<?php

namespace App\Models;

use Database\Factories\MediaStreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_probe_id', 'stream_index', 'codec_type', 'codec_name', 'profile', 'duration_seconds', 'bit_rate', 'language', 'title', 'is_default', 'is_forced', 'is_attached_picture', 'width', 'height', 'pixel_format', 'average_frame_rate', 'real_frame_rate', 'color_range', 'color_space', 'color_transfer', 'color_primaries', 'sample_rate', 'channels', 'channel_layout'])]
class MediaStream extends Model
{
    /** @use HasFactory<MediaStreamFactory> */
    use HasFactory;

    /** @return BelongsTo<MediaProbe, $this> */
    public function probe(): BelongsTo
    {
        return $this->belongsTo(MediaProbe::class, 'media_probe_id');
    }

    protected function casts(): array
    {
        return [
            'stream_index' => 'integer', 'bit_rate' => 'integer', 'duration_seconds' => 'decimal:6',
            'width' => 'integer', 'height' => 'integer', 'sample_rate' => 'integer', 'channels' => 'integer',
            'is_default' => 'boolean', 'is_forced' => 'boolean', 'is_attached_picture' => 'boolean',
        ];
    }
}
