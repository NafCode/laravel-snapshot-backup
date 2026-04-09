<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_snapshots', function (Blueprint $table) {
            $table->unsignedInteger('file_count')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('backup_snapshots', function (Blueprint $table) {
            $table->dropColumn('file_count');
        });
    }
};
