<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_transcode_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->string('verdict')->nullable();
            $table->string('phase')->nullable();
            $table->uuid('request_token');
            $table->uuid('attempt_token')->nullable();
            $table->uuid('transcode_attempt_token');
            $table->json('policy');
            $table->unsignedInteger('execution_limit_seconds');
            $table->unsignedInteger('threads');
            $table->text('source_path');
            $table->unsignedBigInteger('source_size_bytes');
            $table->text('output_path');
            $table->unsignedBigInteger('output_size_bytes');
            $table->string('model_sha256', 64)->nullable();
            $table->json('expected_contract')->nullable();
            $table->json('results')->nullable();
            $table->json('binary_versions')->nullable();
            $table->double('savings_percent')->nullable();
            $table->double('vmaf_mean')->nullable();
            $table->double('coverage_seconds')->nullable();
            $table->float('progress_percent')->nullable();
            $table->text('diagnostic_tail')->nullable();
            $table->text('error')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('source_modified_at');
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('execution_deadline_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_quality_checks');
    }
};
