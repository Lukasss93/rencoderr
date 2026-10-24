<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->text('path');
            $table->char('path_hash', 64)->unique();
            $table->string('scan_status', 20)->default('pending');
            $table->uuid('scan_token')->nullable();
            $table->timestamp('scan_requested_at')->nullable();
            $table->timestamp('scan_started_at')->nullable();
            $table->timestamp('scan_finished_at')->nullable();
            $table->timestamp('last_successful_scan_at')->nullable();
            $table->text('scan_error')->nullable();
            $table->timestamps();
            $table->index('library_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_paths');
    }
};
