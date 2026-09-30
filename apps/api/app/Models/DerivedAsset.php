<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DerivedAsset extends Model
{
    const TYPE_AUDIO_NORMALIZED = 'audio_normalized';
    const TYPE_RENDERED_CLIP = 'clip_rendered';

    // Status constants for clip_rendered type
    const STATUS_PENDING = 'pending';
    const STATUS_RENDERING = 'rendering';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

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
        'status',
        'candidate_index',
        'render_profile_version',
        'render_configuration',
        'render_parameters',
        'render_error',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'sample_rate' => 'integer',
            'channels' => 'integer',
            'candidate_index' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'render_configuration' => 'array',
            'render_parameters' => 'array',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * Get the rendered clips for this media asset.
     */
    public function scopeRenderedClips($query)
    {
        return $query->where('type', self::TYPE_RENDERED_CLIP);
    }

    /**
     * Check if the render attempt is in a terminal state.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * Transition to rendering state.
     */
    public function markRendering(): void
    {
        $this->update([
            'status' => self::STATUS_RENDERING,
        ]);
    }

    /**
     * Mark the render attempt as completed with result metadata.
     *
     * @param  array<string, mixed>  $result
     */
    public function markCompleted(array $result): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'storage_disk' => $result['storage_disk'] ?? $this->storage_disk,
            'storage_key' => $result['storage_key'] ?? $this->storage_key,
            'mime_type' => $result['mime_type'] ?? 'video/mp4',
            'size_bytes' => $result['size_bytes'] ?? 0,
            'duration_ms' => $result['duration_ms'] ?? 0,
            'width' => $result['width'] ?? null,
            'height' => $result['height'] ?? null,
            'codec' => $result['codec'] ?? null,
            'candidate_index' => $result['candidate_index'] ?? $this->candidate_index,
            'render_profile_version' => $result['render_profile_version'] ?? $this->render_profile_version,
            'render_configuration' => $result['render_configuration'] ?? $this->render_configuration,
            'render_parameters' => $result['render_parameters'] ?? $this->render_parameters,
            'render_error' => null,
        ]);
    }

    /**
     * Mark the render attempt as failed with sanitized error code.
     *
     * @param  string  $error  Sanitized error code (invalid_candidate_index, upstream_recommendation_failed, etc.)
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'render_error' => $error,
        ]);
    }
}
