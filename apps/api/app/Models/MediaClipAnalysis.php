<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaClipAnalysis extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    */

    const STATUS_PENDING = 'pending';

    const STATUS_ANALYZING = 'analyzing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ANALYZING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Valid status transitions.
     *
     * @var array<string, list<string>>
     */
    private const VALID_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_ANALYZING],
        self::STATUS_ANALYZING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [self::STATUS_ANALYZING],
    ];

    protected $fillable = [
        'media_asset_id',
        'status',
        'algorithm',
        'algorithm_version',
        'parameters',
        'candidates',
        'input_snapshot',
        'execution_parameters',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'candidates' => 'array',
            'input_snapshot' => 'array',
            'execution_parameters' => 'array',
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
     * Transition to the analyzing state.
     */
    public function markAnalyzing(): void
    {
        if (! self::isValidTransition($this->status, self::STATUS_ANALYZING)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_ANALYZING,
            'error' => null,
        ]);
    }

    /**
     * Transition to the completed state.
     */
    public function markCompleted(
        string $algorithm,
        string $algorithmVersion,
        array $parameters,
        array $candidates,
        array $inputSnapshot,
        array $executionParameters,
    ): void {
        if (! self::isValidTransition($this->status, self::STATUS_COMPLETED)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'algorithm' => $algorithm,
            'algorithm_version' => $algorithmVersion,
            'parameters' => $parameters,
            'candidates' => $candidates,
            'input_snapshot' => $inputSnapshot,
            'execution_parameters' => $executionParameters,
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

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the media asset that owns this clip analysis.
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
