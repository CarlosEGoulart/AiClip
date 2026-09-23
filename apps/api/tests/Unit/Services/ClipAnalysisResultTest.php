<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProcessMediaException;
use Tests\Support\ClipAnalysisFixture as Fixture;
use Tests\TestCase;

uses(TestCase::class);

it('rejects malformed success independently at the real PHP action', function (array $path, mixed $value, bool $remove = false) {
    config(['logging.default' => 'null']);
    $response = Fixture::response();
    $target = &$response;
    foreach (array_slice($path, 0, -1) as $key) {
        $target = &$target[$key];
    }
    $key = $path[count($path) - 1];
    if ($remove) {
        unset($target[$key]);
    } else {
        $target[$key] = $value;
    }
    $failure = null;
    try {
        Fixture::action(json_encode($response, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->analyzeClips(Fixture::contract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertInstanceOf(ProcessMediaException::class, $failure, 'Malformed worker success must not be accepted.');
    expect($failure->getMessage())->toBe('Clip analysis failed');
    expect($failure->getPrevious())->toBeNull();
})->with(function () {
    $base = Fixture::response();
    $objects = [[], ['analysis'], ['analysis', 'parameters'], ['analysis', 'parameters', 'configuration'],
        ['analysis', 'parameters', 'configuration', 'weights'], ['analysis', 'parameters', 'effective_weights'],
        ['analysis', 'parameters', 'limits'], ['analysis', 'candidates', 0], ['analysis', 'candidates', 0, 'criteria']];
    foreach ($objects as $path) {
        $object = $base;
        foreach ($path as $key) {
            $object = $object[$key];
        }
        foreach ($object as $key => $value) {
            yield 'missing '.implode('.', [...$path, $key]) => [[...$path, $key], null, true];
            yield 'null '.implode('.', [...$path, $key]) => [[...$path, $key], null];
        }
        yield 'extra '.implode('.', $path) => [[...$path, 'private'], 'PRIVATE_SENTINEL'];
    }
    foreach (['algorithm' => 'other', 'algorithm_version' => '9.9.9', 'parameters' => [], 'candidates' => (object) []] as $key => $bad) {
        yield "analysis wrong $key" => [['analysis', $key], $bad];
    }
    foreach (['index', 'start_ms', 'end_ms', 'rank'] as $key) {
        foreach ([true, 1.0, '1', -1] as $i => $bad) {
            yield "candidate $key type $i" => [['analysis', 'candidates', 0, $key], $bad];
        }
    }
    foreach ([['score'], ['criteria', 'duration_fit'], ['criteria', 'speech_coverage'], ['criteria', 'boundary_alignment']] as $path) {
        foreach ([true, '0.75', -0.1, 1.1, 0.7512345] as $i => $bad) {
            yield 'numeric '.implode('.', $path)." $i" => [['analysis', 'candidates', 0, ...$path], $bad];
        }
    }
    foreach ([[], (object) [], [0, 0], [1], [true], [0.0], ['0'], [99]] as $i => $bad) {
        yield "source $i" => [['analysis', 'candidates', 0, 'source_scene_indexes'], $bad];
    }
    yield 'fabricated empty result' => [['analysis', 'candidates'], []];
    yield 'missing eligible result' => [['analysis', 'candidates'], [$base['analysis']['candidates'][0]]];
    yield 'duplicate candidates' => [['analysis', 'candidates'], [$base['analysis']['candidates'][0], $base['analysis']['candidates'][0]]];
    yield 'wrong chronology' => [['analysis', 'candidates'], array_reverse($base['analysis']['candidates'])];
    $ranked = $base['analysis']['candidates'];
    $ranked[0]['rank'] = 2;
    $ranked[1]['rank'] = 1;
    yield 'plausible wrong tie break' => [['analysis', 'candidates'], $ranked];
    $selected = $base['analysis']['candidates'];
    $selected[1] = ['index' => 1, 'start_ms' => 20000, 'end_ms' => 40000, 'rank' => 2, 'score' => 0.6,
        'criteria' => ['duration_fit' => 0.5, 'speech_coverage' => 0.5, 'boundary_alignment' => 1], 'source_scene_indexes' => [2]];
    yield 'plausible wrong top K' => [['analysis', 'candidates'], $selected];
    yield 'fabricated in-range score' => [['analysis', 'candidates', 0, 'score'], 0.8];
    yield 'extra meaningful precision' => [['analysis', 'candidates', 0, 'score'], 0.75000001];
    yield 'changed configuration' => [['analysis', 'parameters', 'configuration', 'max_candidates'], 3];
    yield 'wrong availability' => [['analysis', 'parameters', 'transcript_used'], false];
    yield 'wrong policy' => [['analysis', 'parameters', 'candidate_policy'], 'other'];
    yield 'wrong scale' => [['analysis', 'parameters', 'score_scale'], 1000];
});

it('accepts independently hand-derived golden analysis', function () {
    config(['logging.default' => 'null']);
    $response = Fixture::response();
    expect(Fixture::action(json_encode($response, JSON_THROW_ON_ERROR))->analyzeClips(Fixture::contract()))->toBe($response);
});

it('sanitizes transport errors without chained sensitive exceptions', function (string $raw, bool $throws, string $expected) {
    config(['logging.default' => 'null']);
    $failure = null;
    try {
        Fixture::action($raw, $throws ? new \RuntimeException('PRIVATE_SENTINEL') : null)->analyzeClips(Fixture::contract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe($expected);
    expect($failure->getPrevious())->toBeNull();
    expect($failure->stderr)->toBe('');
})->with([
    'malformed JSON' => ['PRIVATE_SENTINEL', false, 'Clip analysis failed'],
    'trailing output' => ['{"status":"success"} PRIVATE_SENTINEL', false, 'Clip analysis failed'],
    'nonfinite' => ['{"status":"success","analysis":{"score":NaN}}', false, 'Clip analysis failed'],
    'overflow' => ['{"status":"success","analysis":{"score":1e9999}}', false, 'Clip analysis failed'],
    'unexpected runtime abort' => ['', true, 'clip_analysis_aborted'],
]);

it('rejects candidate timing beyond persisted duration and negative values', function (string $key, mixed $value) {
    config(['logging.default' => 'null']);
    $response = Fixture::response();
    $response['analysis']['candidates'][0][$key] = $value;
    $failure = null;
    try {
        Fixture::action(json_encode($response, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->analyzeClips(Fixture::contract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe('Clip analysis failed');
    expect($failure->getPrevious())->toBeNull();
})->with([
    'start_ms negative' => ['start_ms', -1000],
    'start_ms beyond duration' => ['start_ms', 50000],
    'end_ms negative' => ['end_ms', -1000],
    'end_ms beyond duration' => ['end_ms', 50000],
    'end_ms <= start_ms' => ['end_ms', 0],
]);

it('rejects invalid rank values and gaps', function (string $key, mixed $value) {
    config(['logging.default' => 'null']);
    $response = Fixture::response();
    $response['analysis']['candidates'][0][$key] = $value;
    $failure = null;
    try {
        Fixture::action(json_encode($response, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->analyzeClips(Fixture::contract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe('Clip analysis failed');
    expect($failure->getPrevious())->toBeNull();
})->with([
    'rank zero' => ['rank', 0],
    'rank negative' => ['rank', -1],
    'rank fractional' => ['rank', 1.5],
    'rank string' => ['rank', '1'],
    'rank boolean' => ['rank', true],
    'rank above K' => ['rank', 3],
]);
