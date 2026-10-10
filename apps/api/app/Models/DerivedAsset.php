<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DerivedAsset extends Model
{
    const TYPE_AUDIO_NORMALIZED = 'audio_normalized';

    const TYPE_RENDERED_CLIP = 'clip_rendered';

    const RENDER_STATUS_PENDING = 'pending';

    const RENDER_STATUS_RENDERING = 'rendering';

    const RENDER_STATUS_COMPLETED = 'completed';

    const RENDER_STATUS_FAILED = 'failed';

    private const RENDER_TRANSITIONS = [
        self::RENDER_STATUS_PENDING => [self::RENDER_STATUS_RENDERING],
        self::RENDER_STATUS_RENDERING => [
            self::RENDER_STATUS_COMPLETED,
            self::RENDER_STATUS_FAILED,
        ],
        self::RENDER_STATUS_FAILED => [self::RENDER_STATUS_RENDERING],
        self::RENDER_STATUS_COMPLETED => [],
    ];

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
        'width',
        'height',
        'candidate_index',
        'render_profile_version',
        'render_status',
        'render_configuration',
        'render_parameters',
        'render_error',
        'render_started_at',
        'render_completed_at',
        'transcript_hash',
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
            'render_started_at' => 'datetime',
            'render_completed_at' => 'datetime',
            'transcript_hash' => 'string',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function scopeRenderedClips($query)
    {
        return $query->where('type', self::TYPE_RENDERED_CLIP);
    }

    public static function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::RENDER_TRANSITIONS[$from] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return $this->render_status === self::RENDER_STATUS_COMPLETED;
    }

    public function markRenderPending(): void
    {
        if (! self::isValidTransition(
            (string) $this->render_status,
            self::RENDER_STATUS_PENDING,
        )) {
            throw new \InvalidArgumentException(
                "Invalid transition from {$this->render_status} to ".self::RENDER_STATUS_PENDING
            );
        }

        $this->update([
            'render_status' => self::RENDER_STATUS_PENDING,
            'render_error' => null,
            'render_started_at' => null,
            'render_completed_at' => null,
        ]);
    }

    public function markRendering(): void
    {
        if (! self::isValidTransition(
            (string) $this->render_status,
            self::RENDER_STATUS_RENDERING,
        )) {
            throw new \InvalidArgumentException(
                "Invalid transition from {$this->render_status} to ".self::RENDER_STATUS_RENDERING
            );
        }

        $this->update([
            'render_status' => self::RENDER_STATUS_RENDERING,
            'render_error' => null,
            'render_started_at' => now(),
            'render_completed_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function markRenderCompleted(array $result): void
    {
        if (! self::isValidTransition(
            (string) $this->render_status,
            self::RENDER_STATUS_COMPLETED,
        )) {
            throw new \InvalidArgumentException(
                "Invalid transition from {$this->render_status} to ".self::RENDER_STATUS_COMPLETED
            );
        }

        $this->update([
            'render_status' => self::RENDER_STATUS_COMPLETED,
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
            'render_completed_at' => now(),
        ]);
    }

    public function markRenderFailed(string $error): void
    {
        if (! self::isValidTransition(
            (string) $this->render_status,
            self::RENDER_STATUS_FAILED,
        )) {
            throw new \InvalidArgumentException(
                "Invalid transition from {$this->render_status} to ".self::RENDER_STATUS_FAILED
            );
        }

        $this->update([
            'render_status' => self::RENDER_STATUS_FAILED,
            'render_error' => $error,
            'render_completed_at' => now(),
        ]);
    }

    /**
     * Retry a failed render: transition back to rendering, clearing error and completion timestamp.
     */
    public function retryRender(): void
    {
        if (! self::isValidTransition(
            (string) $this->render_status,
            self::RENDER_STATUS_RENDERING,
        )) {
            throw new \InvalidArgumentException(
                "Cannot retry from status {$this->render_status}. Only failed renders can be retried."
            );
        }

        $this->update([
            'render_status' => self::RENDER_STATUS_RENDERING,
            'render_error' => null,
            'render_started_at' => now(),
            'render_completed_at' => null,
        ]);
    }
}
