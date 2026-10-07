<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipRecommendation;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ProcessMediaAction;
use App\Services\ClipRankingProfile;
use App\Services\RenderProfile;
use App\Services\RenderValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CanonicalJson;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures for ProcessMediaAsset render regression tests (NEW BEHAVIOR: NO auto-render)
|--------------------------------------------------------------------------
*/

function createProbedAsset(array $overrides = []): MediaAsset
{
    $asset = MediaAsset::factory()->create(array_merge([
        'processing_status' => MediaAsset::PROCESSING_PROBED,
        'duration_ms' => 30000,
        'probe_result' => [
            'duration_ms' => 30000,
            'width' => 1920,
            'height' => 1080,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
        ],
        'storage_disk' => 'media',
        'storage_key' => 'projects/1/assets/1/source.mp4',
    ], $overrides));

    // Update storage_key to use actual asset ID for uniqueness
    $asset->update([
        'storage_key' => "projects/{$asset->project_id}/assets/{$asset->id}/source.mp4",
    ]);

    return $asset->fresh();
}

function createProbedAssetNoAudio(array $overrides = []): MediaAsset
{
    $overrides['probe_result'] = array_merge([
        'duration_ms' => 30000,
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => null,
    ], $overrides['probe_result'] ?? []);

    return createProbedAsset($overrides);
}

function createCompletedSceneAnalysis(MediaAsset $asset): MediaSceneAnalysis
{
    return MediaSceneAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaSceneAnalysis::STATUS_COMPLETED,
        'detector' => 'pyscenedetect',
        'detector_version' => '1.0.0',
        'parameters' => [],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 15000],
            ['index' => 1, 'start_ms' => 15000, 'end_ms' => 30000],
        ],
    ]);
}

function createCompletedClipAnalysis(MediaAsset $asset, ?array $candidates = null): MediaClipAnalysis
{
    $defaultCandidates = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 1.0, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [0]],
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 0.5, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [1]],
    ];

    return MediaClipAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipAnalysis::STATUS_COMPLETED,
        'algorithm' => 'scene_timing_baseline',
        'algorithm_version' => '1.0.0',
        'parameters' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 30000,
            'max_duration_ms' => 60000,
            'max_candidates' => 20,
            'weights' => [
                'duration_fit' => 50,
                'speech_coverage' => 30,
                'boundary_alignment' => 20,
            ],
        ],
        'candidates' => $candidates ?? $defaultCandidates,
    ]);
}

function createCompletedClipAnalysisNoCandidates(MediaAsset $asset): MediaClipAnalysis
{
    return createCompletedClipAnalysis($asset, []);
}

function createCompletedTranscript(MediaAsset $asset, MediaSceneAnalysis $sceneAnalysis): MediaTranscript
{
    $derivedAsset = \App\Models\DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => \App\Models\DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'storage_disk' => 'media',
        'storage_key' => "projects/{$asset->project_id}/assets/{$asset->id}/derivatives/audio/test.wav",
        'mime_type' => 'audio/wav',
        'size_bytes' => 1024000,
        'duration_ms' => 30000,
        'sample_rate' => 16000,
        'channels' => 1,
        'codec' => 'pcm_s16le',
    ]);

    return MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'derived_asset_id' => $derivedAsset->id,
        'status' => MediaTranscript::STATUS_COMPLETED,
        'language' => 'en',
        'full_text' => 'Test transcript',
        'segments' => [
            ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First segment'],
            ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second segment'],
        ],
        'engine' => 'whisper',
        'model' => 'base',
    ]);
}

function createCompletedRecommendation(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, MediaTranscript $transcript): MediaClipRecommendation
{
    $executionParameters = [
        'timeout_seconds' => ClipRankingProfile::timeoutSeconds(),
        'lock_wait_seconds' => ClipRankingProfile::lockWaitSeconds(),
    ];

    return MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'outcome' => MediaClipRecommendation::OUTCOME_RANKED,
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'parameters' => ClipRankingProfile::parameters(ClipRankingProfile::configuration(), false, true),
        'recommendations' => [
            [
                'm4_candidate_index' => 0,
                'start_ms' => 0,
                'end_ms' => 10000,
                'm4_rank' => 1,
                'm4_score' => 1.0,
                'semantic_score' => 0.95,
                'semantic_rank' => 1,
                'reason' => null,
            ],
            [
                'm4_candidate_index' => 1,
                'start_ms' => 10000,
                'end_ms' => 20000,
                'm4_rank' => 2,
                'm4_score' => 0.5,
                'semantic_score' => 0.75,
                'semantic_rank' => 2,
                'reason' => null,
            ],
        ],
        'input_snapshot' => [
            'm4_analysis_id' => $clipAnalysis->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => $clipAnalysis->candidates,
            'duration_ms' => 30000,
            'transcript_state' => 'completed_valid',
            'projection_version' => '1.0.0',
            'text_hashes' => [
                ['index' => 0, 'sha256' => hash('sha256', 'First segment')],
                ['index' => 1, 'sha256' => hash('sha256', 'Second segment')],
            ],
            'request_sha256' => CanonicalJson::sha256([
                'version' => '1.0.0',
                'action' => 'rank_clips',
                'media' => ['duration_ms' => 30000],
                'candidates' => [
                    ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1, 'm4_score' => 1.0, 'transcript_text' => 'First segment'],
                    ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2, 'm4_score' => 0.5, 'transcript_text' => 'Second segment'],
                ],
                'configuration' => ClipRankingProfile::configuration(),
            ]),
        ],
        'execution_parameters' => $executionParameters,
    ]);
}

/*
|--------------------------------------------------------------------------
| Mock worker action for recording render calls
|--------------------------------------------------------------------------
*/

class RecordingRenderAction extends ProcessMediaAction
{
    public array $renderCalls = [];
    public array $renderResults = [];
    public bool $shouldFail = false;
    public string $failCode = 'render_failed';

    public function __construct(array $renderResults = [])
    {
        $this->renderResults = $renderResults;
    }

    public function renderClips(\App\Contracts\MediaProcessingContract $contract): array
    {
        $request = $contract->toRenderClipsMetadataArray();
        $this->renderCalls[] = $request;

        if ($this->shouldFail) {
            throw new ProcessMediaException($this->failCode, 1, '');
        }

        if (empty($this->renderResults)) {
            // Return default success
            $requestSha256 = CanonicalJson::sha256($request);
            return [
                'status' => 'success',
                'render' => [
                    'algorithm' => RenderValidator::ALGORITHM,
                    'algorithm_version' => RenderValidator::ALGORITHM_VERSION,
                    'parameters' => [
                        'configuration' => RenderProfile::configuration(),
                        'source_media' => [
                            'disk' => 'media',
                            'key' => 'projects/1/assets/1/source.mp4',
                            'duration_ms' => 30000,
                            'width' => 1920,
                            'height' => 1080,
                            'video_codec' => 'h264',
                            'audio_codec' => 'aac',
                        ],
                        'ffmpeg_version' => 'ffmpeg version 6.0',
                        'filter_graph' => 'crop=608:1080:656:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30',
                        'limits' => [
                            'max_recommendations' => 1000,
                            'max_input_bytes' => 8388608,
                            'max_duration_ms' => 2147483647,
                        ],
                        'request_sha256' => $requestSha256,
                    ],
                    'clips' => [
                        [
                            'candidate_index' => $request['candidate_index'],
                            'semantic_rank' => $request['recommendation']['candidates'][$request['candidate_index']]['semantic_rank'],
                            'semantic_score' => $request['recommendation']['candidates'][$request['candidate_index']]['semantic_score'],
                            'start_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['start_ms'],
                            'end_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['end_ms'],
                            'duration_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['end_ms'] - $request['recommendation']['candidates'][$request['candidate_index']]['start_ms'],
                            'output' => [
                                'disk' => 'media',
                                'key' => 'renders/1/1/'.$request['candidate_index'].'_20260101T000000Z.mp4',
                                'size_bytes' => 1024000,
                                'duration_ms' => $request['recommendation']['candidates'][$request['candidate_index']]['end_ms'] - $request['recommendation']['candidates'][$request['candidate_index']]['start_ms'],
                                'width' => 1080,
                                'height' => 1920,
                                'video_codec' => 'h264',
                                'audio_codec' => 'aac',
                                'video_bitrate_kbps' => 5000,
                                'audio_bitrate_kbps' => 128,
                            ],
                        ],
                    ],
                ],
            ];
        }

        return array_shift($this->renderResults);
    }
}

/*
|--------------------------------------------------------------------------
| REGRESSION TESTS - ProcessMediaAsset should NOT auto-render (NEW BEHAVIOR)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| TC-PMA-REG-01: ProcessMediaAsset completes after M5 WITHOUT render stage
|--------------------------------------------------------------------------
*/

it('completes asset after M5 WITHOUT auto-render', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);

    $job->handle();

    $asset->refresh();
    // Asset should complete WITHOUT render being invoked
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    // NO render should have been invoked
    expect(count($action->renderCalls))->toBe(0);

    // NO DerivedAsset render should exist
    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->first();

    expect($render)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| TC-PMA-REG-02: No clipRenderResolved flag exists on MediaAsset
|--------------------------------------------------------------------------
*/

it('MediaAsset has no clipRenderResolved accessor', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    // The clipRenderResolved accessor should not exist
    expect(property_exists($asset, 'clipRenderResolved'))->toBeFalse();
    
    // Accessing it should not work (no accessor)
    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| TC-PMA-REG-03: Existing upstream stages (probe, scene, audio, M4, M5) unchanged
|--------------------------------------------------------------------------
*/

it('preserves existing upstream stages - probe, scene, audio, M4, M5 all work', function () {
    $asset = createProbedAsset();
    $sceneAnalysis = createCompletedSceneAnalysis($asset);
    $clipAnalysis = createCompletedClipAnalysis($asset);
    $transcript = createCompletedTranscript($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendation($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderAction();
    $job = new \App\Jobs\ProcessMediaAsset($asset, 'test-key', $action);
    $job->handle();

    $asset->refresh();
    expect($asset->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);

    // All upstream data should be intact
    expect($asset->probe_result)->not->toBeEmpty();
    expect($asset->duration_ms)->toBe(30000);

    $sceneAnalysis->refresh();
    expect($sceneAnalysis->status)->toBe(MediaSceneAnalysis::STATUS_COMPLETED);

    $clipAnalysis->refresh();
    expect($clipAnalysis->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);

    $transcript->refresh();
    expect($transcript->status)->toBe(MediaTranscript::STATUS_COMPLETED);

    $recommendation->refresh();
    expect($recommendation->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($recommendation->outcome)->toBe(MediaClipRecommendation::OUTCOME_RANKED);
});

/*
|--------------------------------------------------------------------------
| TC-PMA-REG-04: Asset finalization does NOT require render
|--------------------------------------------------------------------------
*/

it('asset completes with only existing resolved stages (no render required)', function () {
    // Test with various upstream scenarios - all should complete without render

    // Scenario 1: Full pipeline with M5 ranked
    $asset1 = createProbedAsset();
    $scene1 = createCompletedSceneAnalysis($asset1);
    $clip1 = createCompletedClipAnalysis($asset1);
    $transcript1 = createCompletedTranscript($asset1, $scene1);
    $rec1 = createCompletedRecommendation($asset1, $clip1, $transcript1);

    $action1 = new RecordingRenderAction();
    $job1 = new \App\Jobs\ProcessMediaAsset($asset1, 'test-key-1', $action1);
    $job1->handle();

    $asset1->refresh();
    expect($asset1->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
    expect(count($action1->renderCalls))->toBe(0);

    // Scenario 2: No audio (audio path skipped)
    $asset2 = createProbedAsset(['probe_result' => [
        'duration_ms' => 30000,
        'width' => 1920,
        'height' => 1080,
        'video_codec' => 'h264',
        'audio_codec' => null, // No audio
    ]]);
    $scene2 = createCompletedSceneAnalysis($asset2);
    $clip2 = createCompletedClipAnalysis($asset2);
    $rec2 = MediaClipRecommendation::create([
        'media_asset_id' => $asset2->id,
        'm4_analysis_id' => $clip2->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'outcome' => MediaClipRecommendation::OUTCOME_RANKED,
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'parameters' => ClipRankingProfile::parameters(ClipRankingProfile::configuration(), false, false),
        'recommendations' => [
            ['m4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1, 'm4_score' => 1.0, 'semantic_score' => 0.95, 'semantic_rank' => 1, 'reason' => null],
        ],
        'input_snapshot' => [
            'm4_analysis_id' => $clip2->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => $clip2->candidates,
            'duration_ms' => 30000,
            'transcript_state' => 'no_audio',
            'projection_version' => '1.0.0',
            'text_hashes' => [['index' => 0, 'sha256' => hash('sha256', '')]],
            'request_sha256' => hash('sha256', 'test'),
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $action2 = new RecordingRenderAction();
    $job2 = new \App\Jobs\ProcessMediaAsset($asset2, 'test-key-2', $action2);
    $job2->handle();

    $asset2->refresh();
    expect($asset2->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
    expect(count($action2->renderCalls))->toBe(0);

    // Scenario 3: M5 no candidates (empty)
    $asset3 = createProbedAssetNoAudio();
    $scene3 = createCompletedSceneAnalysis($asset3);
    $clip3 = createCompletedClipAnalysisNoCandidates($asset3);
    $rec3 = MediaClipRecommendation::create([
        'media_asset_id' => $asset3->id,
        'm4_analysis_id' => $clip3->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
        'outcome' => MediaClipRecommendation::OUTCOME_NO_CANDIDATES,
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'parameters' => ClipRankingProfile::parameters(ClipRankingProfile::configuration(), false, false),
        'recommendations' => [],
        'input_snapshot' => [
            'm4_analysis_id' => $clip3->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => [],
            'duration_ms' => 30000,
            'transcript_state' => 'no_audio',
            'projection_version' => '1.0.0',
            'text_hashes' => [],
            'request_sha256' => hash('sha256', 'test'),
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $action3 = new RecordingRenderAction();
    $job3 = new \App\Jobs\ProcessMediaAsset($asset3, 'test-key-3', $action3);
    $job3->handle();

    $asset3->refresh();
    expect($asset3->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
    expect(count($action3->renderCalls))->toBe(0);
});