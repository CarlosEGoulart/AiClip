<?php

namespace App\Models;

use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;

    /*
    |--------------------------------------------------------------------------
    | Processing State Constants
    |--------------------------------------------------------------------------
    */

    const PROCESSING_STORED = 'stored';

    const PROCESSING_QUEUED = 'queued';

    const PROCESSING_RUNNING = 'processing';

    const PROCESSING_PROBED = 'probed';

    const PROCESSING_COMPLETED = 'completed';

    const PROCESSING_FAILED = 'failed';

    const VALID_PROCESSING_STATES = [
        self::PROCESSING_STORED,
        self::PROCESSING_QUEUED,
        self::PROCESSING_RUNNING,
        self::PROCESSING_PROBED,
        self::PROCESSING_COMPLETED,
        self::PROCESSING_FAILED,
    ];

    /**
     * Valid processing state transitions.
     *
     * @var array<string, list<string>>
     */
    private const VALID_TRANSITIONS = [
        self::PROCESSING_STORED => [self::PROCESSING_QUEUED, self::PROCESSING_FAILED],
        self::PROCESSING_QUEUED => [self::PROCESSING_RUNNING, self::PROCESSING_FAILED],
        self::PROCESSING_RUNNING => [self::PROCESSING_PROBED, self::PROCESSING_FAILED],
        self::PROCESSING_PROBED => [self::PROCESSING_COMPLETED, self::PROCESSING_FAILED],
        self::PROCESSING_COMPLETED => [],
        self::PROCESSING_FAILED => [],
    ];

    protected $fillable = [
        'project_id',
        'original_name',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'status',
        'processing_status',
        'idempotency_key',
        'processing_started_at',
        'processing_completed_at',
        'processing_error',
        'probe_result',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'duration_ms' => 'integer',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
            'probe_result' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Processing State Transitions
    |--------------------------------------------------------------------------
    */

    /**
     * Validate whether a state transition is allowed.
     */
    public static function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::VALID_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Transition to the queued state.
     */
    public function markQueued(string $idempotencyKey): void
    {
        if (! self::isValidTransition($this->processing_status, self::PROCESSING_QUEUED)) {
            return;
        }

        $this->update([
            'processing_status' => self::PROCESSING_QUEUED,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * Transition to the processing state.
     */
    public function markProcessing(): void
    {
        if (! self::isValidTransition($this->processing_status, self::PROCESSING_RUNNING)) {
            return;
        }

        $this->update([
            'processing_status' => self::PROCESSING_RUNNING,
            'processing_started_at' => now(),
        ]);
    }

    /**
     * Transition to the probed state.
     */
    public function markProbed(array $probeResult, int $durationMs): void
    {
        if (! self::isValidTransition($this->processing_status, self::PROCESSING_PROBED)) {
            return;
        }

        $this->update([
            'processing_status' => self::PROCESSING_PROBED,
            'probe_result' => $probeResult,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Transition to the completed state.
     */
    public function markCompleted(): void
    {
        if (! self::isValidTransition($this->processing_status, self::PROCESSING_COMPLETED)) {
            return;
        }

        $this->update([
            'processing_status' => self::PROCESSING_COMPLETED,
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Transition to the failed state.
     */
    public function markFailed(string $error): void
    {
        if (! self::isValidTransition($this->processing_status, self::PROCESSING_FAILED)) {
            return;
        }

        $this->update([
            'processing_status' => self::PROCESSING_FAILED,
            'processing_error' => $error,
        ]);
    }

    /**
     * Get the project that owns the media asset.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Get the derived assets for this media asset.
     */
    public function derivedAssets(): HasMany
    {
        return $this->hasMany(DerivedAsset::class);
    }
}
