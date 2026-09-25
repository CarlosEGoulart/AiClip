<?php

namespace Tests\Unit\Services;

use App\Services\FakeRankingProvider;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const FAKE_PROTOTYPE_QUERY = 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.';

function fakeProviderCandidates(): array
{
    return [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'transcript_text' => 'engaging content'],
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'transcript_text' => ''],
        ['index' => 2, 'start_ms' => 20000, 'end_ms' => 30000, 'rank' => 3, 'transcript_text' => 'more content'],
    ];
}

/*
|--------------------------------------------------------------------------
| Deterministic M4-Rank-Descending Scores
|--------------------------------------------------------------------------
*/

it('returns deterministic M4-rank-descending scores', function () {
    $provider = new FakeRankingProvider;
    $candidates = fakeProviderCandidates();

    $result = $provider->rank($candidates, FAKE_PROTOTYPE_QUERY);

    // Scores follow the spec formula 1.0 - (m4_rank - 1) * 0.1, clamped to
    // inclusive [0,1]; ranks 1-3 produce exactly 1.0, 0.9, 0.8.
    expect($result['recommendations'])->toBe([
        ['m4_candidate_index' => 0, 'semantic_score' => 1.0, 'combined_rank' => 1],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.9, 'combined_rank' => 2],
        ['m4_candidate_index' => 2, 'semantic_score' => 0.8, 'combined_rank' => 3],
    ]);

    // Determinism: identical input produces an identical result.
    expect($provider->rank($candidates, FAKE_PROTOTYPE_QUERY))->toBe($result);
});

it('clamps scores to inclusive [0,1] bounds', function () {
    $provider = new FakeRankingProvider;

    // Twelve candidates: rank 11 lands exactly on the 0.0 bound and rank 12
    // computes below it, so both must be clamped to 0.0.
    $candidates = [];
    foreach (range(0, 11) as $index) {
        $candidates[] = [
            'index' => $index,
            'start_ms' => $index * 10000,
            'end_ms' => ($index + 1) * 10000,
            'rank' => $index + 1,
            'transcript_text' => '',
        ];
    }

    $result = $provider->rank($candidates, FAKE_PROTOTYPE_QUERY);

    expect($result['recommendations'])->toHaveCount(12);
    expect($result['recommendations'][0]['semantic_score'])->toBe(1.0);
    expect($result['recommendations'][10]['semantic_score'])->toBe(0.0);
    expect($result['recommendations'][11]['semantic_score'])->toBe(0.0);
    expect($result['recommendations'][11]['combined_rank'])->toBe(12);
});

it('orders recommendations by descending score with ascending M4 rank tie-break', function () {
    $provider = new FakeRankingProvider;

    // Candidates given out of rank order: M4 rank 2 before rank 1. Equal
    // formula scores never occur here, but the input order must not leak
    // into the output ordering.
    $candidates = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 2, 'transcript_text' => ''],
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 1, 'transcript_text' => ''],
    ];

    $result = $provider->rank($candidates, FAKE_PROTOTYPE_QUERY);

    expect($result['recommendations'])->toBe([
        ['m4_candidate_index' => 1, 'semantic_score' => 1.0, 'combined_rank' => 1],
        ['m4_candidate_index' => 0, 'semantic_score' => 0.9, 'combined_rank' => 2],
    ]);
});

/*
|--------------------------------------------------------------------------
| Input-Derived transcript_used
|--------------------------------------------------------------------------
*/

it('derives transcript_used from the given candidates', function (bool $expectedTranscriptUsed) {
    $provider = new FakeRankingProvider;
    $candidates = fakeProviderCandidates();
    if (! $expectedTranscriptUsed) {
        foreach ($candidates as $index => $candidate) {
            $candidates[$index]['transcript_text'] = '';
        }
    }

    $result = $provider->rank($candidates, FAKE_PROTOTYPE_QUERY);

    // Mirrors the worker action's input-derived rule: true iff any candidate
    // carries non-empty transcript_text.
    expect($result['transcript_used'])->toBe($expectedTranscriptUsed);
})->with([
    'transcript available' => true,
    'no transcript text' => false,
]);

/*
|--------------------------------------------------------------------------
| Fixed Non-Cross-Encoder Fake Identity
|--------------------------------------------------------------------------
*/

it('returns a fixed explicitly non-cross-encoder fake identity', function () {
    $provider = new FakeRankingProvider;

    $identity = $provider->getModelIdentity();

    // Fixed identity so fake and real are distinguishable at the provider
    // level: unlike the cross-encoder constants, the fake identity names the
    // deterministic fake provider.
    expect($identity)->toBe([
        'model_id' => 'fake-ranking-v1',
        'model_revision' => 'v1.0.0',
        'provider_name' => 'fake_ranking_provider',
    ]);
    expect($identity['model_id'])->not->toBe('cross-encoder/ms-marco-MiniLM-L-6-v2');
    expect($identity['provider_name'])->not->toBe('cross_encoder_ranking_provider');

    // Identity is stable across repeated calls and matches the rank() output.
    expect($provider->getModelIdentity())->toBe($identity);
    $result = $provider->rank(fakeProviderCandidates(), FAKE_PROTOTYPE_QUERY);
    expect($result['model_id'])->toBe($identity['model_id']);
    expect($result['model_revision'])->toBe($identity['model_revision']);
    expect($result['provider_name'])->toBe($identity['provider_name']);
});

/*
|--------------------------------------------------------------------------
| Entry-Point Patching (no process/network/database/media-file access)
|--------------------------------------------------------------------------
*/

it('performs no process, network, database, or media-file access', function () {
    // Install recording entry-point patches (namespace-local shadows that
    // record and delegate) and point the default database connection at a
    // non-existent driver: any process spawn, socket open, file read, or DB
    // connection attempt during the fake's invocation throws or increments
    // a counter, failing this test.
    require_once __DIR__.'/../../Support/FakeRankingProviderEntryPointPatches.php';
    $GLOBALS['__fake_provider_io'] = ['process' => 0, 'network' => 0, 'media_file' => 0];
    config(['database.default' => 'entry_point_forbidden_driver']);

    $provider = new FakeRankingProvider;
    $candidates = fakeProviderCandidates();

    $first = $provider->rank($candidates, FAKE_PROTOTYPE_QUERY);
    $second = $provider->rank($candidates, FAKE_PROTOTYPE_QUERY);
    $identity = $provider->getModelIdentity();

    // Pure computation only: deterministic results and fixed identity with
    // zero entry-point access.
    expect($second)->toBe($first);
    expect($identity)->toBe([
        'model_id' => 'fake-ranking-v1',
        'model_revision' => 'v1.0.0',
        'provider_name' => 'fake_ranking_provider',
    ]);
    expect($GLOBALS['__fake_provider_io']['process'])->toBe(0);
    expect($GLOBALS['__fake_provider_io']['network'])->toBe(0);
    expect($GLOBALS['__fake_provider_io']['media_file'])->toBe(0);
});
