<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DerivedAsset extends Model
{
    const TYPE_AUDIO_NORMALIZED = 'audio_normalized';

    protected $fillable = [
        'media_asset_id',
        'type',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'duration_ms',
        'sample_rate',
        'channels',
        'codec',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'sample_rate' => 'integer',
            'channels' => 'integer',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
