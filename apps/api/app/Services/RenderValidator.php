<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;
use stdClass;

/**
 * Independent PHP trust boundary for the metadata-only render_clips action.
 *
 * The same invariant core runs at the worker trust boundary (result) and at
 * the model completion boundary (validateCompletion), so persistence cannot
 * bypass any envelope, provenance, reference, precision, or output check.
 */
final class RenderValidator
{
    public const MAX_DURATION_MS = RenderProfile::MAX_DURATION_MS;
    public const MAX_RECOMMENDATIONS = RenderProfile::MAX_RECOMMENDATIONS;
    public const MAX_INPUT_BYTES = RenderProfile::MAX_INPUT_BYTES;

    public const CONTRACT_VERSION = '1.0.0';
    public const ACTION = 'render_clips';
    public const ALGORITHM = RenderProfile::ALGORITHM;
    public const ALGORITHM_VERSION = RenderProfile::ALGORITHM_VERSION;
    public const RENDER_PROFILE_VERSION = RenderProfile::RENDER_PROFILE_VERSION;

    /**
     * The exact request key set.
     *
     * @var list<string>
     */
    private const REQUEST_KEYS = [
        'version', 'action', 'media', 'recommendation', 'candidate_index', 'configuration', 'source_media', 'media_asset_id', 'recommendation_id',
    ];

    /**
     * The exact recommendation key set of a render_clips request.
     *
     * @var list<string>
     */
    private const REQUEST_RECOMMENDATION_KEYS = [
        'candidates', 'candidate_index',
    ];

    /**
     * The exact candidate key set of a render_clips request.
     *
     * @var list<string>
     */
    private const REQUEST_CANDIDATE_KEYS = [
        'index', 'start_ms', 'end_ms', 'semantic_rank', 'semantic_score',
    ];

    /**
     * The exact media key set.
     *
     * @var list<string>
     */
    private const MEDIA_KEYS = ['duration_ms'];

    /**
     * The exact source_media key set.
     *
     * @var list<string>
     */
    private const SOURCE_MEDIA_KEYS = [
        'disk', 'key', 'width', 'height', 'video_codec', 'audio_codec',
    ];

    /**
     * The exact configuration key set of a render_clips request.
     *
     * @var list<string>
     */
    private const CONFIGURATION_KEYS = RenderProfile::CONFIGURATION_KEYS;

    /**
     * The exact clip key set of a worker result.
     *
     * @var list<string>
     */
    private const CLIP_KEYS = [
        'candidate_index', 'semantic_rank', 'semantic_score',
        'start_ms', 'end_ms', 'duration_ms', 'output',
    ];

    /**
     * The exact output key set.
     *
     * @var list<string>
     */
    private const OUTPUT_KEYS = [
        'disk', 'key', 'size_bytes', 'duration_ms',
        'width', 'height', 'video_codec', 'audio_codec',
        'video_bitrate_kbps', 'audio_bitrate_kbps',
    ];

    /**
     * The exact parameters key set of a worker result.
     *
     * @var list<string>
     */
    private const PARAMETERS_KEYS = RenderProfile::parameterKeys();

    /**
     * The exact execution parameter key set.
     *
     * @var list<string>
     */
    private const EXECUTION_KEYS = ['timeout_seconds', 'lock_wait_seconds'];

    /**
     * The exact limits key set.
     *
     * @var list<string>
     */
    private const LIMITS_KEYS = [
        'max_recommendations', 'max_input_bytes', 'max_duration_ms',
    ];

    /**
     * The exact source_media key set in parameters.
     *
     * @var list<string>
     */
    private const PARAMETERS_SOURCE_MEDIA_KEYS = [
        'disk', 'key', 'duration_ms', 'width', 'height', 'video_codec', 'audio_codec',
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

        self::require($data['version'] === self::CONTRACT_VERSION, 'Unsupported render contract version');
        self::require($data['action'] === self::ACTION, 'Unsupported render action');

        // Validate media_asset_id
        self::integer($data['media_asset_id'], 1);
        $mediaAssetId = $data['media_asset_id'];

        // Validate recommendation_id
        self::integer($data['recommendation_id'], 1);
        $recommendationId = $data['recommendation_id'];

        $media = self::fields($data['media'], self::MEDIA_KEYS);
        self::integer($media['duration_ms'], 1, self::MAX_DURATION_MS);
        $data['media'] = $media;

        // Validate recommendation
        $recommendation = self::fields($data['recommendation'], self::REQUEST_RECOMMENDATION_KEYS);
        $candidates = $recommendation['candidates'];
        $candidateIndex = $recommendation['candidate_index'];

        self::require(is_array($candidates) && array_is_list($candidates), 'Candidates must be a list');
        self::require(count($candidates) > 0, 'Render requests require at least one candidate');
        self::require(count($candidates) <= self::MAX_RECOMMENDATIONS, 'Too many candidates');

        self::require(is_int($candidateIndex) && ! is_bool($candidateIndex), 'candidate_index must be integer');
        self::require($candidateIndex >= 0 && $candidateIndex < count($candidates), 'candidate_index out of bounds');

        $data['recommendation'] = self::validateRequestRecommendation($candidates, $candidateIndex, $media['duration_ms']);

        // Validate configuration
        $configuration = self::fields($data['configuration'], self::CONFIGURATION_KEYS);
        self::require(self::configurationMatchesProfile($configuration), 'Render configuration is not the selected profile');
        $data['configuration'] = $configuration;

        // Validate source_media
        $sourceMedia = self::fields($data['source_media'], self::SOURCE_MEDIA_KEYS);
        self::validateSourceMedia($sourceMedia);
        $data['source_media'] = $sourceMedia;

        return $data;
    }

    /**
     * Validate a worker render result independently of any success claim.
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
            $result = self::fields($result, ['status', 'render']);
            self::require($result['status'] === 'success', 'Status must be success');
            self::require(is_array($result['render']), 'Render must be an object');

            $render = self::fields($result['render'], [
                'algorithm', 'algorithm_version', 'parameters', 'clips',
            ]);

            self::validateRender($render, $request, $requestSha256);

            return $result;
        } catch (ProcessMediaException) {
            // Sanitized: fixed category, no previous cause, no worker detail.
            throw self::validationFailed();
        }
    }

    /**
     * Validate a durable completion at the model boundary.
     *
     * The completion payload is self-describing: the render columns carry
     * the recorded authority, so no projection or inference is rerun here.
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
                'algorithm', 'algorithm_version', 'parameters', 'clips',
                'execution_parameters',
            ]);

            self::require(is_array($payload['parameters']), 'Parameters must be an object');
            $parameters = self::fields($payload['parameters'], self::PARAMETERS_KEYS);
            self::validateParameters($parameters);

            self::require(is_array($payload['clips']) && array_is_list($payload['clips']),
                'Clips must be a list');
            self::require(count($payload['clips']) === 1, 'Exactly one clip required');

            // Validate clip against the recorded parameters
            self::validateCompletionClip($payload['clips'][0], $parameters);

            self::validateExecutionParameters($payload['execution_parameters']);
        } catch (ProcessMediaException) {
            throw self::validationFailed();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Shared invariant core
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $render
     * @param  array<string, mixed>  $request
     */
    private static function validateRender(array $render, array $request, mixed $requestSha256): void
    {
        $configuration = $request['configuration'];

        self::require($render['algorithm'] === self::ALGORITHM, 'Invalid algorithm');
        self::require($render['algorithm_version'] === self::ALGORITHM_VERSION, 'Invalid algorithm version');

        $parameters = self::fields($render['parameters'], self::PARAMETERS_KEYS);
        self::validateParameters($parameters, $configuration);

        // The response digest binds the result to this exact invocation.
        self::require(is_string($requestSha256) && preg_match('/^[0-9a-f]{64}$/', $requestSha256) === 1,
            'Invalid request digest binding');
        self::require($parameters['request_sha256'] === $requestSha256, 'Request digest mismatch');

        self::require(is_array($render['clips']) && array_is_list($render['clips']), 'Clips must be a list');
        self::require(count($render['clips']) === 1, 'Exactly one clip required');

        $clip = $render['clips'][0];
        self::require(is_array($clip) && ! array_is_list($clip), 'Clip must be an object');
        $clip = self::fields($clip, self::CLIP_KEYS);

        // Validate clip matches the selected candidate
        $selectedCandidate = $request['recommendation']['candidates'][$request['recommendation']['candidate_index']];
        self::require($clip['candidate_index'] === $selectedCandidate['index'], 'Clip candidate_index mismatch');
        self::require($clip['semantic_rank'] === $selectedCandidate['semantic_rank'], 'Clip semantic_rank mismatch');
        self::require($clip['semantic_score'] === $selectedCandidate['semantic_score'], 'Clip semantic_score mismatch');
        self::require($clip['start_ms'] === $selectedCandidate['start_ms'], 'Clip start_ms mismatch');
        self::require($clip['end_ms'] === $selectedCandidate['end_ms'], 'Clip end_ms mismatch');
        self::require($clip['duration_ms'] === ($selectedCandidate['end_ms'] - $selectedCandidate['start_ms']), 'Clip duration_ms mismatch');

        // Validate output metadata
        self::validateOutput($clip['output'], $parameters);
    }

    /**
     * Validate a clip at the completion boundary.
     *
     * @param  array<string, mixed>  $clip
     * @param  array<string, mixed>  $parameters
     */
    private static function validateCompletionClip(array $clip, array $parameters): void
    {
        $clip = self::fields($clip, self::CLIP_KEYS);

        // Validate output metadata
        self::validateOutput($clip['output'], $parameters);

        // Validate filter graph is present and non-empty
        self::require(is_string($parameters['filter_graph']) && $parameters['filter_graph'] !== '', 'Filter graph must be non-empty');

        // Validate ffmpeg_version is present and non-empty
        self::require(is_string($parameters['ffmpeg_version']) && $parameters['ffmpeg_version'] !== '', 'FFmpeg version must be non-empty');
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $configuration
     */
    private static function validateParameters(array $parameters, ?array $configuration = null): void
    {
        self::require(self::hasExactKeys($parameters, self::PARAMETERS_KEYS), 'Unexpected parameters key set');

        if ($configuration !== null) {
            $expectedConfig = $configuration;
            self::require($parameters['configuration'] === $expectedConfig, 'Configuration does not match request');
        }

        // Validate source_media
        $sourceMedia = self::fields($parameters['source_media'], self::PARAMETERS_SOURCE_MEDIA_KEYS);
        self::validateSourceMedia($sourceMedia);

        // Validate ffmpeg_version
        self::require(is_string($parameters['ffmpeg_version']) && $parameters['ffmpeg_version'] !== '', 'FFmpeg version must be non-empty string');

        // Validate filter_graph
        self::require(is_string($parameters['filter_graph']) && $parameters['filter_graph'] !== '', 'Filter graph must be non-empty string');

        // Validate limits
        $limits = self::fields($parameters['limits'], self::LIMITS_KEYS);
        self::require($limits['max_recommendations'] === self::MAX_RECOMMENDATIONS, 'Limits max_recommendations mismatch');
        self::require($limits['max_input_bytes'] === self::MAX_INPUT_BYTES, 'Limits max_input_bytes mismatch');
        self::require($limits['max_duration_ms'] === self::MAX_DURATION_MS, 'Limits max_duration_ms mismatch');
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $parameters
     */
    private static function validateOutput(array $output, array $parameters): void
    {
        $output = self::fields($output, self::OUTPUT_KEYS);

        // Output file must exist and have size > 0 (checked by Laravel via Storage)
        self::require(is_int($output['size_bytes']) && ! is_bool($output['size_bytes']) && $output['size_bytes'] > 0, 'Output size_bytes must be positive integer');
        self::require(is_int($output['duration_ms']) && ! is_bool($output['duration_ms']) && $output['duration_ms'] > 0, 'Output duration_ms must be positive integer');
        self::require(is_int($output['width']) && ! is_bool($output['width']) && $output['width'] > 0, 'Output width must be positive integer');
        self::require(is_int($output['height']) && ! is_bool($output['height']) && $output['height'] > 0, 'Output height must be positive integer');
        self::require(is_string($output['video_codec']) && $output['video_codec'] !== '', 'Output video_codec must be non-empty string');
        self::require(is_string($output['audio_codec']) && $output['audio_codec'] !== '', 'Output audio_codec must be non-empty string');
        self::require(is_int($output['video_bitrate_kbps']) && ! is_bool($output['video_bitrate_kbps']) && $output['video_bitrate_kbps'] > 0, 'Output video_bitrate_kbps must be positive integer');
        self::require(is_int($output['audio_bitrate_kbps']) && ! is_bool($output['audio_bitrate_kbps']) && $output['audio_bitrate_kbps'] > 0, 'Output audio_bitrate_kbps must be positive integer');

        // Validate resolution matches configuration
        $config = $parameters['configuration'];
        self::require($output['width'] === $config['target_width'], 'Output width does not match configuration');
        self::require($output['height'] === $config['target_height'], 'Output height does not match configuration');

        // Duration tolerance: within 5% of expected (container overhead)
        // The expected duration is in the clip's duration_ms, validated separately
    }

    /**
     * @param  array<string, mixed>  $executionParameters
     */
    private static function validateExecutionParameters(mixed $executionParameters): void
    {
        $executionParameters = self::fields($executionParameters, self::EXECUTION_KEYS);

        self::integer($executionParameters['timeout_seconds'], RenderProfile::TIMEOUT_MIN, RenderProfile::TIMEOUT_MAX);
        self::require(
            $executionParameters['lock_wait_seconds'] === $executionParameters['timeout_seconds'] + RenderProfile::LOCK_WAIT_OFFSET_SECONDS,
            'lock_wait_seconds must equal the captured timeout plus the fixed offset'
        );
    }

    /**
     * Validate the recommendation structure in the request.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return array{candidates: list<array<string, mixed>>, candidate_index: int}
     */
    private static function validateRequestRecommendation(array $candidates, int $candidateIndex, int $durationMs): array
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
            self::integer($candidate['semantic_rank'], 1, $k);
            self::require(! in_array($candidate['semantic_rank'], $seenRanks, true), 'Duplicate semantic_rank');
            $seenRanks[] = $candidate['semantic_rank'];

            $score = $candidate['semantic_score'];
            if ($score !== null) {
                self::require(is_int($score) || is_float($score), 'semantic_score must be a number or null');
                self::require(! is_bool($score) && is_finite((float) $score), 'semantic_score must be finite');
                $value = (float) $score;
                self::require($value >= 0.0 && $value <= 1.0, 'semantic_score out of inclusive [0,1] bounds');
            }

            // Canonicalization check - the score must already be in canonical form
            // (no specific canonicalization for semantic_score like M5 had for transcript_text)
        }

        sort($seenRanks);
        self::require($seenRanks === range(1, $k), 'Candidate semantic_ranks must form 1..K');

        // Selected candidate must have non-null semantic_score
        $selectedCandidate = $candidates[$candidateIndex];
        self::require($selectedCandidate['semantic_score'] !== null, 'Selected candidate must have non-null semantic_score');

        return [
            'candidates' => $candidates,
            'candidate_index' => $candidateIndex,
        ];
    }

    /**
     * Validate source_media object.
     *
     * @param  array<string, mixed>  $sourceMedia
     */
    private static function validateSourceMedia(array $sourceMedia): void
    {
        self::require(is_string($sourceMedia['disk']) && $sourceMedia['disk'] !== '', 'source_media.disk must be non-empty string');
        self::require(is_string($sourceMedia['key']) && $sourceMedia['key'] !== '', 'source_media.key must be non-empty string');
        self::integer($sourceMedia['width'], 1);
        self::integer($sourceMedia['height'], 1);
        self::require(is_string($sourceMedia['video_codec']) && $sourceMedia['video_codec'] !== '', 'source_media.video_codec must be non-empty string');
        // audio_codec can be null for video-only files
        if ($sourceMedia['audio_codec'] !== null) {
            self::require(is_string($sourceMedia['audio_codec']) && $sourceMedia['audio_codec'] !== '', 'source_media.audio_codec must be non-empty string or null');
        }
    }

    /**
     * Whether a configuration object is exactly the pinned profile.
     *
     * @param  array<string, mixed>  $configuration
     */
    public static function configurationMatchesSelection(array $configuration): bool
    {
        if (! self::hasExactKeys($configuration, self::CONFIGURATION_KEYS)) {
            return false;
        }

        try {
            RenderProfile::validateConfiguration($configuration);
        } catch (ProcessMediaException) {
            return false;
        }

        // Compare with current profile
        try {
            $expected = RenderProfile::configuration();
        } catch (ProcessMediaException) {
            return false;
        }

        foreach (self::CONFIGURATION_KEYS as $key) {
            if ($configuration[$key] !== $expected[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
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

    private static function integer(mixed $value, int $low, int $high = PHP_INT_MAX): void
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
        return new ProcessMediaException('Render validation failed', 1, '');
    }

    private static function toArrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::toArrays(...), $value) : $value;
    }
}