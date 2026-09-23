<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;
use stdClass;

/** Independent PHP trust boundary for the metadata-only clip action. */
final class ClipAnalysisValidator
{
    public const MAX_DURATION = 2147483647;

    public const MAX_SCENES = 10000;

    public const MAX_SEGMENTS = 50000;

    private const SCALE = 1000000;

    public static function result(mixed $result, array $request): array
    {
        $input = self::request($request);
        self::exact($result, ['status' => 'success', 'analysis' => self::expectedAnalysis($input)]);

        return self::toArrays($result);
    }

    private static function expectedAnalysis(array $input): array
    {
        $config = $input['configuration'];
        $used = array_key_exists('transcript_segments', $input);
        $weights = $used ? $config['weights'] : ['duration_fit' => $config['weights']['duration_fit'], 'speech_coverage' => 0, 'boundary_alignment' => 0];
        $parameters = [
            'configuration' => $config, 'effective_weights' => $weights, 'transcript_used' => $used,
            'candidate_policy' => 'whole_scene_non_overlapping', 'timing_policy' => 'original_media_ms',
            'transcript_policy' => 'optional_strict_unshifted', 'boundary_policy' => 'strict_interior_speech_cut',
            'score_scale' => self::SCALE, 'rounding' => 'half_up',
            'limits' => ['max_scenes' => self::MAX_SCENES, 'max_transcript_segments' => self::MAX_SEGMENTS,
                'max_input_bytes' => 8388608, 'max_duration_ms' => self::MAX_DURATION],
        ];
        $segments = $input['transcript_segments'] ?? [];
        $position = 0;
        $covered = 0;
        // Ordered prefix sweep: each transcript segment is consumed at most once.
        $at = static function (int $boundary) use ($segments, &$position, &$covered): array {
            while ($position < count($segments) && $segments[$position]['end_ms'] <= $boundary) {
                $covered += $segments[$position]['end_ms'] - $segments[$position]['start_ms'];
                $position++;
            }
            if ($position === count($segments)) {
                return [$covered, true];
            }
            $segment = $segments[$position];

            return [$covered + max(0, $boundary - $segment['start_ms']),
                ! ($segment['start_ms'] < $boundary && $boundary < $segment['end_ms'])];
        };
        $eligible = [];
        foreach ($input['scenes'] as $scene) {
            $length = $scene['end_ms'] - $scene['start_ms'];
            if ($length < $config['min_duration_ms'] || $length > $config['max_duration_ms']) {
                continue;
            }
            $duration = self::quantize(min($length, $config['target_duration_ms']), max($length, $config['target_duration_ms']));
            $speech = $boundary = 0;
            if ($used) {
                [$startCoverage, $startSafe] = $at($scene['start_ms']);
                [$endCoverage, $endSafe] = $at($scene['end_ms']);
                $speech = self::quantize($endCoverage - $startCoverage, $length);
                $boundary = self::quantize((int) $startSafe + (int) $endSafe, 2);
            }
            $total = $weights['duration_fit'] * $duration + $weights['speech_coverage'] * $speech + $weights['boundary_alignment'] * $boundary;
            $denominator = array_sum($weights);
            $units = intdiv(2 * $total + $denominator, 2 * $denominator);
            $eligible[] = [
                'index' => 0, 'start_ms' => $scene['start_ms'], 'end_ms' => $scene['end_ms'], 'rank' => 0,
                'score' => (float) ($units / self::SCALE),
                'criteria' => ['duration_fit' => (float) ($duration / self::SCALE),
                    'speech_coverage' => (float) ($speech / self::SCALE), 'boundary_alignment' => (float) ($boundary / self::SCALE)],
                'source_scene_indexes' => [$scene['index']], '_units' => $units,
            ];
        }
        usort($eligible, static fn ($a, $b) => [-$a['_units'], $a['start_ms'], $a['end_ms'], $a['source_scene_indexes'][0]]
            <=> [-$b['_units'], $b['start_ms'], $b['end_ms'], $b['source_scene_indexes'][0]]);
        $selected = array_slice($eligible, 0, $config['max_candidates']);
        foreach ($selected as $i => &$candidate) {
            $candidate['rank'] = $i + 1;
            unset($candidate['_units']);
        }
        unset($candidate);
        usort($selected, static fn ($a, $b) => [$a['start_ms'], $a['end_ms'], $a['source_scene_indexes'][0]]
            <=> [$b['start_ms'], $b['end_ms'], $b['source_scene_indexes'][0]]);
        foreach ($selected as $i => &$candidate) {
            $candidate['index'] = $i;
        }
        unset($candidate);

        return ['algorithm' => 'scene_timing_baseline', 'algorithm_version' => '1.0.0', 'parameters' => $parameters, 'candidates' => $selected];
    }

    private static function quantize(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator * self::SCALE + $denominator, 2 * $denominator);
    }

    private static function exact(mixed $actual, mixed $expected): void
    {
        if (is_array($expected)) {
            if (array_is_list($expected)) {
                self::require(is_array($actual) && array_is_list($actual) && count($actual) === count($expected));
            } else {
                $actual = self::fields($actual, array_keys($expected));
            }
            foreach ($expected as $key => $value) {
                self::exact($actual[$key], $value);
            }
        } elseif (is_float($expected)) {
            self::require((is_int($actual) || is_float($actual)) && is_finite((float) $actual)
                && $actual >= 0 && $actual <= 1 && abs($actual - $expected) <= 1e-9);
        } else {
            self::require($actual === $expected);
        }
    }

    private static function toArrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::toArrays(...), $value) : $value;
    }

    public static function request(mixed $request): array
    {
        $data = self::fields($request, ['version', 'action', 'media', 'scenes', 'configuration'], ['transcript_segments']);
        self::require($data['version'] === '1.0.0' && $data['action'] === 'analyze_clips');
        $media = self::fields($data['media'], ['duration_ms']);
        self::integer($media['duration_ms'], 1, self::MAX_DURATION);
        $data['media'] = $media;
        $data['scenes'] = self::intervals($data['scenes'], $media['duration_ms'], true);
        if (array_key_exists('transcript_segments', $data)) {
            $data['transcript_segments'] = self::intervals($data['transcript_segments'], $media['duration_ms'], false);
        }
        $configuration = self::fields($data['configuration'], ['min_duration_ms', 'target_duration_ms', 'max_duration_ms', 'max_candidates', 'weights']);
        foreach (['min_duration_ms', 'target_duration_ms', 'max_duration_ms'] as $key) {
            self::integer($configuration[$key], 1, self::MAX_DURATION);
        }
        self::require($configuration['min_duration_ms'] <= $configuration['target_duration_ms']
            && $configuration['target_duration_ms'] <= $configuration['max_duration_ms']);
        self::integer($configuration['max_candidates'], 1, 1000);
        $weights = self::fields($configuration['weights'], ['duration_fit', 'speech_coverage', 'boundary_alignment']);
        foreach ($weights as $key => $value) {
            self::integer($value, $key === 'duration_fit' ? 1 : 0, 10000);
        }
        $configuration['weights'] = $weights;
        $data['configuration'] = $configuration;

        return $data;
    }

    private static function intervals(mixed $items, int $duration, bool $scenes): array
    {
        self::require(is_array($items) && array_is_list($items)
            && count($items) <= ($scenes ? self::MAX_SCENES : self::MAX_SEGMENTS));
        $end = 0;
        $result = [];
        foreach ($items as $index => $item) {
            $interval = self::fields($item, $scenes ? ['index', 'start_ms', 'end_ms'] : ['start_ms', 'end_ms']);
            self::integer($interval['start_ms'], $end, $duration);
            self::integer($interval['end_ms'], $interval['start_ms'] + ($scenes ? 1 : 0), $duration);
            if ($scenes) {
                self::require($interval['index'] === $index);
            }
            $end = $interval['end_ms'];
            $result[] = $interval;
        }

        return $result;
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
        self::require(is_int($value) && $value >= $low && $value <= $high);
    }

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new ProcessMediaException('Invalid clip analysis contract');
        }
    }

    /**
     * Validate completion data against independent rederivation.
     *
     * @param  array{algorithm: string, algorithm_version: string, parameters: array, candidates: array}  $analysis
     * @param  array{duration_ms: int, scenes: array, transcript_segments?: array, configuration: array}  $inputSnapshot
     * @param  array{timeout_seconds: int, lock_wait_seconds: int}  $executionParameters
     *
     * @throws ProcessMediaException
     */
    public static function validateCompletion(array $analysis, array $inputSnapshot, array $executionParameters): void
    {
        // Validate algorithm and version
        if (($analysis['algorithm'] ?? '') !== 'scene_timing_baseline') {
            throw new ProcessMediaException('Invalid algorithm');
        }
        if (($analysis['algorithm_version'] ?? '') !== '1.0.0') {
            throw new ProcessMediaException('Invalid algorithm version');
        }

        // Validate parameters structure
        $requiredParams = ['configuration', 'effective_weights', 'transcript_used', 'candidate_policy',
            'timing_policy', 'transcript_policy', 'boundary_policy', 'score_scale', 'rounding', 'limits'];
        foreach ($requiredParams as $key) {
            if (! array_key_exists($key, $analysis['parameters'] ?? [])) {
                throw new ProcessMediaException('Invalid parameters structure');
            }
        }

        // Validate candidate presence and list shape before any rederivation.
        // Missing, null, non-array, or non-list candidates are never a valid
        // empty completion.
        if (! array_key_exists('candidates', $analysis)
            || ! is_array($analysis['candidates'])
            || ! array_is_list($analysis['candidates'])
        ) {
            throw new ProcessMediaException('Invalid candidates');
        }
        $candidates = $analysis['candidates'];

        // Independent rederivation from input snapshot
        $request = [
            'version' => '1.0.0',
            'action' => 'analyze_clips',
            'media' => ['duration_ms' => $inputSnapshot['duration_ms']],
            'scenes' => $inputSnapshot['scenes'],
            'configuration' => $inputSnapshot['configuration'],
        ];
        if (array_key_exists('transcript_segments', $inputSnapshot)) {
            $request['transcript_segments'] = $inputSnapshot['transcript_segments'];
        }
        $validatedRequest = self::request($request);
        $expectedAnalysis = self::expectedAnalysis($validatedRequest);
        $expectedCandidates = $expectedAnalysis['candidates'];

        if (count($candidates) !== count($expectedCandidates)) {
            throw new ProcessMediaException('Candidate count mismatch');
        }

        if ($candidates === []) {
            // Eligible-empty completion: the claimed provenance must equal
            // the independently rederived expectations in full. Remaining
            // snapshot/privacy/execution checks below still run.
            if (($analysis['parameters'] ?? []) != $expectedAnalysis['parameters']) {
                throw new ProcessMediaException('Invalid parameters structure');
            }
        }

        foreach ($candidates as $index => $candidate) {
            if (! isset($candidate['index'], $candidate['start_ms'], $candidate['end_ms'], $candidate['rank'],
                $candidate['score'], $candidate['criteria'], $candidate['source_scene_indexes'])) {
                throw new ProcessMediaException("Invalid candidate structure at index {$index}");
            }

            if ($candidate['index'] !== $index) {
                throw new ProcessMediaException("Candidate index mismatch at index {$index}");
            }

            if ($candidate['rank'] !== $index + 1) {
                throw new ProcessMediaException("Invalid rank at index {$index}");
            }

            if (! is_numeric($candidate['score']) || ! is_finite((float) $candidate['score']) || $candidate['score'] < 0 || $candidate['score'] > 1) {
                throw new ProcessMediaException("Invalid score at index {$index}");
            }

            // Verify score matches independent rederivation (allow 1e-9 tolerance)
            if (abs($candidate['score'] - $expectedCandidates[$index]['score']) > 1e-9) {
                throw new ProcessMediaException("Score mismatch at index {$index}");
            }

            $criteria = $candidate['criteria'];
            if (! isset($criteria['duration_fit'], $criteria['speech_coverage'], $criteria['boundary_alignment'])) {
                throw new ProcessMediaException("Invalid criteria at index {$index}");
            }
            foreach (['duration_fit', 'speech_coverage', 'boundary_alignment'] as $key) {
                if (! is_numeric($criteria[$key]) || ! is_finite((float) $criteria[$key]) || $criteria[$key] < 0 || $criteria[$key] > 1) {
                    throw new ProcessMediaException("Invalid {$key} at index {$index}");
                }
                if (abs($criteria[$key] - $expectedCandidates[$index]['criteria'][$key]) > 1e-9) {
                    throw new ProcessMediaException("Criteria {$key} mismatch at index {$index}");
                }
            }

            if (! is_array($candidate['source_scene_indexes']) || empty($candidate['source_scene_indexes'])) {
                throw new ProcessMediaException("Invalid source_scene_indexes at index {$index}");
            }
            if ($candidate['source_scene_indexes'] !== $expectedCandidates[$index]['source_scene_indexes']) {
                throw new ProcessMediaException("Source scene indexes mismatch at index {$index}");
            }

            // Check chronological ordering and non-overlapping
            if ($index > 0) {
                $prev = $candidates[$index - 1];
                if ($candidate['start_ms'] < $prev['end_ms']) {
                    throw new ProcessMediaException("Overlapping candidates at index {$index}");
                }
                if ($candidate['start_ms'] < $prev['start_ms']) {
                    throw new ProcessMediaException("Non-chronological candidates at index {$index}");
                }
            }
        }

        // Validate input snapshot
        if (! isset($inputSnapshot['duration_ms'], $inputSnapshot['scenes'], $inputSnapshot['configuration'])) {
            throw new ProcessMediaException('Invalid input snapshot structure');
        }

        // transcript_segments in snapshot must be array if present, not null
        if (array_key_exists('transcript_segments', $inputSnapshot) && $inputSnapshot['transcript_segments'] === null) {
            throw new ProcessMediaException('Snapshot transcript_segments must not be null');
        }

        // No extra privacy fields in snapshot
        $allowedSnapshotKeys = ['duration_ms', 'scenes', 'transcript_segments', 'configuration'];
        foreach (array_keys($inputSnapshot) as $key) {
            if (! in_array($key, $allowedSnapshotKeys, true)) {
                throw new ProcessMediaException("Unknown snapshot field: {$key}");
            }
        }

        // Validate execution parameters
        if (! isset($executionParameters['timeout_seconds'], $executionParameters['lock_wait_seconds'])) {
            throw new ProcessMediaException('Invalid execution parameters structure');
        }
        if (! is_int($executionParameters['timeout_seconds']) || $executionParameters['timeout_seconds'] <= 0 || $executionParameters['timeout_seconds'] > 120) {
            throw new ProcessMediaException('Invalid timeout_seconds');
        }
        $expectedLockWait = $executionParameters['timeout_seconds'] + 5;
        if (! is_int($executionParameters['lock_wait_seconds']) || $executionParameters['lock_wait_seconds'] !== $expectedLockWait) {
            throw new ProcessMediaException('Invalid lock_wait_seconds');
        }
    }
}
