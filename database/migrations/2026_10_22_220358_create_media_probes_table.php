<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_probes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_file_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->uuid('request_token')->nullable();
            $table->uuid('attempt_token')->nullable();
            $table->unsignedBigInteger('source_size_bytes');
            $table->timestamp('source_modified_at');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('probed_at')->nullable();
            $table->text('error')->nullable();
            $table->string('format')->nullable();
            $table->decimal('duration_seconds', 20, 6)->nullable();
            $table->unsignedBigInteger('bit_rate')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_probes');
    }
};
