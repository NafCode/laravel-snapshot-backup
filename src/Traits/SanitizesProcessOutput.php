<?php

namespace SnapshotBackup\Traits;

trait SanitizesProcessOutput
{
    /**
     * Scrub host, username, and port from process output before logging or throwing.
     * Replaces "user@host", "user@host:port", and "ssh://user@host:port/..." with
     * "[remote]" so connection details never appear in logs or notification emails.
     */
    private function sanitizeProcessOutput(string $text, array $ssh): string
    {
        $user = preg_quote($ssh['user'], '/');
        $host = preg_quote($ssh['host'], '/');
        $port = preg_quote((string) $ssh['port'], '/');

        return preg_replace(
            [
                '/ssh:\/\/' . $user . '@' . $host . ':' . $port . '[^\s\'"]*/',
                '/' . $user . '@' . $host . '(:\d+)?/',
            ],
            '[remote]',
            $text,
        );
    }
}
