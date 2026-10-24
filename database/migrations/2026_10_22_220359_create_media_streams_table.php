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
            $table->text('codec_type')->nullable();
            $table->text('codec_name')->nullable();
            $table->text('profile')->nullable();
            $table->text('language')->nullable();
            $table->text('title')->nullable();
            $table->text('pixel_format')->nullable();
            $table->text('average_frame_rate')->nullable();
            $table->text('real_frame_rate')->nullable();
            $table->text('color_range')->nullable();
            $table->text('color_space')->nullable();
            $table->text('color_transfer')->nullable();
            $table->text('color_primaries')->nullable();
            $table->text('channel_layout')->nullable();
            $table->decimal('duration_seconds', 20, 6)->nullable();
            $table->unsignedBigInteger('bit_rate')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedInteger('channels')->nullable();
            $table->boolean('is_default')->nullable();
            $table->boolean('is_forced')->nullable();
            $table->boolean('is_attached_picture')->nullable();
            $table->timestamps();
            $table->unique(['media_probe_id', 'stream_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_streams');
    }
};
