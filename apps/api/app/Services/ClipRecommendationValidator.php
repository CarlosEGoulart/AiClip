<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;
use stdClass;

/**
 * Independent PHP trust boundary for the metadata-only rank_clips action.
 * Validates worker output independently, even when test doubles report success.
 */
final class ClipRecommendationValidator
{
    public const MAX_DURATION = 2147483647;

    private const PROTOTYPE_QUERY = 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.';

    private const EXPECTED_ALGORITHM = 'cross_encoder_reranker';

    private const EXPECTED_ALGORITHM_VERSION = '1.0.0';

    private const EXPECTED_MODEL_ID = 'cross-encoder/ms-marco-MiniLM-L-6-v2';

    private const EXPECTED_PROVIDER_NAME = 'cross_encoder_ranking_provider';

    private const EXPECTED_NORMALIZATION = 'sigmoid';

    private const EXPECTED_SCORE_SCALE = 1.0;

    private const EXPECTED_TIE_BREAK = 'm4_rank_then_chronological';

    private const REQUIRED_PARAMETERS = [
        'prototype_query', 'model_id', 'model_revision', 'provider_name',
        'transcript_used', 'normalization', 'score_scale', 'tie_break',
    ];

    /**
     * Validate worker ranking result and return sanitized arrays.
     *
     * @param  array{version: string, action: string, media: array{duration_ms: int}, candidates: array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>, configuration: array{prototype_query: string}}  $request
     * @param  array{duration_ms: int, candidates: array<int, array{index: int, start_ms: int, end_ms: int, rank: int}>, transcript_used: bool, prototype_query: string}  $inputSnapshot
     * @param  array{timeout_seconds: int, lock_wait_seconds: int}  $executionParameters
     * @return array{status: string, ranking: array{algorithm: string, algorithm_version: string, parameters: array, recommendations: array}}
     *
     * @throws ProcessMediaException
     */
    public static function validate(mixed $result, array $request, array $inputSnapshot, array $executionParameters): array
    {
        try {
            // Convert stdClass to array if needed
            $result = self::toArrays($result);

            // Strict envelope shape: only status + ranking, no extras.
            self::require(is_array($result), 'Result must be an array');
            $result = self::fields($result, ['status', 'ranking']);
            self::require(($result['status'] ?? '') === 'success', 'Status must be success');
            self::require(is_array($result['ranking'] ?? null), 'Ranking must be an array');

            // Strict ranking object shape.
            $ranking = self::fields($result['ranking'], ['algorithm', 'algorithm_version', 'parameters', 'recommendations']);

            // Shared ranking-validation core (see validateRanking()).
            self::validateRanking($ranking, $inputSnapshot, $executionParameters);

            return self::toArrays($result);
        } catch (ProcessMediaException) {
            // Sanitize: always use generic message, no previous exception
            throw new ProcessMediaException('Ranking validation failed', 1, '');
        }
    }

    /**
     * Validate worker ranking result (simplified interface for ProcessMediaAction).
     *
     * @param  array{version: string, action: string, media: array{duration_ms: int}, candidates: array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>, configuration: array{prototype_query: string}}  $request
     * @return array{status: string, ranking: array{algorithm: string, algorithm_version: string, parameters: array, recommendations: array}}
     *
     * @throws ProcessMediaException
     */
    public static function result(mixed $result, array $request): array
    {
        // Compute transcript_used from request candidates
        $transcriptUsed = false;
        foreach ($request['candidates'] as $c) {
            if (($c['transcript_text'] ?? '') !== '') {
                $transcriptUsed = true;
                break;
            }
        }

        // Build input snapshot from request
        $inputSnapshot = [
            'duration_ms' => $request['media']['duration_ms'],
            'candidates' => array_map(function ($c) {
                return [
                    'index' => $c['index'],
                    'start_ms' => $c['start_ms'],
                    'end_ms' => $c['end_ms'],
                    'rank' => $c['rank'],
                ];
            }, $request['candidates']),
            'transcript_used' => $transcriptUsed,
            'prototype_query' => $request['configuration']['prototype_query'],
        ];

        // Build execution parameters from config
        $timeoutSeconds = config('media.clip_ranking_timeout_seconds', 60);
        $executionParameters = [
            'timeout_seconds' => $timeoutSeconds,
            'lock_wait_seconds' => $timeoutSeconds + 5,
        ];

        try {
            return self::validate($result, $request, $inputSnapshot, $executionParameters);
        } catch (ProcessMediaException $e) {
            // Sanitize: always use generic message, no previous exception
            throw new ProcessMediaException('Ranking validation failed', 1, '');
        }
    }

    /**
     * Validate request input (preflight).
     *
     * @return array{version: string, action: string, media: array{duration_ms: int}, candidates: array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>, configuration: array{prototype_query: string}}
     *
     * @throws ProcessMediaException
     */
    public static function request(mixed $request): array
    {
        $data = self::fields($request, ['version', 'action', 'media', 'candidates', 'configuration']);

        self::require($data['version'] === '1.0.0' && $data['action'] === 'rank_clips');

        $media = self::fields($data['media'], ['duration_ms']);
        self::integer($media['duration_ms'], 1, self::MAX_DURATION);
        $data['media'] = $media;

        $candidates = $data['candidates'];
        self::require(is_array($candidates) && array_is_list($candidates));
        $data['candidates'] = self::validateCandidates($candidates, $media['duration_ms']);

        $configuration = self::fields($data['configuration'], ['prototype_query']);
        self::require(is_string($configuration['prototype_query']) && $configuration['prototype_query'] !== '');
        self::require($configuration['prototype_query'] === self::PROTOTYPE_QUERY);
        // Reject unknown fields in configuration
        self::require(array_keys($configuration) === ['prototype_query']);
        $data['configuration'] = $configuration;

        return $data;
    }

    /**
     * Validate candidate list structure and values.
     *
     * @param  array<int, mixed>  $candidates
     * @return array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>
     *
     * @throws ProcessMediaException
     */
    private static function validateCandidates(array $candidates, int $durationMs): array
    {
        $k = count($candidates);
        $seenIndices = [];
        $seenRanks = [];

        foreach ($candidates as $i => $candidate) {
            $candidate = self::fields($candidate, ['index', 'start_ms', 'end_ms', 'rank', 'transcript_text']);

            // Strict integer checks (no bool, no float)
            foreach (['index', 'start_ms', 'end_ms', 'rank'] as $field) {
                self::require(is_int($candidate[$field]) && ! is_bool($candidate[$field]), "candidate {$i}.{$field} must be integer");
            }

            self::require(is_string($candidate['transcript_text']), "candidate {$i}.transcript_text must be string");

            $index = $candidate['index'];
            $startMs = $candidate['start_ms'];
            $endMs = $candidate['end_ms'];
            $rank = $candidate['rank'];

            self::require($index === $i, 'candidate index must be sequential 0..K-1');
            self::require(! in_array($index, $seenIndices, true), "duplicate candidate index: {$index}");
            $seenIndices[] = $index;

            self::require($startMs >= 0 && $startMs <= $durationMs, "candidate {$i}.start_ms out of range");
            self::require($endMs > $startMs && $endMs <= $durationMs, "candidate {$i}.end_ms invalid");

            self::require($rank >= 1 && $rank <= $k, "candidate {$i}.rank out of range 1..{$k}");
            self::require(! in_array($rank, $seenRanks, true), "duplicate candidate rank: {$rank}");
            $seenRanks[] = $rank;
        }

        // Verify indices form 0..K-1
        self::require($seenIndices === range(0, $k - 1), 'candidate indices must form 0..K-1');
        // Verify ranks form 1..K
        self::require($seenRanks === range(1, $k), 'candidate ranks must form 1..K');

        return $candidates;
    }

    /**
     * Validate completion data at the model completion boundary.
     *
     * Consumes the same shared validation core as validate(): one
     * implementation of the invariants, both entry points identical by
     * construction, so the completion boundary cannot bypass any ordering,
     * tie-break, precision, type, or rank-position check.
     *
     * @param  array{algorithm: string, algorithm_version: string, parameters: array, recommendations: array}  $ranking
     * @param  array{duration_ms: int, candidates: array, transcript_used: bool, prototype_query: string}  $inputSnapshot
     * @param  array{timeout_seconds: int, lock_wait_seconds: int}  $executionParameters
     *
     * @throws ProcessMediaException
     */
    public static function validateCompletion(array $ranking, array $inputSnapshot, array $executionParameters): void
    {
        $ranking = self::toArrays($ranking);
        self::require(is_array($ranking), 'Ranking must be an array');

        self::validateRanking($ranking, $inputSnapshot, $executionParameters);
    }

    /**
     * Shared ranking-validation core consumed by both entry points: one
     * implementation of the invariants at the worker trust boundary
     * (validate) and the model completion boundary (validateCompletion).
     *
     * Authoritative ordering: descending semantic_score (6-decimal
     * precision); tie-break ascending M4 candidate rank; final defensive
     * tie-break ascending start_ms, then end_ms, then source_scene_index
     * (unreachable while M4 ranks are unique and contiguous, retained for
     * exhaustiveness).
     *
     * @param  array{algorithm: string, algorithm_version: string, parameters: array, recommendations: array}  $ranking
     * @param  array{duration_ms: int, candidates: array, transcript_used: bool, prototype_query: string}  $inputSnapshot
     * @param  array{timeout_seconds: int, lock_wait_seconds: int}  $executionParameters
     */
    private static function validateRanking(array $ranking, array $inputSnapshot, array $executionParameters): void
    {
        // Validate algorithm and version
        self::require(($ranking['algorithm'] ?? '') === self::EXPECTED_ALGORITHM, 'Invalid algorithm');
        self::require(($ranking['algorithm_version'] ?? '') === self::EXPECTED_ALGORITHM_VERSION, 'Invalid algorithm version');

        // Strict parameters object shape (rejects unknown keys).
        self::require(is_array($ranking['parameters'] ?? null), 'Parameters must be an array');
        $params = self::fields($ranking['parameters'], self::REQUIRED_PARAMETERS);

        self::require($params['prototype_query'] === self::PROTOTYPE_QUERY, 'Prototype query mismatch');
        self::require($params['model_id'] === self::EXPECTED_MODEL_ID, 'Model ID mismatch');
        self::require(is_string($params['model_revision']) && $params['model_revision'] !== '', 'Model revision must be non-empty string');
        self::require($params['provider_name'] === self::EXPECTED_PROVIDER_NAME, 'Provider name mismatch');
        self::require($params['normalization'] === self::EXPECTED_NORMALIZATION, 'Normalization mismatch');
        self::require(
            (is_int($params['score_scale']) || is_float($params['score_scale']))
                && ! is_bool($params['score_scale'])
                && abs((float) $params['score_scale'] - self::EXPECTED_SCORE_SCALE) <= 1e-9,
            'Score scale mismatch'
        );
        self::require($params['tie_break'] === self::EXPECTED_TIE_BREAK, 'Tie-break mismatch');
        self::require(is_bool($params['transcript_used']), 'transcript_used must be boolean');
        self::require(
            array_key_exists('transcript_used', $inputSnapshot)
                && is_bool($inputSnapshot['transcript_used'])
                && $params['transcript_used'] === $inputSnapshot['transcript_used'],
            'transcript_used mismatch with input'
        );

        // Shared recommendation-list invariants core. When the empty
        // short-circuit applies, snapshot/execution checks are skipped
        // (unchanged reference behavior).
        if (self::validateRecommendationList($ranking['recommendations'] ?? null, $inputSnapshot)) {
            // Validate input snapshot shape and agreement.
            self::validateInputSnapshot($inputSnapshot, $params);

            // Validate execution parameters.
            self::validateExecutionParameters($executionParameters);
        }
    }

    /**
     * Shared recommendation-list invariants core: strict per-recommendation
     * shapes and types, inclusive [0,1] bounds with 6-decimal precision,
     * m4_candidate_index permutation, combined_rank position+1 contiguity,
     * and the authoritative ordering with tie-breaks.
     *
     * Returns false when the empty short-circuit applies (no candidates must
     * have empty recommendations), true when the full sweep completed.
     *
     * @param  array{duration_ms: int, candidates: array, transcript_used: bool, prototype_query: string}  $inputSnapshot
     */
    private static function validateRecommendationList(mixed $recommendations, array $inputSnapshot): bool
    {
        self::require(is_array($recommendations) && array_is_list($recommendations), 'Recommendations must be a list');
        $k = count($inputSnapshot['candidates'] ?? []);
        self::require(count($recommendations) === $k, 'Recommendation count mismatch');

        if ($k === 0) {
            self::require($recommendations === [], 'Empty candidates must have empty recommendations');

            return false;
        }

        // Validate each recommendation structure and global invariants.
        $seenIndices = [];
        $seenRanks = [];
        $prevScore = null;
        $prevM4Index = null;

        foreach ($recommendations as $index => $rec) {
            // Strict object shape: rejects start_ms/end_ms/unknown keys.
            self::require(is_array($rec) && ! array_is_list($rec), "Recommendation {$index} must be an object");
            $rec = self::fields($rec, ['m4_candidate_index', 'semantic_score', 'combined_rank']);

            // Strict type checks: integer fields take no bool, no float, no
            // numeric string; the score field takes int|float only.
            self::require(
                is_int($rec['m4_candidate_index']) && ! is_bool($rec['m4_candidate_index']),
                "Recommendation {$index}.m4_candidate_index must be integer"
            );
            self::require(
                (is_int($rec['semantic_score']) || is_float($rec['semantic_score']))
                    && ! is_bool($rec['semantic_score'])
                    && is_finite((float) $rec['semantic_score']),
                "Recommendation {$index}.semantic_score must be finite number"
            );
            self::require(
                is_int($rec['combined_rank']) && ! is_bool($rec['combined_rank']),
                "Recommendation {$index}.combined_rank must be integer"
            );

            $m4Index = $rec['m4_candidate_index'];
            $score = (float) $rec['semantic_score'];
            $combinedRank = $rec['combined_rank'];

            // Score bounds (inclusive [0,1]) and 6-decimal precision.
            self::require($score >= 0 && $score <= 1, "Recommendation {$index}.semantic_score out of range [0,1]");
            self::require(
                abs($score - round($score, 6)) <= 1e-9,
                "Recommendation {$index}.semantic_score exceeds 6-decimal precision"
            );

            // m4_candidate_index range + uniqueness.
            self::require($m4Index >= 0 && $m4Index < $k, "Recommendation {$index}.m4_candidate_index out of range");
            self::require(! in_array($m4Index, $seenIndices, true), "Duplicate m4_candidate_index: {$m4Index}");
            $seenIndices[] = $m4Index;

            // combined_rank must equal position+1 (1..K contiguous in order).
            self::require(
                $combinedRank === $index + 1,
                "Recommendation {$index}.combined_rank must equal position+1"
            );
            self::require($combinedRank >= 1 && $combinedRank <= $k, "Recommendation {$index}.combined_rank out of range 1..{$k}");
            self::require(! in_array($combinedRank, $seenRanks, true), "Duplicate combined_rank: {$combinedRank}");
            $seenRanks[] = $combinedRank;

            // Ordering: descending semantic_score.
            if ($prevScore !== null) {
                self::require(
                    $score <= $prevScore + 1e-9,
                    "Recommendations not sorted by descending semantic_score at index {$index}"
                );

                // Tie-break: if scores equal (within 1e-9), M4 rank must be
                // ascending.
                if (abs($score - $prevScore) <= 1e-9 && $prevM4Index !== null) {
                    $prevCandidate = $inputSnapshot['candidates'][$prevM4Index] ?? null;
                    $currCandidate = $inputSnapshot['candidates'][$m4Index] ?? null;
                    if (is_array($prevCandidate) && is_array($currCandidate)) {
                        $prevRank = $prevCandidate['rank'] ?? 0;
                        $currRank = $currCandidate['rank'] ?? 0;
                        self::require(
                            $prevRank <= $currRank,
                            "Tie-break by M4 rank violated at index {$index}"
                        );

                        if ($prevRank === $currRank) {
                            // Defensive level-3 tie-break (unreachable while
                            // M4 ranks are unique and contiguous, retained
                            // for exhaustiveness): ascending start_ms, then
                            // end_ms, then source_scene_index.
                            $prevStart = self::candidateTiming($prevCandidate, 'start_ms');
                            $currStart = self::candidateTiming($currCandidate, 'start_ms');
                            self::require(
                                $prevStart <= $currStart,
                                "Tie-break by start_ms violated at index {$index}"
                            );

                            if ($prevStart === $currStart) {
                                $prevEnd = self::candidateTiming($prevCandidate, 'end_ms');
                                $currEnd = self::candidateTiming($currCandidate, 'end_ms');
                                self::require(
                                    $prevEnd <= $currEnd,
                                    "Tie-break by end_ms violated at index {$index}"
                                );

                                if ($prevEnd === $currEnd) {
                                    self::require(
                                        self::sourceSceneIndex($prevCandidate) <= self::sourceSceneIndex($currCandidate),
                                        "Tie-break by source_scene_index violated at index {$index}"
                                    );
                                }
                            }
                        }
                    }
                }
            }

            $prevScore = $score;
            $prevM4Index = $m4Index;
        }

        // Verify m4_candidate_index forms permutation of 0..K-1.
        sort($seenIndices);
        self::require($seenIndices === range(0, $k - 1), 'm4_candidate_index values must form permutation of 0..K-1');

        // Verify combined_rank forms 1..K.
        self::require($seenRanks === range(1, $k), 'combined_rank values must form permutation of 1..K');

        return true;
    }

    /**
     * Validate input snapshot shape and agreement.
     *
     * @param  array{duration_ms: int, candidates: array, transcript_used: bool, prototype_query: string}  $inputSnapshot
     */
    private static function validateInputSnapshot(array $inputSnapshot, array $params): void
    {
        self::require(
            isset($inputSnapshot['duration_ms'], $inputSnapshot['candidates'], $inputSnapshot['transcript_used'], $inputSnapshot['prototype_query']),
            'Invalid input snapshot structure'
        );
        self::require($inputSnapshot['prototype_query'] === self::PROTOTYPE_QUERY, 'Input snapshot prototype_query mismatch');
        self::require(
            is_bool($inputSnapshot['transcript_used']) && $inputSnapshot['transcript_used'] === ($params['transcript_used'] ?? false),
            'Input snapshot transcript_used mismatch'
        );
    }

    /**
     * Validate execution parameters.
     *
     * @param  array{timeout_seconds: int, lock_wait_seconds: int}  $executionParameters
     */
    private static function validateExecutionParameters(array $executionParameters): void
    {
        self::require(
            isset($executionParameters['timeout_seconds'], $executionParameters['lock_wait_seconds']),
            'Invalid execution parameters structure'
        );
        self::require(
            is_int($executionParameters['timeout_seconds'])
                && ! is_bool($executionParameters['timeout_seconds'])
                && $executionParameters['timeout_seconds'] >= 1
                && $executionParameters['timeout_seconds'] <= 120,
            'Invalid timeout_seconds'
        );
        $expectedLockWait = $executionParameters['timeout_seconds'] + 5;
        self::require(
            is_int($executionParameters['lock_wait_seconds'])
                && ! is_bool($executionParameters['lock_wait_seconds'])
                && $executionParameters['lock_wait_seconds'] === $expectedLockWait,
            'Invalid lock_wait_seconds'
        );
    }

    /**
     * Defensive tie-break timing value: the candidate's timing field when
     * present, 0 otherwise.
     */
    private static function candidateTiming(array $candidate, string $field): int
    {
        return (int) ($candidate[$field] ?? 0);
    }

    /**
     * Defensive tie-break source scene value: the first entry of the
     * candidate's source_scene_indexes when present, 0 otherwise.
     */
    private static function sourceSceneIndex(array $candidate): int
    {
        $indexes = $candidate['source_scene_indexes'] ?? null;

        return is_array($indexes) && $indexes !== [] ? (int) $indexes[0] : 0;
    }

    private static function toArrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::toArrays(...), $value) : $value;
    }

    private static function fields(mixed $value, array $required, array $optional = []): array
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }
        self::require(is_array($value) && ! array_is_list($value));
        self::require(array_diff($required, array_keys($value)) === []
            && array_diff(array_keys($value), [...$required, ...$optional]) === []);

        return $value;
    }

    private static function integer(mixed $value, int $low, int $high): void
    {
        self::require(is_int($value) && ! is_bool($value) && $value >= $low && $value <= $high);
    }

    private static function require(bool $condition, string $message = 'Validation failed'): void
    {
        if (! $condition) {
            throw new ProcessMediaException($message);
        }
    }
}
