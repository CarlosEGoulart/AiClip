<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Services\ClipRecommendationValidator;
use App\Services\ProcessMediaAction;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function goldenRankingResponse(): array
{
    return [
        'status' => 'success',
        'ranking' => [
            'algorithm' => 'cross_encoder_reranker',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
                'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
                'model_revision' => 'main',
                'provider_name' => 'cross_encoder_ranking_provider',
                'transcript_used' => true,
                'normalization' => 'sigmoid',
                'score_scale' => 1.0,
                'tie_break' => 'm4_rank_then_chronological',
            ],
            'recommendations' => [
                ['m4_candidate_index' => 0, 'semantic_score' => 0.872341, 'combined_rank' => 1],
                ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 2],
            ],
        ],
    ];
}

function goldenRequest(): array
{
    return [
        'version' => '1.0.0',
        'action' => 'rank_clips',
        'media' => ['duration_ms' => 40000],
        'candidates' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'transcript_text' => 'engaging content'],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'transcript_text' => ''],
        ],
        'configuration' => [
            'prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
        ],
    ];
}

function goldenInputSnapshot(): array
{
    return [
        'duration_ms' => 40000,
        'candidates' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2],
        ],
        'transcript_used' => true,
        'prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
    ];
}

function goldenExecutionParameters(): array
{
    return [
        'timeout_seconds' => 60,
        'lock_wait_seconds' => 65,
    ];
}

/*
|--------------------------------------------------------------------------
| Validator Rejects Malformed Success (RED - class doesn't exist)
|--------------------------------------------------------------------------
*/

it('rejects malformed success', function (array $path, mixed $value, bool $remove = false) {
    $response = goldenRankingResponse();
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
        ClipRecommendationValidator::validate($response, goldenRequest(), goldenInputSnapshot(), goldenExecutionParameters());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    $this->assertInstanceOf(ProcessMediaException::class, $failure, 'Malformed worker success must not be accepted.');
    expect($failure->getMessage())->toBe('Ranking validation failed');
    expect($failure->getPrevious())->toBeNull();
})->with(function () {
    $base = goldenRankingResponse();
    $objects = [
        ['ranking'],
        ['ranking', 'parameters'],
        ['ranking', 'recommendations', 0],
    ];
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
    foreach (['algorithm' => 'other', 'algorithm_version' => '9.9.9', 'parameters' => [], 'recommendations' => (object) []] as $key => $bad) {
        yield "ranking wrong $key" => [['ranking', $key], $bad];
    }
    // semantic_score is a float field: type mutations use only type-invalid
    // values (bounds are inclusive [0,1], so in-range numerics such as 1.0 are
    // valid and asserted as accepted below). m4_candidate_index and
    // combined_rank remain strictly integer-typed (float 1.0 rejected there).
    foreach (['m4_candidate_index', 'semantic_score', 'combined_rank'] as $key) {
        $badValues = $key === 'semantic_score' ? [true, '1', -1] : [true, 1.0, '1', -1];
        foreach ($badValues as $i => $bad) {
            yield "recommendation $key type $i" => [['ranking', 'recommendations', 0, $key], $bad];
        }
    }
    foreach ([['semantic_score'], ['combined_rank']] as $path) {
        foreach ([true, '0.75', -0.1, 1.1, 0.7512345] as $i => $bad) {
            yield 'numeric '.implode('.', $path)." $i" => [['ranking', 'recommendations', 0, ...$path], $bad];
        }
    }
    foreach ([[], (object) [], [0, 0], [1], [true], [0.0], ['0'], [99]] as $i => $bad) {
        yield "m4_candidate_index $i" => [['ranking', 'recommendations', 0, 'm4_candidate_index'], $bad];
    }
    yield 'fabricated empty result' => [['ranking', 'recommendations'], []];
    yield 'missing eligible result' => [['ranking', 'recommendations'], [$base['ranking']['recommendations'][0]]];
    yield 'duplicate candidates' => [['ranking', 'recommendations'], [$base['ranking']['recommendations'][0], $base['ranking']['recommendations'][0]]];
    yield 'wrong order' => [['ranking', 'recommendations'], array_reverse($base['ranking']['recommendations'])];
    $ranked = $base['ranking']['recommendations'];
    $ranked[0]['combined_rank'] = 2;
    $ranked[1]['combined_rank'] = 1;
    yield 'plausible wrong tie break' => [['ranking', 'recommendations'], $ranked];
    yield 'extra meaningful precision' => [['ranking', 'recommendations', 0, 'semantic_score'], 0.87234101];
    yield 'changed prototype query' => [['ranking', 'parameters', 'prototype_query'], 'different query'];
    yield 'wrong transcript_used' => [['ranking', 'parameters', 'transcript_used'], false];
    yield 'wrong normalization' => [['ranking', 'parameters', 'normalization'], 'other'];
    yield 'wrong score_scale' => [['ranking', 'parameters', 'score_scale'], 2.0];
    yield 'wrong tie_break' => [['ranking', 'parameters', 'tie_break'], 'other'];
});

/*
|--------------------------------------------------------------------------
| Validator Accepts Golden Response
|--------------------------------------------------------------------------
*/

it('accepts independently hand-derived golden ranking', function () {
    $response = goldenRankingResponse();
    expect(
        ClipRecommendationValidator::validate($response, goldenRequest(), goldenInputSnapshot(), goldenExecutionParameters())
    )->toBe($response);
});

/*
|--------------------------------------------------------------------------
| Validator Accepts Inclusive Bound And Different In-Range Scores
|--------------------------------------------------------------------------
*/

it('accepts inclusive upper bound semantic_score of exactly 1.0', function () {
    // Bounds are inclusive [0,1]: the score 1.0 is valid (FakeRankingProvider
    // emits 1.0 for M4 rank 1 and a large finite logit may round to 1.0).
    // Laravel M5 score validation is structural-only; the validator must
    // return the response unchanged.
    $response = goldenRankingResponse();
    $response['ranking']['recommendations'][0]['semantic_score'] = 1.0;

    expect(
        ClipRecommendationValidator::validate($response, goldenRequest(), goldenInputSnapshot(), goldenExecutionParameters())
    )->toBe($response);
});

it('accepts structurally valid response with different in-range scores', function (int $index, float $score) {
    // A response satisfying every structural rule but with different in-range
    // score values must be accepted: model inference lives in the Python
    // worker provider, so PHP neither recomputes nor golden-pins scores.
    $response = goldenRankingResponse();
    $response['ranking']['recommendations'][$index]['semantic_score'] = $score;

    expect(
        ClipRecommendationValidator::validate($response, goldenRequest(), goldenInputSnapshot(), goldenExecutionParameters())
    )->toBe($response);
})->with([
    'first recommendation 0.8' => [0, 0.8],
    'second recommendation 0.6' => [1, 0.6],
]);

/*
|--------------------------------------------------------------------------
| Validator Sanitizes Transport Errors
|--------------------------------------------------------------------------
*/

it('sanitizes transport errors without chained sensitive exceptions', function (string $raw, bool $throws, string $expected) {
    $failure = null;
    try {
        $action = new class($raw, $throws ? new \RuntimeException('PRIVATE_SENTINEL') : null) extends ProcessMediaAction
        {
            public function __construct(private string $output, private ?\Throwable $failure) {}

            protected function createProcess(array $command): Process
            {
                return new class($this->output, $this->failure) extends Process
                {
                    public function __construct(private string $output, private ?\Throwable $failure)
                    {
                        parent::__construct(['recording-worker']);
                    }

                    public function run(?callable $callback = null, array $env = []): int
                    {
                        if ($this->failure !== null) {
                            throw $this->failure;
                        }

                        return 0;
                    }

                    public function isSuccessful(): bool
                    {
                        return true;
                    }

                    public function getOutput(): string
                    {
                        return $this->output;
                    }

                    public function getExitCode(): ?int
                    {
                        return 0;
                    }
                };
            }
        };
        // Need to call rankClips via reflection since it doesn't exist yet
        $reflection = new \ReflectionClass($action);
        if ($reflection->hasMethod('rankClips')) {
            $action->rankClips(MediaProcessingContract::fromArray(goldenRequest()));
        }
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe($expected);
    expect($failure->getPrevious())->toBeNull();
    expect($failure->stderr)->toBe('');
})->with([
    'malformed JSON' => ['PRIVATE_SENTINEL', false, 'Ranking validation failed'],
    'trailing output' => ['{"status":"success"} PRIVATE_SENTINEL', false, 'Ranking validation failed'],
    'nonfinite' => ['{"status":"success","ranking":{"score":NaN}}', false, 'Ranking validation failed'],
    'overflow' => ['{"status":"success","ranking":{"score":1e9999}}', false, 'Ranking validation failed'],
    'unexpected runtime abort' => ['', true, 'clip_ranking_aborted'],
]);

/*
|--------------------------------------------------------------------------
| Validator Rejects Invalid Candidate Timing
|--------------------------------------------------------------------------
*/

it('rejects candidate timing beyond persisted duration', function (string $key, mixed $value) {
    $response = goldenRankingResponse();
    $response['ranking']['recommendations'][0][$key] = $value;
    $failure = null;
    try {
        ClipRecommendationValidator::validate($response, goldenRequest(), goldenInputSnapshot(), goldenExecutionParameters());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe('Ranking validation failed');
    expect($failure->getPrevious())->toBeNull();
})->with([
    'start_ms negative' => ['start_ms', -1000],
    'start_ms beyond duration' => ['start_ms', 50000],
    'end_ms negative' => ['end_ms', -1000],
    'end_ms beyond duration' => ['end_ms', 50000],
]);

/*
|--------------------------------------------------------------------------
| Validator Rejects Invalid Rank Values
|--------------------------------------------------------------------------
*/

it('rejects invalid combined_rank values', function (string $key, mixed $value) {
    $response = goldenRankingResponse();
    $response['ranking']['recommendations'][0][$key] = $value;
    $failure = null;
    try {
        ClipRecommendationValidator::validate($response, goldenRequest(), goldenInputSnapshot(), goldenExecutionParameters());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }
    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe('Ranking validation failed');
    expect($failure->getPrevious())->toBeNull();
})->with([
    'combined_rank zero' => ['combined_rank', 0],
    'combined_rank negative' => ['combined_rank', -1],
    'combined_rank fractional' => ['combined_rank', 1.5],
    'combined_rank string' => ['combined_rank', '1'],
    'combined_rank boolean' => ['combined_rank', true],
    'combined_rank above K' => ['combined_rank', 3],
]);
