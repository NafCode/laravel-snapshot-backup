<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('server_id', 100);
            $table->string('app_name', 100);
            $table->enum('type', ['files', 'database']);
            $table->date('snapshot_date');
            // Time-slot identifier for file snapshots: YYYY-MM-DD_HH0000
            // DB backup records leave this null.
            $table->string('snapshot_slot', 20)->nullable();
            $table->enum('status', ['running', 'success', 'failed']);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->string('rsync_stats')->nullable();
            $table->timestamps();

            $table->index(['server_id', 'app_name', 'snapshot_date']);
            $table->index(['server_id', 'app_name', 'snapshot_slot']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_snapshots');
    }
};
