<?php

namespace App\Models;

use App\Exceptions\ProcessMediaException;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationProjection;
use App\Services\ClipRecommendationReadiness;
use App\Services\ClipRecommendationValidator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable M5 semantic clip recommendation.
 *
 * Exactly one row per media asset. `completed` and `unavailable` are terminal
 * and immutable: their snapshots and timestamps never change again, even if a
 * transcript or the trusted configuration changes later. Only a failed
 * attempt may be retried, and a retry clears only M5 state.
 */
class MediaClipRecommendation extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Status, outcome and reason
    |--------------------------------------------------------------------------
    */

    const STATUS_PENDING = 'pending';

    const STATUS_RANKING = 'ranking';

    const STATUS_COMPLETED = 'completed';

    const STATUS_UNAVAILABLE = 'unavailable';

    const STATUS_FAILED = 'failed';

    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RANKING,
        self::STATUS_COMPLETED,
        self::STATUS_UNAVAILABLE,
        self::STATUS_FAILED,
    ];

    const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_UNAVAILABLE,
    ];

    const OUTCOME_RANKED = 'ranked';

    const OUTCOME_NO_CANDIDATES = 'no_candidates';

    const OUTCOME_UNAVAILABLE = 'unavailable';

    const ERROR_RANKING_FAILED = 'ranking_failed';

    const ERROR_INVALID_INPUT = 'invalid_input';

    const ERROR_UPSTREAM_M4_UNAVAILABLE = 'upstream_m4_unavailable';

    const ERROR_UPSTREAM_NOT_READY = 'upstream_not_ready';

    const ERROR_VERSION_CONFLICT = 'recommendation_version_conflict';

    /**
     * Legal transitions, applied only inside the locked claim transaction.
     *
     * @var array<string, list<string>>
     */
    private const VALID_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_RANKING],
        self::STATUS_RANKING => [self::STATUS_COMPLETED, self::STATUS_UNAVAILABLE, self::STATUS_FAILED],
        self::STATUS_FAILED => [self::STATUS_RANKING],
    ];

    protected $fillable = [
        'media_asset_id',
        'm4_analysis_id',
        'status',
        'outcome',
        'reason',
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
    | Transitions
    |--------------------------------------------------------------------------
    */

    public static function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::VALID_TRANSITIONS[$from] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Transition to the ranking state, clearing only incomplete M5 state.
     */
    public function markRanking(): void
    {
        if (! self::isValidTransition((string) $this->status, self::STATUS_RANKING)) {
            return;
        }

        // A retry of a failed attempt drops its sanitized error and any
        // incomplete result; upstream rows are never touched.
        $this->update([
            'status' => self::STATUS_RANKING,
            'error' => null,
            'outcome' => null,
            'reason' => null,
            'recommendations' => null,
        ]);
    }

    /**
     * Complete a validated worker result or a validated local outcome.
     *
     * The caller supplied snapshot is compared against the shared completion
     * contract before anything is written, so a caller-invented snapshot is
     * rejected with no partial result and no partial status write.
     *
     * @param  array<string, mixed>  $completion
     *
     * @throws ProcessMediaException
     */
    public function markCompleted(string $outcome, array $completion): void
    {
        if ($this->isTerminal()) {
            throw new ProcessMediaException('Invalid status transition to completed');
        }

        if (! self::isValidTransition((string) $this->status, self::STATUS_COMPLETED)) {
            throw new ProcessMediaException('Invalid status transition to completed');
        }

        self::requireCompletionShape($completion, $outcome);
        $this->requireBoundAuthority($completion);
        $this->requireFreshAuthority($completion);
        ClipRecommendationValidator::validateCompletion($completion);

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'outcome' => $outcome,
            'reason' => null,
            'algorithm' => $completion['algorithm'],
            'algorithm_version' => $completion['algorithm_version'],
            'parameters' => $completion['parameters'],
            'recommendations' => $completion['recommendations'],
            'input_snapshot' => $completion['input_snapshot'],
            'execution_parameters' => $completion['execution_parameters'],
            'error' => null,
        ]);
    }

    /**
     * Complete a validated local unavailable outcome.
     *
     * @param  array<string, mixed>  $completion
     *
     * @throws ProcessMediaException
     */
    public function markUnavailable(string $reason, array $completion): void
    {
        if ($this->isTerminal()) {
            throw new ProcessMediaException('Invalid status transition to unavailable');
        }

        if (! self::isValidTransition((string) $this->status, self::STATUS_UNAVAILABLE)) {
            throw new ProcessMediaException('Invalid status transition to unavailable');
        }

        self::requireCompletionShape($completion, self::OUTCOME_UNAVAILABLE);
        $this->requireBoundAuthority($completion);
        $this->requireFreshAuthority($completion);
        ClipRecommendationValidator::validateCompletion($completion);

        $this->update([
            'status' => self::STATUS_UNAVAILABLE,
            'outcome' => self::OUTCOME_UNAVAILABLE,
            'reason' => $reason,
            'algorithm' => $completion['algorithm'],
            'algorithm_version' => $completion['algorithm_version'],
            'parameters' => $completion['parameters'],
            'recommendations' => $completion['recommendations'],
            'input_snapshot' => $completion['input_snapshot'],
            'execution_parameters' => $completion['execution_parameters'],
            'error' => null,
        ]);
    }

    /**
     * Record a sanitized failed attempt. No result is retained.
     *
     * Explicitly reject transitions from terminal statuses to prevent
     * overwriting a committed owner result during lock contention.
     */
    public function markFailed(string $error): void
    {
        if ($this->isTerminal()) {
            return;
        }

        if (! self::isValidTransition((string) $this->status, self::STATUS_FAILED)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_FAILED,
            'outcome' => null,
            'reason' => null,
            'recommendations' => null,
            'error' => $error,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Terminal reuse and version conflicts
    |--------------------------------------------------------------------------
    */

    /**
     * Whether a terminal row records the same semantic selection as the
     * currently intended one.
     *
     * The operational timeout is deliberately excluded: changing it alone
     * never invalidates a terminal result.
     */
    public function matchesSelection(?int $m4AnalysisId, array $configuration): bool
    {
        $semanticKeys = array_values(array_diff(
            ClipRankingProfile::CONFIGURATION_KEYS,
            ['provider', 'algorithm', 'algorithm_version']
        ));

        if ((int) $this->m4_analysis_id !== (int) $m4AnalysisId) {
            return false;
        }

        if ($this->algorithm !== $configuration['algorithm']
            || $this->algorithm_version !== $configuration['algorithm_version']) {
            return false;
        }

        $parameters = (array) $this->parameters;
        foreach ($semanticKeys as $key) {
            if (! array_key_exists($key, $parameters) || $parameters[$key] !== $configuration[$key]) {
                return false;
            }
        }

        if (($parameters['provider_name'] ?? null) !== ClipRankingProfile::providerName($configuration['provider'])) {
            return false;
        }

        return true;
    }

    /**
     * Build the shared completion payload of a local outcome.
     *
     * @param  array<string, mixed>  $configuration
     * @param  list<array{index: int, start_ms: int, end_ms: int, rank: int, score: float|int}>  $m4Candidates
     * @param  list<array{index: int, sha256: string}>  $textHashes
     * @return array<string, mixed>
     */
    public static function localCompletion(
        array $configuration,
        int $m4AnalysisId,
        array $m4Candidates,
        int $durationMs,
        string $transcriptState,
        array $textHashes,
        array $recommendations,
        array $executionParameters,
    ): array {
        return [
            'algorithm' => $configuration['algorithm'],
            'algorithm_version' => $configuration['algorithm_version'],
            'parameters' => ClipRankingProfile::parameters($configuration, false, false),
            'recommendations' => $recommendations,
            'input_snapshot' => [
                'm4_analysis_id' => $m4AnalysisId,
                'm4_algorithm' => ClipRecommendationValidator::M4_ALGORITHM,
                'm4_algorithm_version' => ClipRecommendationValidator::M4_ALGORITHM_VERSION,
                'm4_candidates' => $m4Candidates,
                'duration_ms' => $durationMs,
                'transcript_state' => $transcriptState,
                'projection_version' => $configuration['projection_version'],
                'text_hashes' => $textHashes,
                'request_sha256' => null,
            ],
            'execution_parameters' => $executionParameters,
        ];
    }

    /**
     * A completion may only be recorded against the M4 authority this row is
     * already bound to. A caller-invented analysis id is rejected before any
     * write, so no partial result and no partial status write can survive.
     *
     * @param  array<string, mixed>  $completion
     */
    private function requireBoundAuthority(array $completion): void
    {
        $snapshot = $completion['input_snapshot'] ?? null;

        if (! is_array($snapshot) || ! array_key_exists('m4_analysis_id', $snapshot)) {
            throw new ProcessMediaException('Invalid status transition to completed');
        }

        if ((int) $snapshot['m4_analysis_id'] !== (int) $this->m4_analysis_id) {
            throw new ProcessMediaException('Invalid status transition to completed');
        }
    }

    /**
     * The freshly authoritative M4 candidate snapshot and the text hashes a
     * completion must match, given the transcript state the claim recorded.
     *
     * This is the single trust seam of the completion boundary. The M4 list is
     * always re-read: only the completed row of the expected algorithm and
     * version that belongs to this asset, returned exactly as stored, never
     * revalidated and never mutated.
     *
     * The hashes are re-derived under the recorded classification, because the
     * specification makes the probe and audio-extraction outcomes
     * authoritative over a stale transcript. A `completed_valid` record must
     * reproduce the locally re-projected hashes of the current completed
     * transcript exactly. Any other terminal state projected no text, so every
     * recorded digest must be the exact empty-string digest: a forged non-empty
     * digest can therefore never make an unscored candidate look eligible, in
     * either direction.
     *
     * @return array{m4_candidates: list<array<string, mixed>>, text_hashes: list<array{index: int, sha256: string}>}|null
     */
    protected function freshAuthority(string $recordedState): ?array
    {
        $analysis = $this->m4Analysis;

        if (! $analysis instanceof MediaClipAnalysis
            || (int) $analysis->media_asset_id !== (int) $this->media_asset_id
            || $analysis->status !== MediaClipAnalysis::STATUS_COMPLETED
            || $analysis->algorithm !== ClipRecommendationValidator::M4_ALGORITHM
            || $analysis->algorithm_version !== ClipRecommendationValidator::M4_ALGORITHM_VERSION
            || ! is_array($analysis->candidates)) {
            return null;
        }

        $m4Candidates = $analysis->candidates;

        $windows = array_map(
            static fn (array $candidate): array => [
                'index' => (int) $candidate['index'],
                'start_ms' => (int) $candidate['start_ms'],
                'end_ms' => (int) $candidate['end_ms'],
            ],
            $m4Candidates
        );
        $indexes = array_map(static fn (array $window): int => $window['index'], $windows);

        if ($recordedState !== ClipRecommendationReadiness::COMPLETED_VALID) {
            return [
                'm4_candidates' => $m4Candidates,
                'text_hashes' => ClipRecommendationProjection::emptyTextHashes($indexes),
            ];
        }

        $asset = $this->mediaAsset;
        $durationMs = (int) ($asset?->duration_ms ?? 0);
        $transcript = $asset?->transcript()->first();

        // A completed_valid record must be backed by a genuinely completed and
        // strictly valid transcript, not by a pending, failed or absent row.
        if ($transcript === null || $transcript->status !== 'completed') {
            return null;
        }

        return [
            'm4_candidates' => $m4Candidates,
            'text_hashes' => ClipRecommendationProjection::project(
                $windows,
                ClipRecommendationProjection::validateSegments($transcript->segments, $durationMs),
            )['text_hashes'],
        ];
    }

    /**
     * Reject a caller-invented snapshot.
     *
     * The recorded M4 authority, candidate list and text hashes are compared
     * against freshly authoritative, locally re-projected values, so a forged
     * non-empty text hash cannot be used to make an unscored candidate look
     * eligible. This runs before the shared invariant core and before any
     * write, so rejection leaves no partial result and no partial status write.
     *
     * @param  array<string, mixed>  $completion
     *
     * @throws ProcessMediaException
     */
    private function requireFreshAuthority(array $completion): void
    {
        $rejected = new ProcessMediaException('Invalid status transition to completed');

        $snapshot = $completion['input_snapshot'] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['m4_candidates'] ?? null)
            || ! is_array($snapshot['text_hashes'] ?? null)) {
            throw $rejected;
        }

        $authority = $this->freshAuthority(
            is_string($snapshot['transcript_state'] ?? null) ? $snapshot['transcript_state'] : ''
        );
        if ($authority === null) {
            throw $rejected;
        }

        if ($snapshot['m4_candidates'] !== $authority['m4_candidates']
            || $snapshot['text_hashes'] !== $authority['text_hashes']) {
            throw $rejected;
        }

        $configuration = ClipRecommendationValidator::configurationFromRecordedParameters(
            (array) ($completion['parameters'] ?? []),
            $completion['algorithm'] ?? null,
            $completion['algorithm_version'] ?? null,
        );

        if ($snapshot['projection_version'] !== $configuration['projection_version']) {
            throw $rejected;
        }
    }

    /**
     * @param  array<string, mixed>  $completion
     */
    private static function requireCompletionShape(array $completion, string $outcome): void
    {
        $expected = in_array($outcome, [self::OUTCOME_RANKED, self::OUTCOME_NO_CANDIDATES], true)
            ? self::STATUS_COMPLETED
            : self::STATUS_UNAVAILABLE;

        if ($expected !== self::STATUS_COMPLETED) {
            return;
        }

        $snapshot = $completion['input_snapshot'] ?? null;
        $digest = is_array($snapshot) ? ($snapshot['request_sha256'] ?? null) : null;
        $parameters = (array) ($completion['parameters'] ?? []);

        if ($outcome === self::OUTCOME_NO_CANDIDATES) {
            // Local zero-candidate completion: never a claimed worker success.
            if ($digest !== null || ($parameters['inference_performed'] ?? null) !== false) {
                throw new ProcessMediaException('Invalid local zero-candidate completion');
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    /**
     * The authoritative M4 analysis this recommendation is bound to.
     */
    public function m4Analysis(): BelongsTo
    {
        return $this->belongsTo(MediaClipAnalysis::class, 'm4_analysis_id');
    }
}
