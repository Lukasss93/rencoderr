<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_writebacks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_transcode_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('media_quality_check_id')->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->index();
            $table->string('mode');
            $table->string('phase')->nullable();
            $table->uuid('request_token');
            $table->uuid('attempt_token')->nullable();
            $table->text('source_path');
            $table->text('staging_path');
            $table->text('candidate_path');
            $table->text('copy_path')->nullable();
            $table->text('destination_path');
            $table->text('backup_path');
            $table->json('source_fingerprint');
            $table->json('output_fingerprint');
            $table->json('candidate_identity')->nullable();
            $table->json('comparison');
            $table->text('error')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('inventory_refreshed_at')->nullable();
            $table->timestamp('staging_cleaned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_writebacks');
    }
};
