<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_streams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_probe_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stream_index');
            foreach (['codec_type', 'codec_name', 'profile', 'language', 'title', 'pixel_format', 'average_frame_rate', 'real_frame_rate', 'color_range', 'color_space', 'color_transfer', 'color_primaries', 'channel_layout'] as $column) {
                $table->text($column)->nullable();
            }
            $table->decimal('duration_seconds', 20, 6)->nullable();
            $table->unsignedBigInteger('bit_rate')->nullable();
            foreach (['width', 'height', 'sample_rate', 'channels'] as $column) {
                $table->unsignedInteger($column)->nullable();
            }
            foreach (['is_default', 'is_forced', 'is_attached_picture'] as $column) {
                $table->boolean($column)->nullable();
            }
            $table->timestamps();
            $table->unique(['media_probe_id', 'stream_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_streams');
    }
};
