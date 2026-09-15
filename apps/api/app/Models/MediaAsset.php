<?php

namespace App\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'original_name',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * Get the project that owns the media asset.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
