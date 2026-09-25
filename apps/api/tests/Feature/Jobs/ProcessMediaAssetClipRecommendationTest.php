<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Test double that records ranking invocations without requiring the method to exist yet
class RecordingRankClipsAction extends ProcessMediaAction
{
    public array $rankClipsContracts = [];

    public int $rankClipsCallCount = 0;

    public function __construct(
        private MediaAsset $asset,
        private array $response,
    ) {}

    public function rankClips(MediaProcessingContract $contract): array
    {
        $this->rankClipsCallCount++;
        $this->rankClipsContracts[] = $contract->toArray();

        return $this->response;
    }
}

it('invokes ranking when M4 clip analysis is completed and transcript is ready', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440010';
    $probe = [
        'duration_ms' => 40000,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
    ];
    $scenes = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000],
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000],
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
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 800000, 'criteria' => ['duration_fit' => 1], 'source_scene_indexes' => [1]],
    ], 40000);

    // Transcript completed
    $transcript = MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'status' => MediaTranscript::STATUS_PENDING,
    ]);
    $transcript->markTranscribing();
    $transcript->markCompleted('whisper', 'base', 'en', [
        ['start_ms' => 500, 'end_ms' => 9500, 'text' => 'engaging content here'],
        ['start_ms' => 10500, 'end_ms' => 19500, 'text' => 'more content'],
    ]);

    // Golden worker response for ranking
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
                ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 2],
            ],
        ],
    ];

    $action = Mockery::mock(RecordingRankClipsAction::class, [$asset, $workerResponse])->makePartial();
    $action->shouldNotReceive('probe');
    $action->shouldNotReceive('detectScenes');
    $action->shouldNotReceive('extractAudio');
    $action->shouldNotReceive('transcribe');
    $action->shouldNotReceive('analyzeClips');
    app()->instance(ProcessMediaAction::class, $action);

    // Verify ready data really exists before invoking the production entry point.
    expect($asset->fresh()->processing_status)->toBe(MediaAsset::PROCESSING_PROBED);
    expect($clipAnalysis->fresh()->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    expect($transcript->fresh()->status)->toBe(MediaTranscript::STATUS_COMPLETED);

    // This will fail because ProcessMediaAsset doesn't invoke ranking yet (RED)
    (new ProcessMediaAsset($asset, $idempotencyKey))->handle();

    // Assert ranking was invoked exactly once
    $this->assertEquals(
        1,
        $action->rankClipsCallCount,
        'Ready M4 clip analysis with completed transcript must invoke rankClips exactly once.',
    );

    // Assert contract shape
    $this->assertEquals([
        'version' => '1.0.0',
        'action' => 'rank_clips',
        'media' => ['duration_ms' => 40000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000],
        ],
        'configuration' => [
            'prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
        ],
        'transcript_segments' => [
            ['start_ms' => 500, 'end_ms' => 9500],
            ['start_ms' => 10500, 'end_ms' => 19500],
        ],
    ], $action->rankClipsContracts[0]);
});

it('returns not-ready when transcript is pending and does not invoke ranking', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440011';
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

    // Transcript is PENDING (not ready)
    $transcript = MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'status' => MediaTranscript::STATUS_PENDING,
    ]);

    $action = Mockery::mock(RecordingRankClipsAction::class, [$asset, []])->makePartial();
    $action->shouldNotReceive('probe');
    $action->shouldNotReceive('detectScenes');
    $action->shouldNotReceive('extractAudio');
    $action->shouldNotReceive('transcribe');
    $action->shouldNotReceive('analyzeClips');
    app()->instance(ProcessMediaAction::class, $action);

    // This will throw upstream_not_ready because transcript is not ready (RED)
    $this->expectException(ProcessMediaException::class);
    $this->expectExceptionMessage('upstream_not_ready');

    (new ProcessMediaAsset($asset, $idempotencyKey))->handle();

    // Verify ranking was NOT invoked
    $this->assertEquals(0, $action->rankClipsCallCount);
});

it('returns not-ready when transcript is transcribing and does not invoke ranking', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440012';
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

    // Transcript is TRANSCRIBING (not ready)
    $transcript = MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'status' => MediaTranscript::STATUS_PENDING,
    ]);
    $transcript->markTranscribing();

    $action = Mockery::mock(RecordingRankClipsAction::class, [$asset, []])->makePartial();
    $action->shouldNotReceive('probe');
    $action->shouldNotReceive('detectScenes');
    $action->shouldNotReceive('extractAudio');
    $action->shouldNotReceive('transcribe');
    $action->shouldNotReceive('analyzeClips');
    app()->instance(ProcessMediaAction::class, $action);

    // This will throw upstream_not_ready because transcript is not ready (RED)
    $this->expectException(ProcessMediaException::class);
    $this->expectExceptionMessage('upstream_not_ready');

    (new ProcessMediaAsset($asset, $idempotencyKey))->handle();

    // Verify ranking was NOT invoked
    $this->assertEquals(0, $action->rankClipsCallCount);
});
