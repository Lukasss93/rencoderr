<?php

namespace App\Models;

use Database\Factories\MediaStreamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $codec_type
 * @property string|null $codec_name
 * @property string|null $profile
 * @property string|null $language
 * @property string|null $title
 * @property string|null $pixel_format
 * @property string|null $average_frame_rate
 * @property string|null $real_frame_rate
 * @property string|null $color_range
 * @property string|null $color_space
 * @property string|null $color_transfer
 * @property string|null $color_primaries
 * @property string|null $channel_layout
 * @property int|null $width
 * @property int|null $height
 * @property int|null $sample_rate
 * @property int|null $channels
 * @property bool|null $is_default
 * @property bool|null $is_forced
 * @property bool|null $is_attached_picture
 */
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stream_index' => 'integer', 'bit_rate' => 'integer', 'duration_seconds' => 'decimal:6',
            'width' => 'integer', 'height' => 'integer', 'sample_rate' => 'integer', 'channels' => 'integer',
            'is_default' => 'boolean', 'is_forced' => 'boolean', 'is_attached_picture' => 'boolean',
        ];
    }
}
