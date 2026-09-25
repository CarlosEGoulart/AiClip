<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ProcessMediaAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Test double that records ranking invocations with a barrier for synchronization
class BarrierRankClipsAction extends ProcessMediaAction
{
    public static array $barrier = [];

    public static int $callCount = 0;

    public array $rankClipsContracts = [];

    public int $rankClipsCallCount = 0;

    public function __construct(
        private MediaAsset $asset,
        private array $response,
    ) {}

    public function rankClips(MediaProcessingContract $contract): array
    {
        self::$callCount++;
        $this->rankClipsCallCount++;
        $this->rankClipsContracts[] = $contract->toArray();

        // Wait at barrier to allow concurrent test to proceed
        if (isset(self::$barrier['wait'])) {
            $start = microtime(true);
            while (! isset(self::$barrier['release']) && (microtime(true) - $start) < 10) {
                usleep(10000);
            }
        }

        return $this->response;
    }

    public static function resetBarrier(): void
    {
        self::$barrier = [];
        self::$callCount = 0;
    }

    public static function setBarrierWait(): void
    {
        self::$barrier['wait'] = true;
        self::$barrier['release'] = false;
    }

    public static function releaseBarrier(): void
    {
        self::$barrier['release'] = true;
    }
}

it('arbitrates concurrent first creation - only one worker runs, second reuses completed result', function () {
    BarrierRankClipsAction::resetBarrier();
    BarrierRankClipsAction::setBarrierWait();

    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440020';
    $probe = [
        'duration_ms' => 40000,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];
    $scenes = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000],
    ];

    $asset = MediaAsset::factory()->create();
    $asset->markQueued($idempotencyKey);
    $asset->markProcessing();
    $asset->markProbed($probe, 40000);

    // Scene detection completed
    $sceneAnalysis = MediaSceneAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaSceneAnalysis::STATUS_PENDING,
    ]);
    $sceneAnalysis->markDetecting();
    $sceneAnalysis->markCompleted('deterministic', '0.0.0', ['threshold' => 27], $scenes, 40000);

    // M4 clip analysis completed
    $clipAnalysis = MediaClipAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipAnalysis::STATUS_PENDING,
    ]);
    $clipAnalysis->markAnalyzing();
    $clipAnalysis->markCompleted('scene_timing_baseline', '1.0.0', [
        'configuration' => ['min_duration_ms' => 5000, 'target_duration_ms' => 10000],
        'candidate_policy' => 'whole_scene_non_overlapping',
    ], [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 1000000, 'criteria' => ['duration_fit' => 1], 'source_scene_indexes' => [0]],
    ], 40000);

    // Transcript completed
    $transcript = MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'status' => MediaTranscript::STATUS_PENDING,
    ]);
    $transcript->markTranscribing();
    $transcript->markCompleted('whisper', 'base', 'en', [
        ['start_ms' => 500, 'end_ms' => 9500, 'text' => 'engaging content'],
    ]);

    $workerResponse = [
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
            ],
        ],
    ];

    // First process - will hold at barrier
    $action1 = Mockery::mock(BarrierRankClipsAction::class, [$asset, $workerResponse])->makePartial();
    $action1->shouldNotReceive('probe');
    $action1->shouldNotReceive('detectScenes');
    $action1->shouldNotReceive('extractAudio');
    $action1->shouldNotReceive('transcribe');
    $action1->shouldNotReceive('analyzeClips');
    app()->instance(ProcessMediaAction::class, $action1);

    // Run first job in background (simulate via direct call with barrier)
    $job1 = new ProcessMediaAsset($asset, $idempotencyKey);

    // Since we can't easily run true parallel processes in Pest,
    // we test the database-level arbitration by checking the recommendation row creation
    // The actual concurrency test with real PostgreSQL would need separate processes

    // For now, verify the recommendation model doesn't exist yet (will be created by job)
    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeFalse();

    // This test requires real PostgreSQL with separate connections/processes
    // which is not available in this test environment
    $this->markTestSkipped('Requires real PostgreSQL with separate connections for true concurrency test');
});

it('verifies recommendation row has unique media_asset_id constraint', function () {
    $asset1 = MediaAsset::factory()->create();
    $asset2 = MediaAsset::factory()->create();

    // Create recommendation for asset1
    MediaClipRecommendation::create([
        'media_asset_id' => $asset1->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    // Attempting to create another for same asset should fail
    $this->expectException(QueryException::class);
    MediaClipRecommendation::create([
        'media_asset_id' => $asset1->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    // But different asset should work
    MediaClipRecommendation::create([
        'media_asset_id' => $asset2->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
});
