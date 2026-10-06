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
        self::STATUS_FAILED => [self::STATUS_TRANSCRIBING],
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
            'error' => null,
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

    /*
    |--------------------------------------------------------------------------
    | Caption Projection
    |--------------------------------------------------------------------------
    */

    /**
     * Escape caption text for FFmpeg drawtext filter.
     *
     * FFmpeg drawtext requires escaping of special characters:
     * - Backslash: \ -> \\
     * - Colon: : -> \:
     * - Single quote: ' -> \'
     * - Percent: % -> \%
     * - Control characters (ASCII < 32) replaced with space
     * - Text wrapped at max_chars_per_line with \n for multi-line drawtext
     *
     * @param  string  $text  Raw caption text
     * @param  int  $maxCharsPerLine  Maximum characters per line (default 32)
     * @return string  Escaped and wrapped text
     */
    public static function escapeCaptionText(string $text, int $maxCharsPerLine = 32): string
    {
        // Replace control characters (ASCII < 32) with space
        $text = preg_replace('/[\x00-\x1F]/', ' ', $text);

        // Escape special characters for FFmpeg drawtext
        // Order matters: escape backslash first to avoid double-escaping
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(':', '\\:', $text);
        $text = str_replace("'", "\\'", $text);
        $text = str_replace('%', '\\%', $text);
        $text = str_replace('"', '\\"', $text);

        // Word-wrap at max_chars_per_line
        if ($maxCharsPerLine > 0 && strlen($text) > $maxCharsPerLine) {
            $words = explode(' ', $text);
            $lines = [];
            $currentLine = '';

            foreach ($words as $word) {
                // If adding this word exceeds the limit, start a new line
                if ($currentLine !== '' && strlen($currentLine) + 1 + strlen($word) > $maxCharsPerLine) {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                } else {
                    $currentLine .= ($currentLine === '' ? '' : ' ') . $word;
                }
            }

            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }

            $text = implode("\n", $lines);
        }

        return $text;
    }

    /**
     * Project transcript segments to a candidate's local timebase.
     *
     * @param  int  $candidateStartMs  Start of candidate in media timebase
     * @param  int  $candidateEndMs    End of candidate in media timebase
     * @param  int  $maxCharsPerLine   Maximum characters per caption line (default 32)
     * @return array<int, array{start_ms: int, end_ms: int, text: string}>
     */
    public function projectSegmentsToCandidate(int $candidateStartMs, int $candidateEndMs, int $maxCharsPerLine = 32): array
    {
        // Only project if transcript is completed
        if ($this->status !== self::STATUS_COMPLETED) {
            return [];
        }

        $segments = $this->segments ?? [];
        if (empty($segments)) {
            return [];
        }

        $candidateDurationMs = $candidateEndMs - $candidateStartMs;
        $projected = [];

        foreach ($segments as $segment) {
            $segmentStartMs = (int) ($segment['start_ms'] ?? 0);
            $segmentEndMs = (int) ($segment['end_ms'] ?? 0);
            $segmentText = (string) ($segment['text'] ?? '');

            if ($segmentText === '') {
                continue;
            }

            // Map to candidate-local timebase
            $localStartMs = max(0, $segmentStartMs - $candidateStartMs);
            $localEndMs = min($candidateDurationMs, $segmentEndMs - $candidateStartMs);

            // Only include if segment overlaps candidate
            if ($localEndMs > $localStartMs) {
                $projected[] = [
                    'start_ms' => $localStartMs,
                    'end_ms' => $localEndMs,
                    'text' => self::escapeCaptionText($segmentText, $maxCharsPerLine),
                ];
            }
        }

        // Sort by local_start_ms ascending
        usort($projected, fn (array $a, array $b) => $a['start_ms'] <=> $b['start_ms']);

        return $projected;
    }
}
