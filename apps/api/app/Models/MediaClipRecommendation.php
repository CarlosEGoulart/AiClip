<?php

namespace App\Models;

use App\Exceptions\ProcessMediaException;
use App\Services\ClipRecommendationValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaClipRecommendation extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    */

    const STATUS_PENDING = 'pending';

    const STATUS_RANKING = 'ranking';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RANKING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Valid status transitions.
     *
     * @var array<string, list<string>>
     */
    private const VALID_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_RANKING],
        self::STATUS_RANKING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [self::STATUS_RANKING],
    ];

    protected $fillable = [
        'media_asset_id',
        'status',
        'algorithm',
        'algorithm_version',
        'parameters',
        'recommendations',
        'input_snapshot',
        'execution_parameters',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'recommendations' => 'array',
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
     * Transition to the ranking state.
     */
    public function markRanking(): void
    {
        if (! self::isValidTransition($this->status, self::STATUS_RANKING)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_RANKING,
            'error' => null,
        ]);
    }

    /**
     * Transition to the completed state.
     *
     * @throws ProcessMediaException If validation fails
     */
    public function markCompleted(
        string $algorithm,
        string $algorithmVersion,
        array $parameters,
        array $recommendations,
        array $inputSnapshot,
        array $executionParameters,
    ): void {
        if (! self::isValidTransition($this->status, self::STATUS_COMPLETED)) {
            throw new ProcessMediaException('Invalid status transition to completed');
        }

        ClipRecommendationValidator::validateCompletion(
            ['algorithm' => $algorithm, 'algorithm_version' => $algorithmVersion, 'parameters' => $parameters, 'recommendations' => $recommendations],
            $inputSnapshot,
            $executionParameters
        );

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'algorithm' => $algorithm,
            'algorithm_version' => $algorithmVersion,
            'parameters' => $parameters,
            'recommendations' => $recommendations,
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
     * Get the media asset that owns this clip recommendation.
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }
}
