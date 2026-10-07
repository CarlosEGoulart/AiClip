<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Services\ClipRecommendationValidator;
use Tests\TestCase;
use Tests\Support\CanonicalJson;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Fixtures — hand-derived from the specification, not from the worker
|--------------------------------------------------------------------------
*/

const FIXED_QUERY = 'Engaging, self-contained short-form video clip highlight with a clear narrative or punchline.';

function specConfiguration(string $provider = 'fake'): array
{
    $profile = $provider === 'cross_encoder'
        ? [
            'model_id' => 'cross-encoder/ms-marco-MiniLM-L6-v2',
            'model_revision' => '233902d25c440f23af6f7d6e94d2946bac0bee0a',
            'runtime_profile' => 'minilm_cpu_v1',
            'normalization' => 'stable_sigmoid_half_up_6',
            'max_tokens' => 512,
            'batch_size' => 8,
            'truncation' => 'right_longest_first_512',
        ]
        : [
            'model_id' => 'fake-ranking-v1',
            'model_revision' => '1.0.0',
            'runtime_profile' => 'fake_v1',
            'normalization' => 'fixture_units_6',
            'max_tokens' => 0,
            'batch_size' => 0,
            'truncation' => 'none',
        ];

    return [
        'provider' => $provider,
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'projection_version' => '1.0.0',
        'query_version' => '1.0.0',
        'prototype_query' => FIXED_QUERY,
    ] + $profile;
}

function specParameters(string $provider = 'fake', bool $inferencePerformed = false, bool $transcriptUsed = true): array
{
    $configuration = specConfiguration($provider);
    unset($configuration['algorithm'], $configuration['algorithm_version']);

    $configuration['provider_name'] = $provider === 'cross_encoder'
        ? 'cross_encoder_ranking_provider'
        : 'fake_ranking_provider';
    $configuration['inference_performed'] = $inferencePerformed;
    $configuration['transcript_used'] = $transcriptUsed;

    return $configuration;
}

function specRequest(string $provider = 'fake'): array
{
    return [
        'version' => '1.0.0',
        'action' => 'rank_clips',
        'media' => ['duration_ms' => 40000],
        'candidates' => [
            [
                'index' => 0,
                'start_ms' => 0,
                'end_ms' => 10000,
                'm4_rank' => 1,
                'm4_score' => 1.0,
                'transcript_text' => 'first candidate window text',
            ],
            [
                'index' => 1,
                'start_ms' => 10000,
                'end_ms' => 20000,
                'm4_rank' => 2,
                'm4_score' => 0.5,
                'transcript_text' => '',
            ],
        ],
        'configuration' => specConfiguration($provider),
    ];
}

/**
 * A fully eligible two-candidate request whose M4 ranks are supplied so that
 * ranking-rule orderings (which must follow M4 rank, not candidate index) can
 * be expressed independently.
 */
function specEligibleRequest(array $m4Ranks = [1, 2]): array
{
    $request = specRequest();
    $request['candidates'][0]['m4_rank'] = $m4Ranks[0];
    $request['candidates'][0]['transcript_text'] = 'first candidate window text';
    $request['candidates'][1]['m4_rank'] = $m4Ranks[1];
    $request['candidates'][1]['transcript_text'] = 'second candidate window text';

    return $request;
}

/**
 * A fully eligible success response built from an explicit member order.
 *
 * @param  list<array{m4_candidate_index: int, semantic_score: float|null, semantic_rank: int|null}>  $order
 */
function specEligibleResponse(array $request, array $order): array
{
    $response = specResponse($request);
    $response['ranking']['recommendations'] = array_map(
        static function (array $member) use ($request): array {
            $candidate = $request['candidates'][$member['m4_candidate_index']];

            return [
                'm4_candidate_index' => $candidate['index'],
                'start_ms' => $candidate['start_ms'],
                'end_ms' => $candidate['end_ms'],
                'm4_rank' => $candidate['m4_rank'],
                'm4_score' => $candidate['m4_score'],
                'semantic_score' => $member['semantic_score'],
                'semantic_rank' => $member['semantic_rank'],
                'reason' => null,
            ];
        },
        $order
    );

    return $response;
}

function requestDigest(array $request): string
{
    return CanonicalJson::sha256($request);
}

function specResponse(array $request, array $overrides = []): array
{
    $response = [
        'status' => 'success',
        'ranking' => [
            'algorithm' => 'transcript_semantic_recommendation',
            'algorithm_version' => '1.0.0',
            'parameters' => specParameters((string) $request['configuration']['provider']),
            'request_sha256' => requestDigest($request),
            'recommendations' => [
                [
                    'm4_candidate_index' => 0,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'm4_rank' => 1,
                    'm4_score' => 1.0,
                    'semantic_score' => 0.880797,
                    'semantic_rank' => 1,
                    'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1,
                    'start_ms' => 10000,
                    'end_ms' => 20000,
                    'm4_rank' => 2,
                    'm4_score' => 0.5,
                    'semantic_score' => null,
                    'semantic_rank' => null,
                    'reason' => 'no_candidate_text',
                ],
            ],
        ],
    ];

    foreach ($overrides as $path => $value) {
        $segments = explode('.', (string) $path);
        $target = &$response;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $target = &$target[$segment];
        }
        if ($value === '__REMOVE__') {
            unset($target[array_pop($segments)]);
        } else {
            $target[array_pop($segments)] = $value;
        }
        unset($target);
    }

    return $response;
}

function assertRankingRejected(mixed $response, array $request, string $label = ''): void
{
    $failure = null;

    try {
        ClipRecommendationValidator::result($response, $request, requestDigest($request));
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class, 'expected ranking rejection: '.$label);
    expect($failure->getMessage())->toBe('Ranking validation failed')
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->stderr)->toBe('');
}

function assertRequestRejected(mixed $request, string $label = ''): void
{
    $failure = null;

    try {
        ClipRecommendationValidator::request($request);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    // The request preflight message stays internal; only the sanitized type,
    // the empty stderr and the absence of a chained cause cross the boundary.
    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class, 'expected request rejection: '.$label);
    expect($failure->getPrevious())->toBeNull()
        ->and($failure->stderr)->toBe('');
}

function assertRequestAccepted(array $request): void
{
    $failure = null;

    try {
        expect(ClipRecommendationValidator::request($request))->toBe($request);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeNull();
}

/*
|--------------------------------------------------------------------------
| Exact request envelope
|--------------------------------------------------------------------------
*/

it('accepts the exact specification request envelope unchanged', function () {
    assertRequestAccepted(specRequest());
});

it('builds the exact request key set from the specification', function () {
    $request = specRequest();

    expect(array_keys($request))->toBe(['version', 'action', 'media', 'candidates', 'configuration'])
        ->and(array_keys($request['media']))->toBe(['duration_ms'])
        ->and(array_keys($request['candidates'][0]))->toBe([
            'index', 'start_ms', 'end_ms', 'm4_rank', 'm4_score', 'transcript_text',
        ])
        ->and(array_keys($request['configuration']))->toBe([
            'provider', 'algorithm', 'algorithm_version', 'projection_version', 'query_version',
            'prototype_query', 'model_id', 'model_revision', 'runtime_profile', 'normalization',
            'max_tokens', 'batch_size', 'truncation',
        ]);
});

it('builds the rank_clips request through the media processing contract', function () {
    $request = specRequest();
    $contract = MediaProcessingContract::fromArray($request);

    expect($contract->action)->toBe('rank_clips')
        ->and($contract->validate())->toBeTrue()
        ->and($contract->toRankClipsMetadataArray())->toBe($request);
});

it('rejects a request that does not use the exact specification key sets', function (string $path, mixed $value) {
    $request = specRequest();
    $segments = explode('.', $path);
    $target = &$request;
    foreach (array_slice($segments, 0, -1) as $segment) {
        $target = &$target[$segment];
    }
    if ($value === '__REMOVE__') {
        unset($target[array_pop($segments)]);
    } else {
        $target[array_pop($segments)] = $value;
    }

    assertRequestRejected($request, $path);
})->with(function () {
    $paths = [
        'ranking.algorithm' => 'cross_encoder_reranker',
        'ranking.algorithm_version' => '9.9.9',
        'ranking.provider' => 'cross_encoder',
        'ranking.projection_version' => '2.0.0',
        'ranking.query_version' => '2.0.0',
        'ranking.prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
        'ranking.model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
        'ranking.model_revision' => 'main',
        'ranking.runtime_profile' => 'sigmoid_v1',
        'ranking.normalization' => 'sigmoid',
        'ranking.max_tokens' => 1,
        'ranking.batch_size' => 1,
        'ranking.truncation' => 'left_longest_first_512',
        'ranking.inference_performed' => true,
    ];

    foreach ($paths as $name => $value) {
        yield $name => [$name, $value];
    }

    $more = [
        'missing version' => ['version', '__REMOVE__'],
        'missing action' => ['action', '__REMOVE__'],
        'missing media' => ['media', '__REMOVE__'],
        'missing candidates' => ['candidates', '__REMOVE__'],
        'missing configuration' => ['configuration', '__REMOVE__'],
        'extra request key' => ['media_asset_id', 1],
        'extra project id' => ['project_id', 1],
        'extra storage' => ['storage', ['disk' => 'media']],
        'extra media key' => ['media.x', 1],
        'transcript segments' => ['transcript_segments', [['start_ms' => 0, 'end_ms' => 1]]],
        'scenes' => ['scenes', []],
        'media as list' => ['media', ['duration_ms']],
        'candidates as object' => ['candidates', ['a' => 1]],
        'configuration as list' => ['configuration', ['provider']],
        'duration zero' => ['media.duration_ms', 0],
        'duration negative' => ['media.duration_ms', -1],
        'duration above maximum' => ['media.duration_ms', 2147483648],
        'duration numeric string' => ['media.duration_ms', '40000'],
        'duration float' => ['media.duration_ms', 40000.0],
        'duration bool' => ['media.duration_ms', true],
        'candidate missing m4_rank' => ['candidates.0.m4_rank', '__REMOVE__'],
        'candidate legacy rank key' => ['candidates.0.rank', 1],
        'candidate missing m4_score' => ['candidates.0.m4_score', '__REMOVE__'],
        'candidate legacy score key' => ['candidates.0.score', 1.0],
        'candidate extra criteria' => ['candidates.0.criteria', ['duration_fit' => 1]],
        'candidate extra source scenes' => ['candidates.0.source_scene_indexes', [0]],
        'candidate index not sequential' => ['candidates.0.index', 1],
        'candidate index string' => ['candidates.0.index', '0'],
        'candidate index float' => ['candidates.0.index', 0.0],
        'candidate index bool' => ['candidates.0.index', false],
        'candidate m4_rank zero' => ['candidates.0.m4_rank', 0],
        'candidate m4_rank above K' => ['candidates.0.m4_rank', 3],
        'candidate duplicate m4 rank' => ['candidates.0.m4_rank', 2],
        'candidate m4_rank string' => ['candidates.0.m4_rank', '1'],
        'candidate m4_score string' => ['candidates.0.m4_score', '1.0'],
        'candidate m4_score bool' => ['candidates.0.m4_score', true],
        'candidate m4_score out of M4 range' => ['candidates.0.m4_score', 1.5],
        'candidate m4_score negative' => ['candidates.0.m4_score', -0.5],
        'candidate end beyond duration' => ['candidates.0.end_ms', 40001],
        'candidate end not after start' => ['candidates.0.end_ms', 0],
        'candidate text not string' => ['candidates.0.transcript_text', 1],
        'candidate text null' => ['candidates.0.transcript_text', null],
        'candidate text over byte bound' => ['candidates.0.transcript_text', str_repeat('a', 16385)],
        'candidate text whitespace only' => ['candidates.0.transcript_text', '   '],
        'candidate text unicode whitespace only' => ['candidates.0.transcript_text', "\u{3000}"],
        'candidate text non canonical whitespace run' => ['candidates.0.transcript_text', 'a  b'],
        'empty candidates list' => ['candidates', []],
    ];

    foreach ($more as $name => $case) {
        yield $name => $case;
    }
});

/*
|--------------------------------------------------------------------------
| Exact response envelope and provenance
|--------------------------------------------------------------------------
*/

it('accepts the exact specification success response unchanged', function () {
    $request = specRequest();
    $response = specResponse($request);

    expect(ClipRecommendationValidator::result($response, $request, requestDigest($request)))->toBe($response);
});

it('accepts a truthful cross-encoder provenance without any fake identity', function () {
    $request = specRequest('cross_encoder');
    $response = specResponse($request, ['ranking.parameters.inference_performed' => true]);

    expect(ClipRecommendationValidator::result($response, $request, requestDigest($request)))->toBe($response)
        ->and(json_encode($response))->not->toContain('fake_ranking_provider')
        ->and(json_encode($response))->not->toContain('fixture_units_6');
});

it('accepts a truthful fake provenance without any real model identity', function () {
    $request = specRequest('fake');
    $response = specResponse($request);

    expect(ClipRecommendationValidator::result($response, $request, requestDigest($request)))->toBe($response)
        ->and(json_encode($response))->not->toContain('cross_encoder_ranking_provider')
        ->and(json_encode($response))->not->toContain('MiniLM')
        ->and(json_encode($response))->not->toContain('stable_sigmoid_half_up_6');
});

it('rejects any response whose provenance does not equal the selected profile', function (string $path, mixed $value) {
    $request = specRequest();
    assertRankingRejected(specResponse($request, [$path => $value]), $request, $path);
})->with(function () {
    $ranking = ['ranking.algorithm' => 'cross_encoder_reranker', 'ranking.algorithm_version' => '2.0.0'];
    foreach ($ranking as $path => $value) {
        yield $path => [$path, $value];
    }

    $parameters = [
        'ranking.parameters.provider' => 'cross_encoder',
        'ranking.parameters.provider_name' => 'cross_encoder_ranking_provider',
        'ranking.parameters.model_id' => 'cross-encoder/ms-marco-MiniLM-L6-v2',
        'ranking.parameters.model_revision' => 'main',
        'ranking.parameters.runtime_profile' => 'minilm_cpu_v1',
        'ranking.parameters.normalization' => 'stable_sigmoid_half_up_6',
        'ranking.parameters.projection_version' => '2.0.0',
        'ranking.parameters.query_version' => '2.0.0',
        'ranking.parameters.prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
        'ranking.parameters.max_tokens' => 512,
        'ranking.parameters.batch_size' => 8,
        'ranking.parameters.truncation' => 'right_longest_first_512',
        'ranking.parameters.inference_performed' => true,
        'ranking.parameters.transcript_used' => false,
    ];
    foreach ($parameters as $path => $value) {
        yield $path => [$path, $value];
    }

    yield 'missing provider selector' => ['ranking.parameters.provider', '__REMOVE__'];
    yield 'missing provider identity' => ['ranking.parameters.provider_name', '__REMOVE__'];
    yield 'extra runtime path' => ['ranking.parameters.model_path', '/opt/models/minilm'];
    yield 'extra raw logits' => ['ranking.parameters.logits', [1, 2]];
    yield 'extra score scale' => ['ranking.parameters.score_scale', 1.0];
    yield 'extra tie break' => ['ranking.parameters.tie_break', 'm4_rank_then_chronological'];
    yield 'provider selector boolean' => ['ranking.parameters.provider', true];
    yield 'inference flag string' => ['ranking.parameters.inference_performed', 'false'];
    yield 'transcript used string' => ['ranking.parameters.transcript_used', 'true'];
});

it('keeps the provider selector distinct from the provider identity', function () {
    $request = specRequest();
    $response = specResponse($request);

    expect($response['ranking']['parameters']['provider'])->toBe('fake')
        ->and($response['ranking']['parameters']['provider'])->not->toBe($response['ranking']['parameters']['provider_name'])
        ->and($response['ranking']['parameters']['provider_name'])->toBe('fake_ranking_provider');
});

it('rejects a response that is not the exact success envelope', function (string $path, mixed $value) {
    $request = specRequest();
    assertRankingRejected(specResponse($request, [$path => $value]), $request);
})->with([
    'missing ranking' => ['ranking', '__REMOVE__'],
    'missing status' => ['status', '__REMOVE__'],
    'error status' => ['status', 'error'],
    'status boolean' => ['status', true],
    'status uppercase' => ['status', 'SUCCESS'],
    'missing algorithm' => ['ranking.algorithm', '__REMOVE__'],
    'missing algorithm version' => ['ranking.algorithm_version', '__REMOVE__'],
    'missing parameters' => ['ranking.parameters', '__REMOVE__'],
    'missing recommendations' => ['ranking.recommendations', '__REMOVE__'],
    'missing request digest' => ['ranking.request_sha256', '__REMOVE__'],
    'ranking as list' => ['ranking', ['transcript_semantic_recommendation']],
    'parameters as list' => ['ranking.parameters', ['transcript_semantic_recommendation']],
    'recommendations as object' => ['ranking.recommendations', ['a' => 1]],
    'digest null' => ['ranking.request_sha256', null],
    'digest boolean' => ['ranking.request_sha256', true],
    'digest not lowercase hex' => ['ranking.request_sha256', 'PRIVATE'],
    'digest wrong value' => ['ranking.request_sha256', 'PRIVATE_SENTINEL'],
    'digest other request' => ['ranking.request_sha256', hash('sha256', 'other bytes')],
]);

it('rejects a response whose digest does not match the exact sent bytes', function () {
    $request = specRequest();
    $response = specResponse($request);

    // The bound digest must be the SHA256 of the exact bytes handed to the
    // worker, not of a reserialized approximation of the same structure.
    $nonCanonicalJson = json_encode($request, JSON_THROW_ON_ERROR);
    $reserialized = json_encode(json_decode($nonCanonicalJson));
    $response['ranking']['request_sha256'] = hash('sha256', $reserialized.' ');

    assertRankingRejected($response, $request);
});

it('rejects a response with an extra envelope key carrying private data', function () {
    $request = specRequest();
    $response = specResponse($request);
    $response['ranking']['raw_logits'] = [1.234, -0.5];

    assertRankingRejected($response, $request);
});

/*
|--------------------------------------------------------------------------
| Recommendation members
|--------------------------------------------------------------------------
*/

it('rejects any malformed recommendation member as a whole result', function (string $path, mixed $value) {
    $request = specRequest();
    assertRankingRejected(specResponse($request, [$path => $value]), $request, $path);
})->with(function () {
    $paths = [
        'ranking.recommendations.0.m4_candidate_index' => [
            '__REMOVE__', true, '0', 0.5, -1, 2, 99, [], (object) [],
        ],
        'ranking.recommendations.0.start_ms' => [
            '__REMOVE__', true, '0', 1.5, -1, 999, 10001, 20000, 40001,
        ],
        'ranking.recommendations.0.end_ms' => [
            '__REMOVE__', true, '20000', 0, -1, 40001,
        ],
        'ranking.recommendations.0.m4_rank' => [
            '__REMOVE__', true, '1', 1.0, 0, 3, 2,
        ],
        'ranking.recommendations.0.m4_score' => [
            '__REMOVE__', true, '1.0', 0.5, 0.25, 2, -1,
        ],
        'ranking.recommendations.0.semantic_score' => [
            '__REMOVE__', true, '0.880797', -0.1, 1.1, 0.8807971, 0.88079701, INF,
        ],
        'ranking.recommendations.0.semantic_rank' => [
            '__REMOVE__', true, '1', 1.0, 0, 2, 3,
        ],
        'ranking.recommendations.0.reason' => [
            '__REMOVE__', true, 'no_candidate_text', '', 'other',
        ],
        'ranking.recommendations.1.semantic_score' => [0.5],
        'ranking.recommendations.1.semantic_rank' => [1],
    ];

    foreach ($paths as $path => $values) {
        foreach ($values as $index => $value) {
            yield $path.' #'.$index => [$path, $value];
        }
    }

    yield 'extra recommendation key' => ['ranking.recommendations.0.raw_logit', 0.5];
    yield 'fabricated empty list' => ['ranking.recommendations', []];
    yield 'single member only' => ['ranking.recommendations', [null]];
    yield 'recommendation as list' => ['ranking.recommendations.0', ['transcript_semantic_recommendation']];
});

it('rejects a result with a missing duplicate or gapped reference', function (array $recommendations) {
    $request = specRequest();
    $response = specResponse($request, ['ranking.recommendations' => $recommendations]);

    assertRankingRejected($response, $request);
})->with(function () {
    $scored = [
        'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000,
        'm4_rank' => 1, 'm4_score' => 1.0,
        'semantic_score' => 0.880797, 'semantic_rank' => 1, 'reason' => null,
    ];
    $unscored = [
        'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000,
        'm4_rank' => 2, 'm4_score' => 0.5,
        'semantic_score' => null, 'semantic_rank' => null, 'reason' => 'no_candidate_text',
    ];

    yield 'duplicate index' => [[$scored, $scored, $unscored]];
    yield 'duplicate unscored index' => [[$scored, $unscored, $unscored]];
    yield 'index gap' => [
        [$scored, ['m4_candidate_index' => 2, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 2, 'm4_score' => 1.0, 'semantic_score' => 0.5, 'semantic_rank' => 2, 'reason' => null]],
    ];
    yield 'extra third reference' => [[$scored, $unscored, [
        'm4_candidate_index' => 2, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 2,
        'm4_score' => 1.0, 'semantic_score' => 0.1, 'semantic_rank' => 2, 'reason' => null,
    ]]];
    yield 'scored after unscored' => [[$unscored, $scored]];
    yield 'unscored before scored with equal score' => [[
        [
            'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
            'm4_score' => 0.5, 'semantic_score' => 0.880797, 'semantic_rank' => 1, 'reason' => null,
        ],
        [
            'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
            'm4_score' => 1.0, 'semantic_score' => 0.880797, 'semantic_rank' => 2, 'reason' => null,
        ],
    ]];
    yield 'wrong score order' => [[
        [
            'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
            'm4_score' => 1.0, 'semantic_score' => 0.5, 'semantic_rank' => 1, 'reason' => null,
        ],
        [
            'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
            'm4_score' => 0.5, 'semantic_score' => 0.880797, 'semantic_rank' => 2, 'reason' => null,
        ],
    ]];
    yield 'unscored entry with a semantic rank' => [[$scored, array_merge($unscored, ['semantic_rank' => 1])]];
    yield 'scored entry carrying the unscored reason' => [[array_merge($scored, ['reason' => 'no_candidate_text']), $unscored]];
    yield 'unknown unscored reason' => [[$scored, array_merge($unscored, ['reason' => 'unknown'])]];
    yield 'contiguity broken' => [[array_merge($scored, ['semantic_rank' => 2]), $unscored]];
});

it('rejects a null score on an eligible candidate and a score on an ineligible candidate', function (int $index, mixed $score, mixed $rank, mixed $reason) {
    $request = specRequest();
    $response = specResponse($request, [
        "ranking.recommendations.{$index}.semantic_score" => $score,
        "ranking.recommendations.{$index}.semantic_rank" => $rank,
        "ranking.recommendations.{$index}.reason" => $reason,
    ]);

    assertRankingRejected($response, $request);
})->with([
    'eligible candidate scored null' => [0, null, null, 'no_candidate_text'],
    'eligible candidate scored null without reason' => [0, null, null, null],
    'ineligible candidate scored' => [1, 0.5, 1, null],
    'ineligible candidate scored without reason' => [1, 0.5, null, null],
    'ineligible candidate scored with rank' => [1, 0.5, 2, null],
]);

it('accepts structurally valid alternative in-range neural scores without recomputation', function (float $score) {
    // Laravel never recomputes or golden-pins neural scores: a structurally
    // valid different in-range value is the documented trust boundary.
    $request = specRequest();
    $response = specResponse($request, ['ranking.recommendations.0.semantic_score' => $score]);

    expect(ClipRecommendationValidator::result($response, $request, requestDigest($request)))->toBe($response);
})->with([
    'lowest bound' => [0.0],
    'midpoint' => [0.5],
    'close to one' => [0.999999],
    'upper bound' => [1.0],
    'independent value' => [0.654321],
]);

it('rejects scored entries whose order violates descending score units', function () {
    // The two members differ by exactly one six-decimal score unit, so the
    // authoritative order is strict: no epsilon tolerance may accept a swap.
    $request = specEligibleRequest([1, 2]);
    $response = specEligibleResponse($request, [
        ['m4_candidate_index' => 0, 'semantic_score' => 0.880796, 'semantic_rank' => 1],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.880797, 'semantic_rank' => 2],
    ]);

    assertRankingRejected($response, $request);
});

it('breaks equal score units by ascending m4 rank, not candidate index', function () {
    // M4 rank 2 sits on candidate index 0: with identical score units the
    // only accepted order is the one that puts M4 rank 1 first.
    $request = specEligibleRequest([2, 1]);

    $rejected = specEligibleResponse($request, [
        ['m4_candidate_index' => 0, 'semantic_score' => 0.5, 'semantic_rank' => 1],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.5, 'semantic_rank' => 2],
    ]);
    assertRankingRejected($rejected, $request);

    $accepted = specEligibleResponse($request, [
        ['m4_candidate_index' => 1, 'semantic_score' => 0.5, 'semantic_rank' => 1],
        ['m4_candidate_index' => 0, 'semantic_score' => 0.5, 'semantic_rank' => 2],
    ]);

    expect(ClipRecommendationValidator::result($accepted, $request, requestDigest($request)))->toBe($accepted);
});

it('keeps unscored entries after scored entries in ascending candidate index', function () {
    $request = specRequest();
    $response = specResponse($request, ['ranking.recommendations' => [
        [
            'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
            'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
            'reason' => 'no_candidate_text',
        ],
        [
            'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
            'm4_score' => 1.0, 'semantic_score' => 0.880797, 'semantic_rank' => 1, 'reason' => null,
        ],
    ]]);

    assertRankingRejected($response, $request);
});

it('rejects extra meaningful decimal precision beyond the score unit', function (float $score) {
    $request = specRequest();
    assertRankingRejected(specResponse($request, ['ranking.recommendations.0.semantic_score' => $score]), $request);
})->with([
    'seven decimals' => [0.8807971],
    'seven decimals rounding up' => [0.88079701],
    'float artefact' => [0.1 + 0.2],
    'tiny magnitude' => [1.0E-7],
]);

/*
|--------------------------------------------------------------------------
| Shared completion boundary
|--------------------------------------------------------------------------
*/

function specCompletion(array $overrides = []): array
{
    $request = specRequest();
    $completion = [
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'parameters' => specParameters('fake'),
        'recommendations' => specResponse($request)['ranking']['recommendations'],
        'input_snapshot' => [
            'm4_analysis_id' => 7,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 1.0, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [0]],
                ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 0.5, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [1]],
            ],
            'duration_ms' => 40000,
            'transcript_state' => 'completed_valid',
            'projection_version' => '1.0.0',
            'text_hashes' => [
                ['index' => 0, 'sha256' => hash('sha256', 'first candidate window text')],
                ['index' => 1, 'sha256' => hash('sha256', '')],
            ],
            'request_sha256' => requestDigest($request),
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ];

    foreach ($overrides as $path => $value) {
        $segments = explode('.', (string) $path);
        $target = &$completion;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $target = &$target[$segment];
        }
        if ($value === '__REMOVE__') {
            unset($target[array_pop($segments)]);
        } else {
            $target[array_pop($segments)] = $value;
        }
        unset($target);
    }

    return $completion;
}

function assertCompletionRejected(array $completion, string $label = ''): void
{
    $failure = null;

    try {
        ClipRecommendationValidator::validateCompletion($completion);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class, 'expected completion rejection: '.$label);
    expect($failure->getMessage())->toBe('Ranking validation failed')
        ->and($failure->getPrevious())->toBeNull();
}

function assertCompletionAccepted(array $completion): void
{
    $failure = null;

    try {
        ClipRecommendationValidator::validateCompletion($completion);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeNull();
}

it('accepts a valid worker completion at the model boundary', function () {
    assertCompletionAccepted(specCompletion());
});

it('rejects a completion whose input snapshot is not the exact key set', function (string $path, mixed $value) {
    assertCompletionRejected(specCompletion([$path => $value]), $path);
})->with([
    'missing m4 analysis id' => ['input_snapshot.m4_analysis_id', '__REMOVE__'],
    'missing m4 algorithm' => ['input_snapshot.m4_algorithm', '__REMOVE__'],
    'missing m4 algorithm version' => ['input_snapshot.m4_algorithm_version', '__REMOVE__'],
    'missing m4 candidates' => ['input_snapshot.m4_candidates', '__REMOVE__'],
    'missing duration' => ['input_snapshot.duration_ms', '__REMOVE__'],
    'missing transcript state' => ['input_snapshot.transcript_state', '__REMOVE__'],
    'missing projection version' => ['input_snapshot.projection_version', '__REMOVE__'],
    'missing text hashes' => ['input_snapshot.text_hashes', '__REMOVE__'],
    'missing request digest' => ['input_snapshot.request_sha256', '__REMOVE__'],
    'extra snapshot key' => ['input_snapshot.transcript_text', 'PRIVATE_SENTINEL'],
    'extra snapshot candidate key' => ['input_snapshot.m4_candidates.0.transcript_text', 'PRIVATE_SENTINEL'],
    'm4 algorithm mutated' => ['input_snapshot.m4_algorithm', 'semantic_timing'],
    'm4 algorithm version mutated' => ['input_snapshot.m4_algorithm_version', '2.0.0'],
    'projection version mutated' => ['input_snapshot.projection_version', '2.0.0'],
    'unknown transcript state' => ['input_snapshot.transcript_state', 'completed_but_whatever'],
    'digest mismatch' => ['input_snapshot.request_sha256', 'PRIVATE_SENTINEL'],
    'text hash malformed' => ['input_snapshot.text_hashes.0.sha256', 'NOT-A-HASH'],
    'text hash not lowercase' => ['input_snapshot.text_hashes.0.sha256', strtoupper(hash('sha256', 'first candidate window text'))],
    'text hash index gap' => ['input_snapshot.text_hashes.0.index', 1],
    'text hash extra key' => ['input_snapshot.text_hashes.0.text', 'PRIVATE_SENTINEL'],
    'm4 candidate count mismatch' => ['input_snapshot.m4_candidates', []],
    'execution timeout zero' => ['execution_parameters.timeout_seconds', 0],
    'execution timeout above maximum' => ['execution_parameters.timeout_seconds', 121],
    'execution timeout float' => ['execution_parameters.timeout_seconds', 60.0],
    'execution lock wait wrong' => ['execution_parameters.lock_wait_seconds', 66],
    'execution lock wait missing' => ['execution_parameters.lock_wait_seconds', '__REMOVE__'],
    'execution extra key' => ['execution_parameters.model_path', '/opt/models'],
    'completion missing algorithm' => ['algorithm', '__REMOVE__'],
    'completion extra key' => ['raw_logits', [1.0]],
]);

it('accepts a validated local unavailable outcome with no inference claim', function () {
    $request = specRequest();
    $completion = specCompletion([
        'parameters' => specParameters('fake', false, false),
        'recommendations' => [
            [
                'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                'm4_score' => 1.0, 'semantic_score' => null, 'semantic_rank' => null,
                'reason' => 'no_candidate_text',
            ],
            [
                'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
                'reason' => 'no_candidate_text',
            ],
        ],
        'input_snapshot.transcript_state' => 'no_candidate_text',
        'input_snapshot.text_hashes' => [
            ['index' => 0, 'sha256' => hash('sha256', '')],
            ['index' => 1, 'sha256' => hash('sha256', '')],
        ],
        'input_snapshot.request_sha256' => null,
    ]);

    assertCompletionAccepted($completion);
});

it('rejects a local outcome that still claims inference or a worker digest', function (string $path, mixed $value) {
    assertCompletionRejected(specCompletion([
        'parameters' => specParameters('fake', false, false),
        'input_snapshot.transcript_state' => 'no_candidate_text',
        'input_snapshot.text_hashes' => [
            ['index' => 0, 'sha256' => hash('sha256', '')],
            ['index' => 1, 'sha256' => hash('sha256', '')],
        ],
        'input_snapshot.request_sha256' => null,
        $path => $value,
    ]));
})->with([
    'inference performed claim' => ['parameters.inference_performed', true],
    'transcript used claim' => ['parameters.transcript_used', true],
    'retained worker digest' => ['input_snapshot.request_sha256', 'PRIVATE_SENTINEL'],
    'scored entry without worker' => ['recommendations.0.semantic_score', 0.5],
]);

it('accepts a validated zero-candidate local completion', function () {
    $completion = specCompletion([
        'recommendations' => [],
        'input_snapshot.m4_candidates' => [],
        'input_snapshot.transcript_state' => 'completed_empty',
        'input_snapshot.text_hashes' => [],
        'input_snapshot.request_sha256' => null,
        'parameters' => specParameters('fake', false, false),
    ]);

    assertCompletionAccepted($completion);
});
