<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;
use stdClass;

/**
 * Independent PHP trust boundary for the metadata-only rank_clips action.
 *
 * The same invariant core runs at the worker trust boundary (result) and at
 * the model completion boundary (validateCompletion), so persistence cannot
 * bypass any envelope, provenance, reference, precision, ordering, rank or
 * snapshot check. Laravel never recomputes or golden-pins neural scores: a
 * structurally valid alternative in-range score is the documented boundary.
 */
final class ClipRecommendationValidator
{
    public const MAX_DURATION_MS = ClipRankingProfile::MAX_DURATION_MS;

    public const MAX_CANDIDATES = ClipRankingProfile::MAX_CANDIDATES;

    /**
     * The only M4 authority M5 may bind to. M5 never reinterprets, rescales or
     * revalidates M4 output; it only records and re-checks this identity.
     */
    public const M4_ALGORITHM = 'scene_timing_baseline';

    public const M4_ALGORITHM_VERSION = '1.0.0';

    public const CONTRACT_VERSION = '1.0.0';

    public const ACTION = 'rank_clips';

    public const UNSCORED_REASON = 'no_candidate_text';

    public const SCORE_UNITS = 1000000;

    public const TRANSCRIPT_STATES = [
        'completed_valid',
        'completed_empty',
        'no_audio',
        'extraction_failed',
        'transcription_failed',
        'missing',
        'no_candidate_text',
    ];

    public const UNAVAILABLE_REASONS = [
        'completed_empty',
        'no_audio',
        'extraction_failed',
        'transcription_failed',
        'missing',
        'no_candidate_text',
    ];

    /**
     * The exact request key set.
     *
     * @var list<string>
     */
    private const REQUEST_KEYS = ['version', 'action', 'media', 'candidates', 'configuration'];

    /**
     * The exact candidate key set of a rank_clips request.
     *
     * @var list<string>
     */
    private const REQUEST_CANDIDATE_KEYS = [
        'index', 'start_ms', 'end_ms', 'm4_rank', 'm4_score', 'transcript_text',
    ];

    /**
     * The exact recommendation key set of a worker result or local outcome.
     *
     * @var list<string>
     */
    private const RECOMMENDATION_KEYS = [
        'm4_candidate_index', 'start_ms', 'end_ms', 'm4_rank', 'm4_score',
        'semantic_score', 'semantic_rank', 'reason',
    ];

    /**
     * The exact input snapshot key set of a durable M5 record.
     *
     * @var list<string>
     */
    private const SNAPSHOT_KEYS = [
        'm4_analysis_id', 'm4_algorithm', 'm4_algorithm_version', 'm4_candidates',
        'duration_ms', 'transcript_state', 'projection_version', 'text_hashes',
        'request_sha256',
    ];

    /**
     * The exact text hash member key set.
     *
     * @var list<string>
     */
    private const TEXT_HASH_KEYS = ['index', 'sha256'];

    /**
     * The exact execution parameter key set.
     *
     * @var list<string>
     */
    private const EXECUTION_KEYS = ['timeout_seconds', 'lock_wait_seconds'];

    /**
     * The exact persisted M4 candidate key set carried by a snapshot.
     *
     * @var list<string>
     */
    private const M4_CANDIDATE_KEYS = [
        'index', 'start_ms', 'end_ms', 'rank', 'score', 'criteria', 'source_scene_indexes',
    ];

    /**
     * Validate the worker request before any process is created.
     *
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException
     */
    public static function request(mixed $request): array
    {
        $data = self::fields($request, self::REQUEST_KEYS);

        self::require($data['version'] === self::CONTRACT_VERSION, 'Unsupported ranking contract version');
        self::require($data['action'] === self::ACTION, 'Unsupported ranking action');

        $media = self::fields($data['media'], ['duration_ms']);
        self::integer($media['duration_ms'], 1, self::MAX_DURATION_MS);
        $data['media'] = $media;

        $configuration = self::fields($data['configuration'], ClipRankingProfile::CONFIGURATION_KEYS);
        self::require(self::configurationMatchesSelection($configuration), 'Ranking configuration is not the selected profile');
        $data['configuration'] = $configuration;

        $candidates = $data['candidates'];
        self::require(is_array($candidates) && array_is_list($candidates), 'Candidates must be a list');
        self::require(count($candidates) > 0, 'Ranking requests require at least one candidate');
        self::require(count($candidates) <= self::MAX_CANDIDATES, 'Too many ranking candidates');
        $data['candidates'] = self::validateRequestCandidates($candidates, $media['duration_ms']);

        // A worker request is only legitimate when at least one candidate
        // carries usable text; local zero/empty outcomes never reach here.
        $usable = false;
        foreach ($data['candidates'] as $candidate) {
            if ($candidate['transcript_text'] !== '') {
                $usable = true;
                break;
            }
        }
        self::require($usable, 'Ranking requests require usable candidate text');

        return $data;
    }

    /**
     * Validate a worker ranking result independently of any success claim.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException
     */
    public static function result(mixed $result, array $request, string $requestSha256): array
    {
        try {
            $result = self::toArrays($result);

            self::require(is_array($result), 'Result must be an object');
            $result = self::fields($result, ['status', 'ranking']);
            self::require($result['status'] === 'success', 'Status must be success');
            self::require(is_array($result['ranking']), 'Ranking must be an object');

            $ranking = self::fields($result['ranking'], [
                'algorithm', 'algorithm_version', 'parameters', 'request_sha256', 'recommendations',
            ]);

            self::require(is_array($ranking['recommendations']) && array_is_list($ranking['recommendations']),
                'Recommendations must be a list');

            self::validateRanking($ranking, $request, $requestSha256);

            return $result;
        } catch (ProcessMediaException) {
            // Sanitized: fixed category, no previous cause, no worker detail.
            throw self::validationFailed();
        }
    }

    /**
     * Validate a durable completion at the model boundary.
     *
     * The completion payload is self describing: the input snapshot carries
     * the recorded authority, transcript state, text hashes and the optional
     * worker request digest, so no projection or inference is rerun here.
     *
     * @param  array<string, mixed>  $completion
     *
     * @throws ProcessMediaException
     */
    public static function validateCompletion(mixed $completion): void
    {
        try {
            $completion = self::toArrays($completion);
            self::require(is_array($completion), 'Completion must be an object');

            $payload = self::fields($completion, [
                'algorithm', 'algorithm_version', 'parameters', 'recommendations',
                'input_snapshot', 'execution_parameters',
            ]);

            self::require(is_array($payload['parameters']), 'Parameters must be an object');
            $parameters = self::fields($payload['parameters'], ClipRankingProfile::parameterKeys());
            $configuration = self::configurationFromParameters(
                $parameters,
                $payload['algorithm'],
                $payload['algorithm_version'],
            );

            $snapshot = self::validateSnapshot($payload['input_snapshot']);
            $request = self::requestFromSnapshot($snapshot, $configuration);

            self::require(is_array($payload['recommendations']) && array_is_list($payload['recommendations']),
                'Recommendations must be a list');

            $ranking = [
                'algorithm' => $payload['algorithm'],
                'algorithm_version' => $payload['algorithm_version'],
                'parameters' => $parameters,
                'request_sha256' => $snapshot['request_sha256'],
                'recommendations' => $payload['recommendations'],
            ];

            // A local outcome is identified by the absence of a worker
            // request digest: it never claims inference or transcript use.
            $localOutcome = $snapshot['request_sha256'] === null;

            self::validateRanking($ranking, $request, $snapshot['request_sha256'], $localOutcome);
            self::validateExecutionParameters($payload['execution_parameters']);
        } catch (ProcessMediaException) {
            throw self::validationFailed();
        }
    }

    /**
     * The exact local unavailable references of a validated M4 authority.
     *
     * A local outcome is built from the authoritative M4 candidate list, never
     * from a worker request: the specification only allows a worker request
     * when at least one candidate carries usable text, so routing a local
     * outcome through the request validator would reject precisely the
     * outcomes that must never reach the worker. K exact references are kept,
     * both semantic fields stay null and the reason repeats the outcome
     * reason.
     *
     * @param  list<array{index: int, start_ms: int, end_ms: int, rank: int, score: float|int}>  $m4Candidates
     * @return list<array<string, mixed>>
     *
     * @throws ProcessMediaException
     */
    public static function localUnavailableRecommendations(array $m4Candidates, string $reason): array
    {
        self::require(in_array($reason, self::UNAVAILABLE_REASONS, true), 'Unknown unavailable reason');

        $recommendations = [];
        foreach ($m4Candidates as $candidate) {
            $recommendations[] = [
                'm4_candidate_index' => (int) $candidate['index'],
                'start_ms' => (int) $candidate['start_ms'],
                'end_ms' => (int) $candidate['end_ms'],
                'm4_rank' => (int) $candidate['rank'],
                'm4_score' => $candidate['score'],
                'semantic_score' => null,
                'semantic_rank' => null,
                'reason' => $reason,
            ];
        }

        return $recommendations;
    }

    /**
     * The exact local empty completion of a validated K=0 request.
     *
     * @return list<array<string, mixed>>
     */
    public static function noCandidateRecommendations(): array
    {
        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Shared invariant core
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $ranking
     * @param  array<string, mixed>  $request
     */
    private static function validateRanking(array $ranking, array $request, mixed $requestSha256, bool $localOutcome = false): void
    {
        $configuration = $request['configuration'];

        self::require($ranking['algorithm'] === $configuration['algorithm'], 'Invalid algorithm');
        self::require($ranking['algorithm_version'] === $configuration['algorithm_version'], 'Invalid algorithm version');

        $parameters = self::fields($ranking['parameters'], ClipRankingProfile::parameterKeys());
        self::validateParameters($parameters, $configuration);

        // The response digest binds the result to this exact invocation.
        if ($localOutcome) {
            // A local outcome never claims a worker invocation.
            self::require($requestSha256 === null, 'Local outcome must not carry a worker digest');
            self::require($ranking['request_sha256'] === null, 'Local outcome must not carry a worker digest');
            self::require($parameters['inference_performed'] === false, 'Local outcome must not claim inference');
            self::require($parameters['transcript_used'] === false, 'Local outcome must not claim transcript use');
        } else {
            self::require(is_string($requestSha256) && preg_match('/^[0-9a-f]{64}$/', $requestSha256) === 1,
                'Invalid request digest binding');
            self::require($ranking['request_sha256'] === $requestSha256, 'Request digest mismatch');
            self::require(
                $parameters['inference_performed'] === ClipRankingProfile::inferencePerformed($configuration['provider']),
                'Inference flag does not match the selected profile'
            );
            // These worker requests are transcript bearing by construction.
            self::require($parameters['transcript_used'] === true, 'Worker requests use the transcript');
        }

        self::validateRecommendations($ranking['recommendations'], $request, $parameters, $localOutcome);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $configuration
     */
    private static function validateParameters(array $parameters, array $configuration): void
    {
        $expected = $configuration;
        unset($expected['algorithm'], $expected['algorithm_version']);

        self::require(self::hasExactKeys($parameters, ClipRankingProfile::parameterKeys()), 'Unexpected parameters key set');

        foreach ($expected as $key => $value) {
            self::require($parameters[$key] === $value, "Parameter {$key} does not match the selected profile");
        }

        self::require(
            is_string($parameters['provider_name']) && $parameters['provider_name'] !== '',
            'Provider identity must be a non-empty string'
        );
        self::require(
            $parameters['provider_name'] === ClipRankingProfile::providerName($configuration['provider']),
            'Provider identity does not match the selected profile'
        );
        self::require($parameters['provider'] !== $parameters['provider_name'], 'Provider selector and identity must differ');
        self::require(is_bool($parameters['inference_performed']), 'inference_performed must be boolean');
        self::require(is_bool($parameters['transcript_used']), 'transcript_used must be boolean');
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $parameters
     */
    private static function validateRecommendations(mixed $recommendations, array $request, array $parameters, bool $localOutcome): void
    {
        $candidates = $request['candidates'];
        $k = count($candidates);

        self::require(count($recommendations) === $k, 'Recommendation count mismatch');

        if ($k === 0) {
            // A zero-candidate local completion is exact and stays empty.
            self::require($recommendations === [], 'A zero-candidate outcome must stay empty');

            return;
        }

        $seenIndexes = [];
        $seenSemanticRanks = [];
        $previousUnits = null;
        $previousM4Rank = null;
        $scoredEntries = 0;
        $enteredUnscored = false;

        foreach ($recommendations as $position => $member) {
            self::require(is_array($member) && ! array_is_list($member), 'Recommendation must be an object');
            $member = self::fields($member, self::RECOMMENDATION_KEYS);

            $index = $member['m4_candidate_index'];
            self::require(is_int($index) && ! is_bool($index), 'm4_candidate_index must be integer');
            self::require($index >= 0 && $index < $k, 'm4_candidate_index out of range');
            self::require(! in_array($index, $seenIndexes, true), 'Duplicate m4_candidate_index');
            $seenIndexes[] = $index;

            $candidate = $candidates[$index];

            // Every reference type is checked explicitly, then compared to the
            // authoritative request value: integers must stay integers, and the
            // M4 score must keep the exact numeric value that crossed the
            // boundary without normalization.
            self::require(is_int($member['start_ms']) && ! is_bool($member['start_ms']), 'start_ms must be integer');
            self::require(is_int($member['end_ms']) && ! is_bool($member['end_ms']), 'end_ms must be integer');
            self::require(is_int($member['m4_rank']) && ! is_bool($member['m4_rank']), 'm4_rank must be integer');
            self::require(
                (is_int($member['m4_score']) || is_float($member['m4_score'])) && ! is_bool($member['m4_score'])
                    && is_finite((float) $member['m4_score']),
                'm4_score must be a finite number'
            );

            self::require($member['start_ms'] === $candidate['start_ms'], 'start_ms does not match the request');
            self::require($member['end_ms'] === $candidate['end_ms'], 'end_ms does not match the request');
            self::require($member['m4_rank'] === $candidate['m4_rank'], 'm4_rank does not match the request');
            self::require($member['m4_score'] == $candidate['m4_score'], 'm4_score does not match the request');

            $eligible = $candidate['transcript_text'] !== '';
            $score = $member['semantic_score'];
            $semanticRank = $member['semantic_rank'];
            $reason = $member['reason'];

            if (! $eligible) {
                // Unscored entries carry both semantic fields null and a fixed
                // reason, and never follow a scored entry.
                self::require($score === null, 'Ineligible candidate must not carry a semantic score');
                self::require($semanticRank === null, 'Ineligible candidate must not carry a semantic rank');
                self::require(is_string($reason) && $reason !== '', 'Unscored entry must carry a reason');
                if ($localOutcome) {
                    // A local unavailable outcome repeats its own reason.
                    self::require(in_array($reason, self::UNAVAILABLE_REASONS, true),
                        'Unscored entry must carry a known unavailable reason');
                } else {
                    // A worker result only ever reports the fixed unscored reason.
                    self::require($reason === self::UNSCORED_REASON, 'Unscored entry must carry the fixed unscored reason');
                }
                $enteredUnscored = true;

                continue;
            }

            self::require($reason === null, 'Scored entry must not carry a reason');
            self::require(is_int($score) || is_float($score), 'semantic_score must be a number');
            self::require(! is_bool($score) && is_finite((float) $score), 'semantic_score must be finite');
            $value = (float) $score;
            self::require($value >= 0.0 && $value <= 1.0, 'semantic_score out of inclusive [0,1] bounds');
            self::require(self::hasAtMostSixDecimals($value), 'semantic_score exceeds six decimal digits');
            self::require(is_int($semanticRank) && ! is_bool($semanticRank), 'semantic_rank must be integer');
            self::require(! $enteredUnscored, 'Scored entry must not follow an unscored entry');

            $scoredEntries++;
            self::require($semanticRank === $scoredEntries, 'semantic_rank must be contiguous from 1');
            self::require(! in_array($semanticRank, $seenSemanticRanks, true), 'Duplicate semantic_rank');
            $seenSemanticRanks[] = $semanticRank;

            $units = self::scoreUnits($value);
            if ($previousUnits !== null) {
                self::require($units <= $previousUnits, 'Scored entries must be ordered by descending score units');
                if ($units === $previousUnits) {
                    self::require($member['m4_rank'] > $previousM4Rank, 'Equal score units must be ordered by ascending M4 rank');
                }
            }
            $previousUnits = $units;
            $previousM4Rank = $member['m4_rank'];
        }

        sort($seenIndexes);
        self::require($seenIndexes === range(0, $k - 1), 'Every candidate index must occur exactly once');
        if ($scoredEntries > 0) {
            self::require($seenSemanticRanks === range(1, $scoredEntries), 'semantic_rank must form a contiguous 1..N set');
        } else {
            self::require($seenSemanticRanks === [], 'A fully unscored outcome must carry no semantic rank');
        }
    }

    /**
     * The exact M4 authority, transcript state, hashes and digest of a
     * durable M5 record.
     *
     * @return array<string, mixed>
     */
    public static function validateSnapshot(mixed $snapshot): array
    {
        $snapshot = self::fields($snapshot, self::SNAPSHOT_KEYS);

        self::require(is_int($snapshot['m4_analysis_id']) && ! is_bool($snapshot['m4_analysis_id'])
            && $snapshot['m4_analysis_id'] > 0, 'm4_analysis_id must be a positive integer');
        self::require(is_string($snapshot['m4_algorithm']) && $snapshot['m4_algorithm'] === self::M4_ALGORITHM,
            'Recorded M4 algorithm is not the expected authority');
        self::require(is_string($snapshot['m4_algorithm_version'])
            && $snapshot['m4_algorithm_version'] === self::M4_ALGORITHM_VERSION,
            'Recorded M4 algorithm version is not the expected authority');
        self::require(is_int($snapshot['duration_ms']) && ! is_bool($snapshot['duration_ms'])
            && $snapshot['duration_ms'] >= 1 && $snapshot['duration_ms'] <= self::MAX_DURATION_MS,
            'duration_ms must be a strict positive bounded integer');
        self::require(is_string($snapshot['transcript_state'])
            && in_array($snapshot['transcript_state'], self::TRANSCRIPT_STATES, true),
            'Unknown transcript state');
        self::require(is_string($snapshot['projection_version']) && $snapshot['projection_version'] !== '',
            'projection_version must be a non-empty string');

        self::require(is_array($snapshot['m4_candidates']) && array_is_list($snapshot['m4_candidates']),
            'm4_candidates must be a list');
        self::require(count($snapshot['m4_candidates']) <= self::MAX_CANDIDATES, 'Too many recorded M4 candidates');
        $k = count($snapshot['m4_candidates']);
        $seenRanks = [];
        foreach ($snapshot['m4_candidates'] as $position => $candidate) {
            self::require(is_array($candidate) && ! array_is_list($candidate), 'M4 candidate must be an object');
            $candidate = self::fields($candidate, self::M4_CANDIDATE_KEYS);
            self::require($candidate['index'] === $position, 'M4 candidate index must be sequential');
            self::integer($candidate['start_ms'], 0, self::MAX_DURATION_MS);
            self::integer($candidate['end_ms'], 0, self::MAX_DURATION_MS);
            self::integer($candidate['rank'], 1, max($k, 1));
            self::require((is_int($candidate['score']) || is_float($candidate['score'])) && ! is_bool($candidate['score']),
                'M4 candidate score must be numeric');
            self::require(is_array($candidate['criteria']) && ! array_is_list($candidate['criteria']),
                'M4 candidate criteria must be an object');
            self::require(is_array($candidate['source_scene_indexes']) && array_is_list($candidate['source_scene_indexes']),
                'M4 candidate source scenes must be a list');
            self::require($candidate['end_ms'] > $candidate['start_ms']
                && $candidate['end_ms'] <= $snapshot['duration_ms'], 'M4 candidate bounds are invalid');
            $seenRanks[] = $candidate['rank'];
        }
        if ($k > 0) {
            sort($seenRanks);
            self::require($seenRanks === range(1, $k), 'M4 candidate ranks must form 1..K');
        }

        self::require(is_array($snapshot['text_hashes']) && array_is_list($snapshot['text_hashes']),
            'text_hashes must be a list');
        self::require(count($snapshot['text_hashes']) === $k, 'text_hashes must cover every candidate');
        foreach ($snapshot['text_hashes'] as $position => $entry) {
            self::require(is_array($entry) && ! array_is_list($entry), 'Text hash must be an object');
            $entry = self::fields($entry, self::TEXT_HASH_KEYS);
            self::require($entry['index'] === $position, 'Text hash index must be chronological');
            self::require(ClipRecommendationProjection::isDigest($entry['sha256']), 'Text hash must be lowercase 64 hex');
        }

        self::require($snapshot['request_sha256'] === null || ClipRecommendationProjection::isDigest($snapshot['request_sha256']),
            'Recorded request digest must be null or lowercase 64 hex');

        return $snapshot;
    }

    /**
     * The recorded snapshot bound to the selected profile, expressed as the
     * normalized request the shared core validates against.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private static function requestFromSnapshot(array $snapshot, array $configuration): array
    {
        self::require($snapshot['projection_version'] === $configuration['projection_version'],
            'Recorded projection version does not match the selected profile');

        $candidates = [];
        foreach ($snapshot['m4_candidates'] as $candidate) {
            // Eligibility is fully captured by the recorded text hash: the
            // empty-string digest marks a candidate with no usable text.
            $usable = ! ClipRecommendationProjection::isEmptyDigest($snapshot['text_hashes'][$candidate['index']]['sha256']);

            $candidates[] = [
                'index' => $candidate['index'],
                'start_ms' => $candidate['start_ms'],
                'end_ms' => $candidate['end_ms'],
                'm4_rank' => $candidate['rank'],
                'm4_score' => $candidate['score'],
                'transcript_text' => $usable ? 'recorded' : '',
            ];
        }

        return [
            'version' => self::CONTRACT_VERSION,
            'action' => self::ACTION,
            'media' => ['duration_ms' => $snapshot['duration_ms']],
            'candidates' => $candidates,
            'configuration' => $configuration,
        ];
    }

    /**
     * Rebuild the exact request configuration from a recorded parameters
     * object plus the recorded algorithm identity.
     *
     * Public entry point for the model completion boundary, which compares a
     * caller snapshot against the currently selected profile without building
     * a worker request.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException
     */
    public static function configurationFromRecordedParameters(
        array $parameters,
        mixed $algorithm,
        mixed $algorithmVersion,
    ): array {
        try {
            return self::configurationFromParameters($parameters, $algorithm, $algorithmVersion);
        } catch (ProcessMediaException) {
            throw self::validationFailed();
        }
    }

    private static function configurationFromParameters(array $parameters, mixed $algorithm, mixed $algorithmVersion): array
    {
        $configuration = $parameters;
        unset($configuration['provider_name'], $configuration['inference_performed'], $configuration['transcript_used']);

        $withIdentity = ['algorithm' => $algorithm, 'algorithm_version' => $algorithmVersion];
        foreach (array_reverse(array_diff(ClipRankingProfile::CONFIGURATION_KEYS, array_keys($withIdentity))) as $key) {
            $withIdentity = [$key => $configuration[$key] ?? null] + $withIdentity;
        }

        self::require(
            self::hasExactKeys($withIdentity, ClipRankingProfile::CONFIGURATION_KEYS),
            'Parameters do not carry the exact configuration key set'
        );
        self::require(self::configurationMatchesSelection($withIdentity), 'Recorded configuration is not a known profile');

        return $withIdentity;
    }

    /**
     * Whether a configuration object is exactly the pinned selected profile.
     *
     * @param  array<string, mixed>  $configuration
     */
    public static function configurationMatchesSelection(array $configuration): bool
    {
        if (! self::hasExactKeys($configuration, ClipRankingProfile::CONFIGURATION_KEYS)) {
            return false;
        }

        try {
            $expected = ClipRankingProfile::configuration($configuration['provider']);
        } catch (ProcessMediaException) {
            return false;
        }

        // Key order is not part of the contract; every value must match the
        // pinned selected profile exactly.
        foreach (ClipRankingProfile::CONFIGURATION_KEYS as $key) {
            if ($configuration[$key] !== $expected[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $candidates
     * @return array<int, mixed>
     */
    private static function validateRequestCandidates(array $candidates, int $durationMs): array
    {
        $k = count($candidates);
        $seenRanks = [];

        foreach ($candidates as $position => $candidate) {
            self::require(is_array($candidate) && ! array_is_list($candidate), 'Candidate must be an object');
            $candidate = self::fields($candidate, self::REQUEST_CANDIDATE_KEYS);

            self::require($candidate['index'] === $position, 'Candidate index must be sequential 0..K-1');
            self::integer($candidate['start_ms'], 0, $durationMs);
            self::integer($candidate['end_ms'], 0, $durationMs);
            self::require($candidate['end_ms'] > $candidate['start_ms'], 'Candidate interval must be positive');
            self::integer($candidate['m4_rank'], 1, $k);
            self::require((is_int($candidate['m4_score']) || is_float($candidate['m4_score'])) && ! is_bool($candidate['m4_score']),
                'Candidate m4_score must be numeric');
            self::require(is_finite((float) $candidate['m4_score']), 'Candidate m4_score must be finite');
            self::require($candidate['m4_score'] >= 0 && $candidate['m4_score'] <= 1,
                'Candidate m4_score must stay inside the M4 score range');
            self::require(is_string($candidate['transcript_text']), 'Candidate transcript_text must be a string');
            self::require(strlen($candidate['transcript_text']) <= ClipRecommendationProjection::MAX_CANDIDATE_TEXT_BYTES,
                'Candidate transcript_text exceeds the canonical byte bound');
            // Request text is already canonical: canonicalization is
            // idempotent on it, and it is either usable text or the exact
            // empty string. Non-canonical or whitespace-only text never
            // reaches the worker boundary.
            self::require(
                ClipRecommendationProjection::canonicalizeSegmentText($candidate['transcript_text']) === $candidate['transcript_text'],
                'Candidate transcript_text must already be canonical'
            );
            self::require(
                $candidate['transcript_text'] === '' || ClipRecommendationProjection::isUsable($candidate['transcript_text']),
                'Candidate transcript_text must be canonical usable text or empty'
            );

            $seenRanks[] = $candidate['m4_rank'];
        }

        sort($seenRanks);
        self::require($seenRanks === range(1, $k), 'Candidate M4 ranks must form 1..K');

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $executionParameters
     */
    private static function validateExecutionParameters(mixed $executionParameters): void
    {
        $executionParameters = self::fields($executionParameters, self::EXECUTION_KEYS);

        self::integer($executionParameters['timeout_seconds'], ClipRankingProfile::TIMEOUT_MIN, ClipRankingProfile::TIMEOUT_MAX);
        self::require(
            $executionParameters['lock_wait_seconds'] === $executionParameters['timeout_seconds'] + ClipRankingProfile::LOCK_WAIT_OFFSET_SECONDS,
            'lock_wait_seconds must equal the captured timeout plus the fixed offset'
        );
    }

    /**
     * The six-decimal score unit used for the authoritative ordering.
     */
    private static function scoreUnits(float $value): int
    {
        return (int) floor($value * self::SCORE_UNITS + 0.5);
    }

    private static function hasAtMostSixDecimals(float $value): bool
    {
        return (float) sprintf('%.6F', $value) === $value;
    }

    private static function toArrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::toArrays(...), $value) : $value;
    }

    /**
     * Require an object carrying exactly the given key set.
     *
     * Key order is not part of the contract; membership is. Missing, extra and
     * unknown members are rejected recursively at every validated level.
     *
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function fields(mixed $value, array $required): array
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        self::require(is_array($value) && ! array_is_list($value), 'Expected an object');

        $keys = array_keys($value);
        sort($keys);
        $expected = $required;
        sort($expected);
        self::require($keys === $expected, 'Unexpected key set');

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        $expected = $keys;
        sort($expected);

        return $actual === $expected;
    }

    private static function integer(mixed $value, int $low, int $high): void
    {
        self::require(is_int($value) && ! is_bool($value) && $value >= $low && $value <= $high,
            'Expected an integer inside the allowed range');
    }

    private static function require(bool $condition, string $message = 'Validation failed'): void
    {
        if (! $condition) {
            throw new ProcessMediaException($message, 1, '');
        }
    }

    private static function validationFailed(): ProcessMediaException
    {
        return new ProcessMediaException('Ranking validation failed', 1, '');
    }
}
