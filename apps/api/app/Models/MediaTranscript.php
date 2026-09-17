<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaTranscript extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    */

    const STATUS_PENDING = 'pending';

    const STATUS_TRANSCRIBING = 'transcribing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_TRANSCRIBING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /**
     * Valid status transitions.
     *
     * @var array<string, list<string>>
     */
    private const VALID_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_TRANSCRIBING, self::STATUS_FAILED],
        self::STATUS_TRANSCRIBING => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
    ];

    protected $fillable = [
        'media_asset_id',
        'derived_asset_id',
        'status',
        'language',
        'full_text',
        'segments',
        'engine',
        'model',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'segments' => 'array',
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
     * Transition to the transcribing state.
     */
    public function markTranscribing(): void
    {
        if (! self::isValidTransition($this->status, self::STATUS_TRANSCRIBING)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_TRANSCRIBING,
        ]);
    }

    /**
     * Transition to the completed state.
     */
    public function markCompleted(
        string $language,
        string $fullText,
        array $segments,
        string $engine,
        string $model,
    ): void {
        if (! self::isValidTransition($this->status, self::STATUS_COMPLETED)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'language' => $language,
            'full_text' => $fullText,
            'segments' => $segments,
            'engine' => $engine,
            'model' => $model,
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
     * Get the media asset that owns the transcript.
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * Get the derived asset containing the normalized audio.
     */
    public function derivedAsset(): BelongsTo
    {
        return $this->belongsTo(DerivedAsset::class);
    }
}
