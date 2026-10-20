<?php

namespace App\Models;

use App\Enums\ImageStatus;
use Database\Factories\ImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    /** @use HasFactory<ImageFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'project_id',
        'prompt',
        'parameters',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'status' => ImageStatus::class,
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * The project the image belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The user that owns the image.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
