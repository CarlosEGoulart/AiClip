<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaSceneAnalysis extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    */

    const STATUS_PENDING = 'pending';

    const STATUS_DETECTING = 'detecting';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_DETECTING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Valid status transitions.
     *
     * @var array<string, list<string>>
     */
    private const VALID_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_DETECTING],
        self::STATUS_DETECTING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [self::STATUS_DETECTING],
    ];

    protected $fillable = [
        'media_asset_id',
        'status',
        'detector',
        'detector_version',
        'parameters',
        'scenes',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'scenes' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Status Transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Validate whether a status transition is allowed.
     */
    public static function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::VALID_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Transition to the detecting state.
     */
    public function markDetecting(): void
    {
        throw new \RuntimeException('markDetecting not yet implemented');
    }

    /**
     * Transition to the completed state.
     */
    public function markCompleted(
        string $detector,
        string $detectorVersion,
        array $parameters,
        array $scenes,
    ): void {
        throw new \RuntimeException('markCompleted not yet implemented');
    }

    /**
     * Transition to the failed state.
     */
    public function markFailed(string $error): void
    {
        throw new \RuntimeException('markFailed not yet implemented');
    }

    /**
     * Validate scenes array structure.
     *
     * @param  array<int, array{index: int, start_ms: int, end_ms: int}>  $scenes
     */
    public static function validateScenes(array $scenes): void
    {
        throw new \RuntimeException('validateScenes not yet implemented');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the media asset that owns this scene analysis.
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
