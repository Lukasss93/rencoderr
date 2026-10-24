<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_transcodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transcode_run_id')->constrained();
            $table->foreignId('media_file_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('file_identity_id');
            $table->uuid('request_token');
            $table->uuid('attempt_token')->nullable();
            $table->string('unique_lock_owner')->nullable();
            $table->string('status')->default('pending');
            $table->text('source_path');
            $table->unsignedBigInteger('source_size_bytes');
            $table->unsignedInteger('execution_limit_seconds');
            $table->string('encoder')->nullable();
            $table->json('arguments')->nullable();
            $table->json('stream_decisions')->nullable();
            $table->text('output_path')->nullable();
            $table->unsignedBigInteger('output_size_bytes')->nullable();
            $table->decimal('progress_percent', 6, 2)->nullable();
            $table->decimal('encoded_seconds', 16, 6)->nullable();
            $table->text('diagnostic_tail')->nullable();
            $table->text('error')->nullable();
            $table->string('skip_reason')->nullable();
            $table->timestamp('source_modified_at');
            $table->timestamp('dispatch_intended_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('execution_deadline_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['transcode_run_id', 'media_file_id']);
            $table->index(['transcode_run_id', 'status']);
            $table->index(['status', 'dispatched_at']);
            $table->index(['status', 'execution_deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_transcodes');
    }
};
