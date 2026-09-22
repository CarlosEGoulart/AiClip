<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Services\ProcessMediaAction;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

function preflightContract(): MediaProcessingContract
{
    $contract = new MediaProcessingContract;
    $contract->action = 'analyze_clips';
    $contract->mediaAssetId = 1;
    $contract->durationMs = 30000;
    $contract->scenes = [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]];
    $contract->configuration = [
        'min_duration_ms' => 5000, 'target_duration_ms' => 30000,
        'max_duration_ms' => 60000, 'max_candidates' => 20,
        'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
    ];

    return $contract;
}

it('rejects invalid clip requests before creating a process', function (string $property, mixed $value) {
    config(['logging.default' => 'null']);
    $contract = preflightContract();
    $contract->{$property} = $value;
    $action = new class extends ProcessMediaAction
    {
        public int $creations = 0;

        protected function createProcess(array $command): Process
        {
            $this->creations++;

            // A fixed process failure lets the assertion distinguish preflight
            // rejection from rejection only after crossing the process boundary.
            return new class(['recording-worker']) extends Process
            {
                public function run(?callable $callback = null, array $env = []): int
                {
                    return 1;
                }

                public function isSuccessful(): bool
                {
                    return false;
                }

                public function getExitCode(): ?int
                {
                    return 1;
                }
            };
        }
    };
    $failure = null;
    try {
        $action->analyzeClips($contract);
    } catch (ProcessMediaException $exception) {
        $failure = $exception;
    }
    $this->assertSame(0, $action->creations, 'Invalid clip input must be rejected before createProcess().');
    expect($failure)->toBeInstanceOf(ProcessMediaException::class);
    expect($failure->getPrevious())->toBeNull();
    expect($failure->getMessage())->not->toContain('PRIVATE_SENTINEL');
})->with(function () {
    yield 'unsupported minor' => ['version', '1.1.0'];
    yield 'unsupported major' => ['version', '2.0.0'];
    yield 'duration overflow' => ['durationMs', 2147483648];
    yield 'missing scenes' => ['scenes', null];
    yield 'scene map' => ['scenes', ['scene' => ['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]]];
    foreach (['index', 'start_ms', 'end_ms'] as $key) {
        foreach ([true, 1.0, '1', null] as $i => $bad) {
            $scenes = preflightContract()->scenes;
            $scenes[0][$key] = $bad;
            yield "scene $key strict type $i" => ['scenes', $scenes];
        }
        $scenes = preflightContract()->scenes;
        unset($scenes[0][$key]);
        yield "scene missing $key" => ['scenes', $scenes];
    }
    yield 'scene nonzero index' => ['scenes', [['index' => 1, 'start_ms' => 0, 'end_ms' => 30000]]];
    yield 'scene negative start' => ['scenes', [['index' => 0, 'start_ms' => -1, 'end_ms' => 30000]]];
    yield 'scene empty interval' => ['scenes', [['index' => 0, 'start_ms' => 0, 'end_ms' => 0]]];
    yield 'scene exceeds duration' => ['scenes', [['index' => 0, 'start_ms' => 0, 'end_ms' => 30001]]];
    yield 'scene overlap' => ['scenes', [['index' => 0, 'start_ms' => 0, 'end_ms' => 10], ['index' => 1, 'start_ms' => 9, 'end_ms' => 20]]];
    yield 'scene private extra' => ['scenes', [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000, 'text' => 'PRIVATE_SENTINEL']]];
    yield 'scene count overflow' => ['scenes', array_map(fn ($i) => ['index' => $i, 'start_ms' => $i, 'end_ms' => $i + 1], range(0, 10000))];
    yield 'transcript map' => ['transcriptSegments', ['segment' => ['start_ms' => 0, 'end_ms' => 1]]];
    foreach (['start_ms', 'end_ms'] as $key) {
        foreach ([true, 1.0, '1', null] as $i => $bad) {
            $segments = [['start_ms' => 0, 'end_ms' => 10]];
            $segments[0][$key] = $bad;
            yield "transcript $key strict type $i" => ['transcriptSegments', $segments];
        }
        $segments = [['start_ms' => 0, 'end_ms' => 10]];
        unset($segments[0][$key]);
        yield "transcript missing $key" => ['transcriptSegments', $segments];
    }
    yield 'transcript reversed' => ['transcriptSegments', [['start_ms' => 10, 'end_ms' => 9]]];
    yield 'transcript outside duration' => ['transcriptSegments', [['start_ms' => 0, 'end_ms' => 30001]]];
    yield 'transcript overlap' => ['transcriptSegments', [['start_ms' => 0, 'end_ms' => 10], ['start_ms' => 9, 'end_ms' => 20]]];
    yield 'transcript private extra' => ['transcriptSegments', [['start_ms' => 0, 'end_ms' => 10, 'text' => 'PRIVATE_SENTINEL']]];
    yield 'transcript count overflow' => ['transcriptSegments', array_fill(0, 50001, ['start_ms' => 0, 'end_ms' => 0])];
    foreach (['min_duration_ms', 'target_duration_ms', 'max_duration_ms', 'max_candidates'] as $key) {
        foreach ([true, 1.0, '1', null, 0] as $i => $bad) {
            $config = preflightContract()->configuration;
            $config[$key] = $bad;
            yield "configuration $key strict value $i" => ['configuration', $config];
        }
        $config = preflightContract()->configuration;
        unset($config[$key]);
        yield "configuration missing $key" => ['configuration', $config];
    }
    foreach (['duration_fit', 'speech_coverage', 'boundary_alignment'] as $key) {
        foreach ([true, 1.0, '1', null, -1, 10001] as $i => $bad) {
            $config = preflightContract()->configuration;
            $config['weights'][$key] = $bad;
            yield "weight $key strict value $i" => ['configuration', $config];
        }
        $config = preflightContract()->configuration;
        unset($config['weights'][$key]);
        yield "weight missing $key" => ['configuration', $config];
    }
    foreach (['min_duration_ms' => 30001, 'max_duration_ms' => 29999, 'max_candidates' => 1001, 'private' => 'PRIVATE_SENTINEL'] as $key => $bad) {
        $config = preflightContract()->configuration;
        $config[$key] = $bad;
        yield "configuration relation or extra $key" => ['configuration', $config];
    }
    $config = preflightContract()->configuration;
    $config['weights']['duration_fit'] = 0;
    yield 'zero duration weight' => ['configuration', $config];
    $config['weights']['duration_fit'] = 50;
    $config['weights']['private'] = 'PRIVATE_SENTINEL';
    yield 'unknown weight' => ['configuration', $config];
});

it('accepts strict clip request boundary controls without coercion', function () {
    $contract = preflightContract();
    expect($contract->validate())->toBeTrue();
    $contract->scenes = [];
    $contract->transcriptSegments = [];
    expect($contract->validate())->toBeTrue();
    $contract->scenes = array_map(fn ($i) => ['index' => $i, 'start_ms' => $i, 'end_ms' => $i + 1], range(0, 9999));
    $contract->transcriptSegments = array_fill(0, 50000, ['start_ms' => 0, 'end_ms' => 0]);
    expect($contract->validate())->toBeTrue();
});
