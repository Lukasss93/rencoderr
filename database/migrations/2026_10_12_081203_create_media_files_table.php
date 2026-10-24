<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_path_id')->constrained()->cascadeOnDelete();
            $table->text('relative_path');
            $table->char('path_hash', 64);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('filesystem_modified_at');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->uuid('last_seen_scan_token')->nullable();
            $table->string('status', 20)->default('present');
            $table->timestamps();
            $table->unique(['library_path_id', 'path_hash']);
            $table->index(['library_path_id', 'status']);
            $table->index(['library_path_id', 'last_seen_scan_token']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_files');
    }
};
