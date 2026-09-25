<?php

namespace Tests\Feature\Models;

use App\Models\MediaAsset;
use App\Models\MediaClipRecommendation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('recommendation model has correct casts and relationships', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $model = MediaClipRecommendation::find($recommendation->id);

    // Check casts
    expect($model->getCasts()['parameters'])->toBe('array');
    expect($model->getCasts()['recommendations'])->toBe('array');
    expect($model->getCasts()['input_snapshot'])->toBe('array');
    expect($model->getCasts()['execution_parameters'])->toBe('array');
    expect($model->getCasts()['status'])->toBe('string');
    expect($model->getCasts()['algorithm'])->toBe('string');
    expect($model->getCasts()['algorithm_version'])->toBe('string');
    expect($model->getCasts()['error'])->toBe('string');

    // Check relationship
    expect($model->mediaAsset()->exists())->toBeTrue();
    expect($model->mediaAsset->id)->toBe($asset->id);
});

it('recommendation model enforces unique media_asset_id', function () {
    $asset = MediaAsset::factory()->create();

    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $this->expectException(QueryException::class);
    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
});

it('recommendation lifecycle transitions work correctly', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    // pending -> ranking
    $recommendation->update(['status' => MediaClipRecommendation::STATUS_RANKING]);
    expect($recommendation->fresh()->status)->toBe(MediaClipRecommendation::STATUS_RANKING);

    // ranking -> completed
    $recommendation->update([
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'algorithm' => 'cross_encoder_reranker',
        'algorithm_version' => '1.0.0',
        'parameters' => ['test' => 'data'],
        'recommendations' => [['m4_candidate_index' => 0, 'semantic_score' => 0.9, 'combined_rank' => 1]],
        'input_snapshot' => ['duration_ms' => 10000, 'candidates' => [], 'transcript_used' => false, 'prototype_query' => 'test'],
        'execution_parameters' => ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
    ]);
    expect($recommendation->fresh()->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($recommendation->fresh()->algorithm)->toBe('cross_encoder_reranker');
    expect($recommendation->fresh()->recommendations)->toHaveCount(1);

    // completed is terminal - cannot transition
    $recommendation->update(['status' => MediaClipRecommendation::STATUS_RANKING]);
    expect($recommendation->fresh()->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED); // unchanged
});

it('failed retry clears error and allows new attempt', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_FAILED,
        'error' => 'upstream_not_ready',
    ]);

    // failed -> ranking (retry)
    $recommendation->update([
        'status' => MediaClipRecommendation::STATUS_RANKING,
        'error' => null,
    ]);
    expect($recommendation->fresh()->status)->toBe(MediaClipRecommendation::STATUS_RANKING);
    expect($recommendation->fresh()->error)->toBeNull();
});

it('completed recommendation persists full snapshot', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'algorithm' => 'cross_encoder_reranker',
        'algorithm_version' => '1.0.0',
        'parameters' => [
            'prototype_query' => 'test query',
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
        'input_snapshot' => [
            'duration_ms' => 40000,
            'candidates' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1],
                ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2],
            ],
            'transcript_used' => true,
            'prototype_query' => 'test query',
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $fresh = $recommendation->fresh();
    expect($fresh->parameters['model_id'])->toBe('cross-encoder/ms-marco-MiniLM-L-6-v2');
    expect($fresh->recommendations)->toHaveCount(2);
    expect($fresh->input_snapshot['transcript_used'])->toBeTrue();
    expect($fresh->execution_parameters['lock_wait_seconds'])->toBe(65);
});

it('asset deletion cascades recommendation', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
    ]);

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeTrue();

    $asset->delete();

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeFalse();
});

it('separate assets have isolated recommendations', function () {
    $asset1 = MediaAsset::factory()->create();
    $asset2 = MediaAsset::factory()->create();

    MediaClipRecommendation::create([
        'media_asset_id' => $asset1->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
    ]);
    MediaClipRecommendation::create([
        'media_asset_id' => $asset2->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
    ]);

    expect(MediaClipRecommendation::where('media_asset_id', $asset1->id)->count())->toBe(1);
    expect(MediaClipRecommendation::where('media_asset_id', $asset2->id)->count())->toBe(1);
});

it('pending fields have no completed result defaults', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $fresh = $recommendation->fresh();
    expect($fresh->algorithm)->toBeNull();
    expect($fresh->algorithm_version)->toBeNull();
    expect($fresh->parameters)->toBeNull();
    expect($fresh->recommendations)->toBeNull();
    expect($fresh->input_snapshot)->toBeNull();
    expect($fresh->execution_parameters)->toBeNull();
    expect($fresh->error)->toBeNull();
});

it('zero-recommendation completed row is valid', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'algorithm' => 'cross_encoder_reranker',
        'algorithm_version' => '1.0.0',
        'parameters' => [
            'prototype_query' => 'test',
            'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
            'model_revision' => 'main',
            'provider_name' => 'cross_encoder_ranking_provider',
            'transcript_used' => false,
            'normalization' => 'sigmoid',
            'score_scale' => 1.0,
            'tie_break' => 'm4_rank_then_chronological',
        ],
        'recommendations' => [],
        'input_snapshot' => [
            'duration_ms' => 10000,
            'candidates' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1]],
            'transcript_used' => false,
            'prototype_query' => 'test',
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $fresh = $recommendation->fresh();
    expect($fresh->recommendations)->toBe([]);
    expect($fresh->parameters['transcript_used'])->toBeFalse();
});
