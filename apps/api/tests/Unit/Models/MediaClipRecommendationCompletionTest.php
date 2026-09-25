<?php

namespace Tests\Unit\Models;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaClipRecommendation;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| The completion boundary must reject the same ordering/tie-break/precision/
| type mutations the service validator rejects (no bypass). Rejections throw
| before any persistence, so an unsaved model instance with the ranking
| status exercises markCompleted() without touching the database.
|
*/

const COMPLETION_PROTOTYPE_QUERY = 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.';

function completionParameters(): array
{
    return [
        'prototype_query' => COMPLETION_PROTOTYPE_QUERY,
        'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
        'model_revision' => 'main',
        'provider_name' => 'cross_encoder_ranking_provider',
        'transcript_used' => true,
        'normalization' => 'sigmoid',
        'score_scale' => 1.0,
        'tie_break' => 'm4_rank_then_chronological',
    ];
}

function completionInputSnapshot(): array
{
    // Ranks form a permutation of 1..K that is deliberately not in position
    // order, so the ascending-M4-rank tie-break level is observable.
    return [
        'duration_ms' => 40000,
        'candidates' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 2],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 1],
        ],
        'transcript_used' => true,
        'prototype_query' => COMPLETION_PROTOTYPE_QUERY,
    ];
}

function completionExecutionParameters(): array
{
    return [
        'timeout_seconds' => 60,
        'lock_wait_seconds' => 65,
    ];
}

function rankingModel(): MediaClipRecommendation
{
    $model = new MediaClipRecommendation;
    $model->status = MediaClipRecommendation::STATUS_RANKING;

    return $model;
}

/*
|--------------------------------------------------------------------------
| Completion Boundary No-Bypass
|--------------------------------------------------------------------------
*/

it('markCompleted rejects the same mutations the service validator rejects', function (string $mutation, array $recommendations) {
    $model = rankingModel();

    $failure = null;
    try {
        $model->markCompleted(
            'cross_encoder_reranker',
            '1.0.0',
            completionParameters(),
            $recommendations,
            completionInputSnapshot(),
            completionExecutionParameters(),
        );
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    $this->assertInstanceOf(
        ProcessMediaException::class,
        $failure,
        "Completion boundary must reject the {$mutation} mutation the service validator rejects (no bypass)."
    );
})->with([
    'equal scores with M4 ranks descending' => ['equal scores with M4 ranks descending', [
        ['m4_candidate_index' => 0, 'semantic_score' => 0.9, 'combined_rank' => 1],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.9, 'combined_rank' => 2],
    ]],
    'combined_rank permutation out of position order' => ['combined_rank permutation out of position order', [
        ['m4_candidate_index' => 0, 'semantic_score' => 0.872341, 'combined_rank' => 2],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 1],
    ]],
    'numeric-string semantic_score' => ['numeric-string semantic_score', [
        ['m4_candidate_index' => 0, 'semantic_score' => '0.872341', 'combined_rank' => 1],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 2],
    ]],
    'score exceeding 6 decimals' => ['score exceeding 6 decimals', [
        ['m4_candidate_index' => 0, 'semantic_score' => 0.87234101, 'combined_rank' => 1],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 2],
    ]],
]);
