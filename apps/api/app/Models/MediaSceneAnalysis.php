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
        if (! self::isValidTransition($this->status, self::STATUS_DETECTING)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_DETECTING,
            'error' => null,
        ]);
    }

    /**
     * Transition to the completed state.
     */
    public function markCompleted(
        string $detector,
        string $detectorVersion,
        array $parameters,
        array $scenes,
        int $durationMs,
    ): void {
        if (! self::isValidTransition($this->status, self::STATUS_COMPLETED)) {
            return;
        }

        self::validateScenes($scenes, $durationMs);

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'detector' => $detector,
            'detector_version' => $detectorVersion,
            'parameters' => $parameters,
            'scenes' => $scenes,
            'error' => null,
        ]);
    }

    /**
     * Transition to the failed state.
     */
    public function markFailed(string $error): void
    {
        if (! self::isValidTransition($this->status, self::STATUS_FAILED)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    /**
     * Validate scenes array structure.
     *
     * @param  array<int, array{index: int, start_ms: int, end_ms: int}>  $scenes
     */
    public static function validateScenes(array $scenes, int $durationMs): void
    {
        if ($durationMs <= 0) {
            throw new \InvalidArgumentException("Duration must be > 0, got {$durationMs}");
        }

        if (empty($scenes)) {
            return;
        }

        $seenIndexes = [];

        foreach ($scenes as $i => $scene) {
            // Validate required keys
            if (! isset($scene['index'], $scene['start_ms'], $scene['end_ms'])) {
                throw new \InvalidArgumentException("Scene {$i} is missing required keys (index, start_ms, end_ms)");
            }

            $index = $scene['index'];
            $startMs = $scene['start_ms'];
            $endMs = $scene['end_ms'];

            // Validate types
            if (! is_int($index)) {
                throw new \InvalidArgumentException("Scene {$i} index must be an integer");
            }
            if (! is_int($startMs)) {
                throw new \InvalidArgumentException("Scene {$i} start_ms must be an integer");
            }
            if (! is_int($endMs)) {
                throw new \InvalidArgumentException("Scene {$i} end_ms must be an integer");
            }

            // Validate start_ms >= 0
            if ($startMs < 0) {
                throw new \InvalidArgumentException("Scene {$i} start_ms must be >= 0, got {$startMs}");
            }

            // Validate end_ms > start_ms
            if ($endMs <= $startMs) {
                throw new \InvalidArgumentException("Scene {$i} end_ms ({$endMs}) must be > start_ms ({$startMs})");
            }

            // Validate ordering
            if ($i > 0 && $startMs < $scenes[$i - 1]['start_ms']) {
                throw new \InvalidArgumentException(
                    "Scenes not ordered: scene {$i} start_ms={$startMs} < scene ".($i - 1)." start_ms={$scenes[$i - 1]['start_ms']}"
                );
            }

            // Validate no overlap
            if ($i > 0 && $startMs < $scenes[$i - 1]['end_ms']) {
                throw new \InvalidArgumentException(
                    "Scenes overlap: scene {$i} start_ms={$startMs} < scene ".($i - 1)." end_ms={$scenes[$i - 1]['end_ms']}"
                );
            }

            // Validate duplicate indexes
            if (in_array($index, $seenIndexes, true)) {
                throw new \InvalidArgumentException("Duplicate scene index: {$index}");
            }
            $seenIndexes[] = $index;
        }

        // Validate sequential 0-based indexes
        foreach ($scenes as $i => $scene) {
            if ($scene['index'] !== $i) {
                throw new \InvalidArgumentException(
                    'Scenes must have sequential 0-based indexes: '
                    ."scene at position {$i} has index {$scene['index']}, expected {$i}"
                );
            }
        }

        // Validate duration bounds
        if ($durationMs <= 0) {
            throw new \InvalidArgumentException("Duration must be > 0, got {$durationMs}");
        }

        foreach ($scenes as $i => $scene) {
            if (! isset($scene['end_ms']) || $scene['end_ms'] > $durationMs) {
                throw new \InvalidArgumentException(
                    "Scene {$i} end_ms exceeds media duration ({$durationMs}ms)"
                );
            }
        }
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
