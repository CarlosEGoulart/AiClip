<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaClipAnalysis;
use App\Services\ClipAnalysisValidator;
use Tests\Support\ClipAnalysisFixture as Fixture;
use Tests\TestCase;

uses(TestCase::class);

function emptyCompletionModel(): MediaClipAnalysis
{
    $model = new class extends MediaClipAnalysis
    {
        public array $writes = [];

        public function update(array $attributes = [], array $options = []): bool
        {
            $this->writes[] = $attributes;

            return true;
        }
    };
    $model->status = MediaClipAnalysis::STATUS_ANALYZING;

    return $model;
}

function emptyCompletionConfig(): array
{
    return [
        'min_duration_ms' => 5000, 'target_duration_ms' => 30000,
        'max_duration_ms' => 60000, 'max_candidates' => 20,
        'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
    ];
}

function emptyCompletionProvenance(array $configuration, bool $transcriptUsed): array
{
    return [
        'configuration' => $configuration,
        'effective_weights' => $transcriptUsed
            ? ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20]
            : ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
        'transcript_used' => $transcriptUsed,
        'candidate_policy' => 'whole_scene_non_overlapping',
        'timing_policy' => 'original_media_ms',
        'transcript_policy' => 'optional_strict_unshifted',
        'boundary_policy' => 'strict_interior_speech_cut',
        'score_scale' => 1000000, 'rounding' => 'half_up',
        'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
    ];
}

it('validates direct model completion before any update', function (string $kind) {
    $model = new class extends MediaClipAnalysis
    {
        public array $writes = [];

        public function update(array $attributes = [], array $options = []): bool
        {
            $this->writes[] = $attributes;

            return true;
        }
    };
    $model->status = MediaClipAnalysis::STATUS_ANALYZING;
    $analysis = Fixture::response()['analysis'];
    $request = Fixture::contract()->toMetadataArray();
    $snapshot = ['duration_ms' => $request['media']['duration_ms'], 'scenes' => $request['scenes'],
        'transcript_segments' => $request['transcript_segments'], 'configuration' => $request['configuration']];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];
    switch ($kind) {
        case 'version': $analysis['algorithm_version'] = '9.9.9';
            break;
        case 'score': $analysis['candidates'][0]['score'] = 0.9;
            break;
        case 'nan': $analysis['candidates'][0]['score'] = NAN;
            break;
        case 'infinity': $analysis['candidates'][0]['criteria']['duration_fit'] = INF;
            break;
        case 'empty': $analysis['candidates'] = [];
            break;
        case 'source': $analysis['candidates'][0]['source_scene_indexes'] = [1];
            break;
        case 'rank': $analysis['candidates'][0]['rank'] = 2;
            break;
        case 'snapshot null': $snapshot['transcript_segments'] = null;
            break;
        case 'snapshot extra': $snapshot['text'] = 'PRIVATE_SENTINEL';
            break;
        case 'timeout': $execution['timeout_seconds'] = 0;
            break;
        case 'lock wait': $execution['lock_wait_seconds'] = 34;
            break;
    }
    $failure = null;
    try {
        $model->markCompleted($analysis['algorithm'], $analysis['algorithm_version'], $analysis['parameters'],
            $analysis['candidates'], $snapshot, $execution);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    if ($kind === 'valid') {
        expect($failure)->toBeNull();
        expect($model->writes)->toHaveCount(1);
        expect($model->writes[0]['status'])->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        expect($model->writes[0]['candidates'])->toBe($analysis['candidates']);
    } else {
        $this->assertSame([], $model->writes, 'Invalid completion must be rejected before any model update.');
        expect($failure)->toBeInstanceOf(ProcessMediaException::class);
        expect($failure->getPrevious())->toBeNull();
        expect($failure->getMessage())->not->toContain('PRIVATE_SENTINEL');
    }
})->with(['valid', 'version', 'score', 'nan', 'infinity', 'empty', 'source', 'rank', 'snapshot null', 'snapshot extra', 'timeout', 'lock wait']);

it('completes authoritative empty scenes with empty candidates', function () {
    $model = emptyCompletionModel();
    $configuration = emptyCompletionConfig();
    $snapshot = ['duration_ms' => 30000, 'scenes' => [], 'configuration' => $configuration];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

    $failure = null;
    try {
        $model->markCompleted(
            'scene_timing_baseline', '1.0.0',
            emptyCompletionProvenance($configuration, false), [],
            $snapshot, $execution
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeNull('valid eligible-empty completion must succeed');
    expect($model->writes)->toHaveCount(1, 'exactly one complete update must persist');
    expect($model->writes[0]['status'])->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($model->writes[0]['candidates'])->toBe([]);
    expect($model->writes[0]['error'])->toBeNull();
    expect($model->writes[0]['input_snapshot'])->toBe($snapshot);
    expect($model->writes[0]['execution_parameters'])->toBe($execution);
});

it('completes all-ineligible scenes below minimum and above maximum', function () {
    $model = emptyCompletionModel();
    $configuration = emptyCompletionConfig();
    $scenes = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 3000],
        ['index' => 1, 'start_ms' => 3000, 'end_ms' => 70000],
    ];
    $snapshot = [
        'duration_ms' => 120000, 'scenes' => $scenes,
        'transcript_segments' => [['start_ms' => 500, 'end_ms' => 1500]],
        'configuration' => $configuration,
    ];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

    $failure = null;
    try {
        $model->markCompleted(
            'scene_timing_baseline', '1.0.0',
            emptyCompletionProvenance($configuration, true), [],
            $snapshot, $execution
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeNull('below-minimum and above-maximum filtering must yield a valid empty completion');
    expect($model->writes)->toHaveCount(1, 'exactly one complete update must persist');
    expect($model->writes[0]['status'])->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($model->writes[0]['candidates'])->toBe([]);
    expect($model->writes[0]['error'])->toBeNull();
    expect($model->writes[0]['input_snapshot'])->toBe($snapshot);
});

it('completes explicitly present empty transcript segments as transcript-used', function () {
    $model = emptyCompletionModel();
    $configuration = emptyCompletionConfig();
    $snapshot = [
        'duration_ms' => 30000, 'scenes' => [],
        'transcript_segments' => [],
        'configuration' => $configuration,
    ];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

    $failure = null;
    try {
        $model->markCompleted(
            'scene_timing_baseline', '1.0.0',
            emptyCompletionProvenance($configuration, true), [],
            $snapshot, $execution
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeNull('present-but-empty transcript segments must complete as transcript-used');
    expect($model->writes)->toHaveCount(1, 'exactly one complete update must persist');
    expect($model->writes[0]['candidates'])->toBe([]);
    expect($model->writes[0]['error'])->toBeNull();
});

it('completes when legitimate configuration removes all eligibility', function () {
    $model = emptyCompletionModel();
    $configuration = [
        'min_duration_ms' => 40000, 'target_duration_ms' => 50000,
        'max_duration_ms' => 60000, 'max_candidates' => 20,
        'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
    ];
    $snapshot = [
        'duration_ms' => 30000,
        'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]],
        'configuration' => $configuration,
    ];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

    $failure = null;
    try {
        $model->markCompleted(
            'scene_timing_baseline', '1.0.0',
            emptyCompletionProvenance($configuration, false), [],
            $snapshot, $execution
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeNull('a legitimate configuration with no eligible scenes must complete empty');
    expect($model->writes)->toHaveCount(1, 'exactly one complete update must persist');
    expect($model->writes[0]['status'])->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($model->writes[0]['candidates'])->toBe([]);
    expect($model->writes[0]['error'])->toBeNull();
});

it('rejects empty candidates when scenes are eligible', function () {
    $model = emptyCompletionModel();
    $configuration = emptyCompletionConfig();
    $snapshot = [
        'duration_ms' => 30000,
        'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]],
        'configuration' => $configuration,
    ];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

    $failure = null;
    try {
        $model->markCompleted(
            'scene_timing_baseline', '1.0.0',
            emptyCompletionProvenance($configuration, false), [],
            $snapshot, $execution
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class);
    expect($failure->getPrevious())->toBeNull();
    $this->assertSame([], $model->writes, 'Fabricated empty must be rejected before any model update.');
});

it('rejects mismatched provenance with empty candidates', function () {
    $model = emptyCompletionModel();
    $configuration = emptyCompletionConfig();
    $snapshot = ['duration_ms' => 30000, 'scenes' => [], 'configuration' => $configuration];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

    $failure = null;
    try {
        $model->markCompleted(
            'scene_timing_baseline', '1.0.0',
            emptyCompletionProvenance($configuration, true), [],
            $snapshot, $execution
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class);
    expect($failure->getPrevious())->toBeNull();
    $this->assertSame([], $model->writes, 'Provenance mismatch must fail closed before any model update.');
});

it('rejects invalid empty-path inputs before any model update', function (string $kind) {
    $model = emptyCompletionModel();
    $configuration = emptyCompletionConfig();
    $snapshot = ['duration_ms' => 30000, 'scenes' => [], 'configuration' => $configuration];
    $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];
    $algorithm = 'scene_timing_baseline';
    $candidates = [];

    switch ($kind) {
        case 'duration': $snapshot['duration_ms'] = 0;
            break;
        case 'scene order': $snapshot['scenes'] = [['index' => 0, 'start_ms' => 5000, 'end_ms' => 1000]];
            break;
        case 'null transcript': $snapshot['transcript_segments'] = null;
            break;
        case 'algorithm': $algorithm = 'other';
            break;
        case 'null candidates': $candidates = null;
            break;
        case 'object candidates': $candidates = ['unexpected' => 'object'];
            break;
        case 'lock wait': $execution['lock_wait_seconds'] = 34;
            break;
    }

    $failure = null;
    try {
        $model->markCompleted($algorithm, '1.0.0', emptyCompletionProvenance($configuration, false), $candidates, $snapshot, $execution);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class);
    expect($failure->getPrevious())->toBeNull();
    $this->assertSame([], $model->writes, 'Invalid empty-path input must be rejected before any model update.');
})->with(['duration', 'scene order', 'null transcript', 'algorithm', 'object candidates', 'lock wait']);

it('passes eligible-empty metadata through the action boundary unchanged', function () {
    $configuration = emptyCompletionConfig();
    $request = [
        'version' => '1.0.0', 'action' => 'analyze_clips',
        'media' => ['duration_ms' => 30000], 'scenes' => [],
        'configuration' => $configuration,
    ];
    $result = json_decode(json_encode([
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline', 'algorithm_version' => '1.0.0',
            'parameters' => emptyCompletionProvenance($configuration, false),
            'candidates' => [],
        ],
    ]));

    expect(ClipAnalysisValidator::result($result, $request))->toBe([
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline', 'algorithm_version' => '1.0.0',
            'parameters' => emptyCompletionProvenance($configuration, false),
            'candidates' => [],
        ],
    ]);
});

it('rejects invalid operational timeout before process creation', function (mixed $timeout) {
    config(['media.clip_analysis_timeout_seconds' => $timeout, 'logging.default' => 'null']);
    $action = Fixture::action(json_encode(Fixture::response(), JSON_THROW_ON_ERROR));
    $failure = null;
    try {
        $action->analyzeClips(Fixture::contract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertSame(0, $action->creations, 'Invalid timeout must fail before process creation.');
    expect($failure)->toBeInstanceOf(ProcessMediaException::class);
})->with([0, -1, 121, true, 30.0, '30', null]);
