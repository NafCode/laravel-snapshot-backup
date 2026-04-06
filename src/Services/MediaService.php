<?php

namespace SnapshotBackup\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaService
{
    protected static array $sanitize_array_name = ['(', ')', ';', '-', '&', '#', '/', '\\', ' ', '+', '*'];

    /**
     * Typed wrapper around Storage::disk() — ensures IDE resolves FilesystemAdapter
     * methods like directoryExists() correctly.
     */
    public static function disk(string $name): \Illuminate\Filesystem\FilesystemAdapter
    {
        return Storage::disk($name);
    }

    public static function store($model, $media_path, $collection = '', $preservingOriginal = false)
    {
        if (is_null($model) || !File::exists($media_path)) {
            return null;
        }

        return $model->addMedia($media_path)
            ->sanitizingFileName(fn ($fileName) => self::sanitizingFileName($fileName))
            ->preservingOriginal($preservingOriginal)
            ->toMediaCollection($collection);
    }

    public static function storeFromDisk($model, $media_path, $collection = '', $preservingOriginal = false, $disk = 'public')
    {
        return $model->addMediaFromDisk($media_path, $disk)
            ->sanitizingFileName(fn ($fileName) => self::sanitizingFileName($fileName))
            ->preservingOriginal($preservingOriginal)
            ->toMediaCollection($collection);
    }

    public static function storeFromUrl($model, $media_path, $collection = '', $preservingOriginal = false)
    {
        return $model->addMediaFromUrl($media_path)
            ->sanitizingFileName(fn ($fileName) => self::sanitizingFileName($fileName))
            ->preservingOriginal($preservingOriginal)
            ->toMediaCollection($collection);
    }

    public static function storeUsingName($model, $media_path, $collection = '', $preservingOriginal = false, $name = '')
    {
        if (is_null($model) || !File::exists($media_path)) {
            return null;
        }

        return $model->addMedia($media_path)
            ->sanitizingFileName(fn ($fileName) => self::sanitizingFileName($fileName))
            ->preservingOriginal($preservingOriginal)
            ->usingName($name)
            ->usingFileName(basename($media_path))
            ->toMediaCollection($collection);
    }

    /**
     * Store a media file from base64 encoded data (e.g. data:image/png;base64,...).
     */
    public static function storeFromBase64($model, string $base64Data, string $collection = '', ?string $fileName = null)
    {
        if (is_null($model) || empty($base64Data)) {
            return null;
        }

        if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $matches)) {
            $extension  = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
            $base64Data = substr($base64Data, strpos($base64Data, ',') + 1);
        } else {
            $extension = 'png';
        }

        $decodedData = base64_decode($base64Data);
        if ($decodedData === false) {
            return null;
        }

        $tempDir = storage_path('app/public/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $tempPath = $tempDir . '/' . ($fileName ?? uniqid('media_')) . '.' . $extension;

        if (file_put_contents($tempPath, $decodedData) === false) {
            return null;
        }

        return $model->addMedia($tempPath)
            ->sanitizingFileName(fn ($name) => self::sanitizingFileName($name))
            ->toMediaCollection($collection);
    }

    public static function storeFromRequest($model, $name, $collection = '', $preservingOriginal = false)
    {
        if (is_null($model)) {
            return null;
        }

        return $model->addMediaFromRequest($name)
            ->sanitizingFileName(function () use ($name) {
                $file      = request()->file($name);
                $extension = $file->getClientOriginalExtension();
                return self::sanitizingFileName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $extension;
            })
            ->preservingOriginal($preservingOriginal)
            ->toMediaCollection($collection);
    }

    public static function getFirstUrl($model, $collection = '', $conversion = '', $expire = 5)
    {
        if (is_null($model)) {
            return null;
        }

        $media = $model->getFirstMedia($collection);

        if (isset($media->disk) && $media->disk === 's3') {
            return $model->getFirstTemporaryUrl(now()->addMinutes($expire), $collection, $conversion);
        }

        return $model->getFirstMediaUrl($collection, $conversion);
    }

    public static function getUrl(Media $media, string $conversion = '', int $expire = 5): ?string
    {
        if (isset($media->disk) && $media->disk === 's3') {
            return $media->getTemporaryUrl(now()->addMinutes($expire), $conversion);
        }

        if ($conversion && $media->hasGeneratedConversion($conversion)) {
            return $media->getFullUrl($conversion);
        }

        return $media->getFullUrl();
    }

    public static function clearCollection($model, string $collection): void
    {
        $model->clearMediaCollection($collection);
    }

    public static function updateFeaturedImage($model, string $name, string $collection)
    {
        if (!request()->hasFile($name)) {
            return null;
        }

        self::clearCollection($model, $name);
        return self::storeFromRequest($model, $name, $collection);
    }

    /**
     * Stream a media file to the browser (works with local / public / S3 / remote).
     *
     * @param  string  $disposition  "inline" for preview, "attachment" for download
     */
    public static function streamMedia(Media $media, string $disposition = 'attachment'): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $disk = Storage::disk($media->disk);
        $path = str_replace('\\', '/', $media->getPathRelativeToRoot());

        if (!$disk->exists($path)) {
            throw new \RuntimeException("File not found on disk [{$media->disk}] at {$path}");
        }

        $stream = $disk->readStream($path);

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type'        => $media->mime_type,
            'Content-Disposition' => $disposition . '; filename="' . $media->name . '.' . pathinfo($media->file_name, PATHINFO_EXTENSION) . '"',
        ]);
    }

    public static function attachMediaCopy($model, string $relativePath, string $collection = 'files', bool $preserveOriginal = false, ?string $name = null): bool|null
    {
        $originalPath = storage_path('app/public/' . $relativePath);

        if (!file_exists($originalPath)) {
            return null;
        }

        $tempDir = storage_path('app/public/temp');
        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $copiedPath = $tempDir . '/' . basename($relativePath);

        if (!copy($originalPath, $copiedPath)) {
            return false;
        }

        self::storeUsingName($model, $copiedPath, $collection, $preserveOriginal, $name);
        return true;
    }

    protected static function sanitizingFileName(string $fileName = ''): string
    {
        $fileName = str_replace(
            ['\\', '/', ':', '*', '?', '"', '<', '>', '|', '#'],
            '-',
            $fileName
        );

        $fileName = preg_replace('/\.(?=.*\.)/', '-', $fileName);

        return trim($fileName);
    }
}
