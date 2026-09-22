<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Jobs\ProcessMediaAsset;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Declare only the future worker boundary in tests, not a future model or table.
class RecordingClipAnalysisAction extends ProcessMediaAction
{
    public array $analysisContracts = [];

    public array $assetStatusesAtAnalysis = [];

    public function __construct(
        private MediaAsset $asset,
        private array $response,
    ) {}

    public function analyzeClips(MediaProcessingContract $contract): array
    {
        $this->analysisContracts[] = $contract->toArray();
        $this->assetStatusesAtAnalysis[] = $this->asset->fresh()->processing_status;

        return $this->response;
    }
}

it('invokes clip analysis once for persisted ready scenes without audio before completing the asset', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    $probe = [
        'duration_ms' => 30000,
        'video_codec' => 'h264',
        'audio_codec' => null,
    ];
    $scenes = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 30000],
    ];
    $configuration = [
        'min_duration_ms' => 5000,
        'target_duration_ms' => 30000,
        'max_duration_ms' => 60000,
        'max_candidates' => 20,
        'weights' => [
            'duration_fit' => 50,
            'speech_coverage' => 30,
            'boundary_alignment' => 20,
        ],
    ];

    $asset = MediaAsset::factory()->create();
    $asset->markQueued($idempotencyKey);
    $asset->markProcessing();
    $asset->markProbed($probe, 30000);

    $sceneAnalysis = MediaSceneAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaSceneAnalysis::STATUS_PENDING,
    ]);
    $sceneAnalysis->markDetecting();
    $sceneAnalysis->markCompleted('deterministic', '0.0.0', ['threshold' => 27], $scenes, 30000);
    $originalScenes = $sceneAnalysis->fresh()->getRawOriginal();

    // Hand-derived golden response: a target-length whole scene scores 1.
    // Unavailable transcription contributes neither criteria nor effective weight.
    $workerResponse = [
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'configuration' => $configuration,
                'effective_weights' => [
                    'duration_fit' => 50,
                    'speech_coverage' => 0,
                    'boundary_alignment' => 0,
                ],
                'transcript_used' => false,
                'candidate_policy' => 'whole_scene_non_overlapping',
                'timing_policy' => 'original_media_ms',
                'transcript_policy' => 'optional_strict_unshifted',
                'boundary_policy' => 'strict_interior_speech_cut',
                'score_scale' => 1000000,
                'rounding' => 'half_up',
                'limits' => [
                    'max_scenes' => 10000,
                    'max_transcript_segments' => 50000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
            ],
            'candidates' => [
                [
                    'index' => 0,
                    'start_ms' => 0,
                    'end_ms' => 30000,
                    'rank' => 1,
                    'score' => 1,
                    'criteria' => [
                        'duration_fit' => 1,
                        'speech_coverage' => 0,
                        'boundary_alignment' => 0,
                    ],
                    'source_scene_indexes' => [0],
                ],
            ],
        ],
    ];

    $action = Mockery::mock(RecordingClipAnalysisAction::class, [$asset, $workerResponse])->makePartial();
    $action->shouldNotReceive('probe');
    $action->shouldNotReceive('detectScenes');
    $action->shouldNotReceive('extractAudio');
    $action->shouldNotReceive('transcribe');
    app()->instance(ProcessMediaAction::class, $action);

    // Verify ready data really exists before invoking the production entry point.
    expect($asset->fresh()->processing_status)->toBe(MediaAsset::PROCESSING_PROBED);
    expect($asset->fresh()->duration_ms)->toBe(30000);
    expect($asset->fresh()->probe_result)->toBe($probe);
    expect($sceneAnalysis->fresh()->status)->toBe(MediaSceneAnalysis::STATUS_COMPLETED);
    expect($sceneAnalysis->fresh()->scenes)->toBe($scenes);

    (new ProcessMediaAsset($asset, $idempotencyKey))->handle();

    $this->assertCount(
        1,
        $action->analysisContracts,
        'Ready persisted scenes without audio must invoke analyzeClips exactly once.',
    );
    expect($action->assetStatusesAtAnalysis)->toBe([MediaAsset::PROCESSING_PROBED]);
    $this->assertEquals([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 30000],
        'scenes' => $scenes,
        'configuration' => $configuration,
    ], $action->analysisContracts[0]);
    expect($asset->fresh()->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
    expect($asset->fresh()->probe_result)->toBe($probe);
    expect($sceneAnalysis->fresh()->getRawOriginal())->toBe($originalScenes);
    expect(DerivedAsset::where('media_asset_id', $asset->id)->exists())->toBeFalse();
    expect(MediaTranscript::where('media_asset_id', $asset->id)->exists())->toBeFalse();
});

it('resolves clip analysis as upstream_scene_missing for no-video applicability and completes the asset', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440001';
    // Probe has no video_codec key, so the scene stage is skipped as not applicable.
    // audio_codec null keeps the audio path resolved (no-audio short-circuit).
    $probe = [
        'duration_ms' => 5000,
        'audio_codec' => null,
    ];

    $asset = MediaAsset::factory()->create();
    $asset->markQueued($idempotencyKey);
    $asset->markProcessing();
    $asset->markProbed($probe, 5000);

    // No MediaSceneAnalysis row exists; the scene stage will be skipped.
    expect(MediaSceneAnalysis::where('media_asset_id', $asset->id)->exists())->toBeFalse();

    $action = Mockery::mock(RecordingClipAnalysisAction::class, [$asset, []])->makePartial();
    $action->shouldNotReceive('probe');
    $action->shouldNotReceive('detectScenes');
    $action->shouldNotReceive('extractAudio');
    $action->shouldNotReceive('transcribe');
    app()->instance(ProcessMediaAction::class, $action);

    expect($asset->fresh()->processing_status)->toBe(MediaAsset::PROCESSING_PROBED);

    (new ProcessMediaAsset($asset, $idempotencyKey))->handle();

    // Analyzer must never be invoked for a missing scene.
    $this->assertCount(
        0,
        $action->analysisContracts,
        'No-video applicability must not invoke analyzeClips.',
    );

    // A controlled failed attempt with upstream_scene_missing must be persisted.
    $clipAnalysis = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
    expect($clipAnalysis)->not->toBeNull();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_FAILED);
    expect($clipAnalysis->error)->toBe('upstream_scene_missing');
    expect($clipAnalysis->candidates ?? [])->toBeEmpty();

    // The resolved asset must complete (controlled failure is a resolved stage).
    expect($asset->fresh()->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
});
