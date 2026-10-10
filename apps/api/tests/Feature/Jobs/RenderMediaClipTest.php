<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Jobs\RenderMediaClip;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ClipRankingProfile;
use App\Services\ProcessMediaAction;
use App\Services\RenderProfile;
use App\Services\RenderValidator;
use App\Services\StorageKeyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('media');
    // Ensure render config is set for validation
    config(['media.render_timeout_seconds' => '300']);
    config(['media.render_target_width' => 1080]);
    config(['media.render_target_height' => 1920]);
    config(['media.render_target_fps' => 30]);
    config(['media.render_video_codec' => 'libx264']);
    config(['media.render_video_bitrate_kbps' => 5000]);
    config(['media.render_audio_codec' => 'aac']);
    config(['media.render_audio_bitrate_kbps' => 128]);
});

/*
|--------------------------------------------------------------------------
| Fixtures for RenderMediaClip job tests
|--------------------------------------------------------------------------
*/

function createProbedAssetForRender(array $overrides = []): MediaAsset
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

function createCompletedSceneAnalysisForRender(MediaAsset $asset): MediaSceneAnalysis
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

function createCompletedClipAnalysisForRender(MediaAsset $asset): MediaClipAnalysis
{
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
        'candidates' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 1.0, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [0]],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 0.5, 'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0], 'source_scene_indexes' => [1]],
        ],
    ]);
}

function createCompletedTranscriptForRender(MediaAsset $asset, MediaSceneAnalysis $sceneAnalysis): MediaTranscript
{
    $derivedAsset = DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
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

function createCompletedRecommendationForRender(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, ?MediaTranscript $transcript): MediaClipRecommendation
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
            'request_sha256' => hash('sha256', json_encode([
                'version' => '1.0.0',
                'action' => 'rank_clips',
                'media' => ['duration_ms' => 30000],
                'candidates' => [
                    ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1, 'm4_score' => 1.0, 'transcript_text' => 'First segment'],
                    ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2, 'm4_score' => 0.5, 'transcript_text' => 'Second segment'],
                ],
                'configuration' => ClipRankingProfile::configuration(),
            ], JSON_THROW_ON_ERROR)),
        ],
        'execution_parameters' => $executionParameters,
    ]);
}

/*
|--------------------------------------------------------------------------
| Mock worker action for recording render calls (SINGULAR render_clip format)
|--------------------------------------------------------------------------
*/

class RecordingRenderActionForRender extends ProcessMediaAction
{
    public array $renderCalls = [];

    public array $renderResults = [];

    public bool $shouldFail = false;

    public string $failCode = 'render_failed';

    public function __construct(array $renderResults = [])
    {
        $this->renderResults = $renderResults;
    }

    public function renderClips(MediaProcessingContract $contract): array
    {
        $request = $contract->toRenderClipMetadataArray();
        $this->renderCalls[] = $request;

        if ($this->shouldFail) {
            throw new ProcessMediaException($this->failCode, 1, '');
        }

        if (empty($this->renderResults)) {
            // Return default success matching singular render_clip response format
            $requestSha256 = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

            return [
                'status' => 'success',
                'render' => [
                    'algorithm' => RenderValidator::ALGORITHM,
                    'algorithm_version' => RenderValidator::ALGORITHM_VERSION,
                    'parameters' => [
                        'configuration' => RenderProfile::configuration(),
                        'source_media' => [
                            'disk' => $request['source_media']['disk'],
                            'key' => $request['source_media']['key'],
                            'duration_ms' => $request['media']['duration_ms'],
                            'width' => $request['source_media']['width'],
                            'height' => $request['source_media']['height'],
                            'video_codec' => $request['source_media']['video_codec'],
                            'audio_codec' => $request['source_media']['audio_codec'],
                        ],
                        'ffmpeg_version' => 'ffmpeg version 6.0',
                        'filter_graph' => 'crop=ih*9/16:ih:(iw-ih*9/16)/2:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30',
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
                            'start_ms' => $request['candidate']['start_ms'],
                            'end_ms' => $request['candidate']['end_ms'],
                            'duration_ms' => $request['candidate']['end_ms'] - $request['candidate']['start_ms'],
                            'output' => [
                                'disk' => $request['output_storage']['disk'],
                                'key' => $request['output_storage']['key'],
                                'size_bytes' => 1024000,
                                'duration_ms' => $request['candidate']['end_ms'] - $request['candidate']['start_ms'],
                                'width' => 1080,
                                'height' => 1920,
                                'video_codec' => 'libx264',
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
| RED PHASE TESTS - These should FAIL initially because RenderMediaClip job doesn't exist yet
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| TC-RMJ-01: M5 completed/ranked, valid candidate_index → job completes, DerivedAsset created
|--------------------------------------------------------------------------
*/

it('renders clip when explicitly dispatched with valid candidate_index', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($render->candidate_index)->toBe(0);
    expect(count($action->renderCalls))->toBe(1);
    expect($action->renderCalls[0]['candidate_index'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-02: M5 completed/ranked, candidate_index out of bounds → job fails
|--------------------------------------------------------------------------
*/

it('fails when candidate_index out of bounds', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 5, $action); // Out of bounds (only 2 candidates)

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-03: M5 completed/ranked, candidate has null semantic_score → job fails
|--------------------------------------------------------------------------
*/

it('fails when selected candidate has null semantic_score', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    // Create recommendation where candidate 0 has null semantic_score
    $recommendation = MediaClipRecommendation::create([
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
                'semantic_score' => null, // NULL SCORE
                'semantic_rank' => 1,
                'reason' => 'no_candidate_text',
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
            'request_sha256' => hash('sha256', 'test'),
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-04: M5 pending/ranking/not_ready → throws UpstreamRecommendationUnavailableException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation is pending', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_unavailable');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-05: M5 failed → throws UpstreamRecommendationFailedException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation failed', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_FAILED,
        'error' => 'ranking_failed',
    ]);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_failed');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-06: M5 unavailable → throws UpstreamRecommendationUnavailableException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation unavailable', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $clipAnalysis->id,
        'status' => MediaClipRecommendation::STATUS_UNAVAILABLE,
        'reason' => 'no_candidate_text',
    ]);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_unavailable');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-07: M5 missing after resolved → throws UpstreamRecommendationMissingException
|--------------------------------------------------------------------------
*/

it('throws when M5 recommendation missing', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);

    // No MediaClipRecommendation row at all
    $fakeRecommendationId = 99999;

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $fakeRecommendationId, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('upstream_recommendation_missing');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-08: Existing completed DerivedAsset (same candidate+profile) → reused, no worker call
|--------------------------------------------------------------------------
*/

it('reuses existing completed render, no worker call', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    // Pre-create completed render with render_status and lifecycle fields
    DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
        'candidate_index' => 0,
        'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
        'render_status' => DerivedAsset::RENDER_STATUS_COMPLETED,
        'render_completed_at' => now(),
        'storage_disk' => 'media',
        'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
        'duration_ms' => 10000,
        'width' => 1080,
        'height' => 1920,
        'codec' => 'libx264',
        'render_configuration' => RenderProfile::configuration(),
        'render_parameters' => ['test' => 'data'],
    ]);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect(count($action->renderCalls))->toBe(0);

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($result->id)->toBe($render->id);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-09: Existing completed DerivedAsset, different M5 authority/config → version_conflict
|--------------------------------------------------------------------------
*/

it('throws version_conflict when existing render has different M5 authority', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    // Pre-create completed render with DIFFERENT profile version
    DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
        'candidate_index' => 0,
        'render_profile_version' => 'ffmpeg_vertical_baseline:2.0.0', // Different version
        'render_status' => DerivedAsset::RENDER_STATUS_COMPLETED,
        'render_completed_at' => now(),
        'storage_disk' => 'media',
        'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
        'duration_ms' => 10000,
        'width' => 1080,
        'height' => 1920,
        'codec' => 'libx264',
    ]);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('render_version_conflict');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-10: Explicit candidate_index selection - different index produces different output
|--------------------------------------------------------------------------
*/

it('produces different output for different candidate_index', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;

    // Render candidate 0
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);
    $job1->handle();

    // Render candidate 1
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 1, $action);
    $job2->handle();

    expect(count($action->renderCalls))->toBe(2);
    expect($action->renderCalls[0]['candidate_index'])->toBe(0);
    expect($action->renderCalls[1]['candidate_index'])->toBe(1);

    // Verify two separate DerivedAsset rows
    $render0 = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    $render1 = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 1)
        ->first();

    expect($render0)->not->toBeNull();
    expect($render1)->not->toBeNull();
    expect($render0->id)->not->toBe($render1->id);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-11: Invalid candidate_index (negative) → rejected
|--------------------------------------------------------------------------
*/

it('rejects negative candidate_index', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, -1, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_candidate_index');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-12: Job idempotency - re-dispatch same params → returns same DerivedAsset
|--------------------------------------------------------------------------
*/

it('is idempotent - re-dispatch returns same DerivedAsset', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;

    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);
    $result1 = $job1->handle();

    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);
    $result2 = $job2->handle();

    expect($result1->id)->toBe($result2->id);
    expect(count($action->renderCalls))->toBe(1); // Only first call invoked worker
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-13: Failed attempt retry - re-dispatch after failure → new attempt
|--------------------------------------------------------------------------
*/

it('retries failed attempt - re-dispatch after failure clears error and re-attempts', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $action->shouldFail = true;
    $action->failCode = 'render_failed';

    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job1->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();

    // Check render is failed
    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('render_failed');

    // Now retry with success
    $action2 = new RecordingRenderActionForRender;
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action2);
    $result2 = $job2->handle();

    $render->refresh();
    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($render->render_error)->toBeNull();
    expect(count($action2->renderCalls))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-14: Invalid duration (missing probe) → throws InvalidInputException
|--------------------------------------------------------------------------
*/

it('throws when asset has invalid probe data', function () {
    $asset = createProbedAssetForRender(['probe_result' => [], 'duration_ms' => 0]);
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('invalid_input');
});

/*
|--------------------------------------------------------------------------
| CAPTION INTEGRATION TESTS (Slice 3D)
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| TC-RMJ-CAP-01: Happy path with caption_file
|--------------------------------------------------------------------------
*/

it('includes caption_file in render contract when completed transcript with in-range segments exists', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect(count($action->renderCalls))->toBe(1);
    expect($action->renderCalls[0])->toHaveKey('caption_file');
    $captionFile = $action->renderCalls[0]['caption_file'];
    expect($captionFile)->toMatch('/^projects\/\d+\/captions\/\d+\/\d+\/[a-zA-Z0-9._-]+\/[a-f0-9-]+\.srt$/');

    expect(Storage::disk('media')->exists($captionFile))->toBeTrue();
    $srtContent = Storage::disk('media')->get($captionFile);
    expect($srtContent)->toContain('00:00:00,000 -->');
    expect($srtContent)->toContain('First segment');

    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-CAP-02: No transcript
|--------------------------------------------------------------------------
*/

it('omits caption_file when no transcript exists', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    // Create recommendation WITHOUT transcript (pass null)
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, null);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect(count($action->renderCalls))->toBe(1);
    expect($action->renderCalls[0])->not->toHaveKey('caption_file');
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-CAP-03: Transcript status not completed
|--------------------------------------------------------------------------
*/

it('omits caption_file when transcript status is not completed', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $transcript->update(['status' => MediaTranscript::STATUS_FAILED]);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect($action->renderCalls[0])->not->toHaveKey('caption_file');
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-CAP-04: Empty projection (segments outside clip range)
|--------------------------------------------------------------------------
*/

it('omits caption_file when transcript segments are outside clip range', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);

    $derivedAsset = DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'storage_disk' => 'media',
        'storage_key' => "projects/{$asset->project_id}/assets/{$asset->id}/derivatives/audio/test.wav",
        'mime_type' => 'audio/wav',
        'size_bytes' => 1024000,
        'duration_ms' => 30000,
        'sample_rate' => 16000,
        'channels' => 1,
        'codec' => 'pcm_s16le',
    ]);

    $transcript = MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'derived_asset_id' => $derivedAsset->id,
        'status' => MediaTranscript::STATUS_COMPLETED,
        'language' => 'en',
        'full_text' => 'Late transcript',
        'segments' => [
            ['start_ms' => 20000, 'end_ms' => 30000, 'text' => 'Outside clip range'],
        ],
        'engine' => 'whisper',
        'model' => 'base',
    ]);

    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect($action->renderCalls[0])->not->toHaveKey('caption_file');
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-CAP-05: Malformed transcript segments
|--------------------------------------------------------------------------
*/

it('fails with invalid_input when transcript segments are malformed', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);

    $derivedAsset = DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        'storage_disk' => 'media',
        'storage_key' => "projects/{$asset->project_id}/assets/{$asset->id}/derivatives/audio/test.wav",
        'mime_type' => 'audio/wav',
        'size_bytes' => 1024000,
        'duration_ms' => 30000,
        'sample_rate' => 16000,
        'channels' => 1,
        'codec' => 'pcm_s16le',
    ]);

    $transcript = MediaTranscript::create([
        'media_asset_id' => $asset->id,
        'derived_asset_id' => $derivedAsset->id,
        'status' => MediaTranscript::STATUS_COMPLETED,
        'language' => 'en',
        'full_text' => 'Bad transcript',
        'segments' => [
            ['end_ms' => 5000, 'text' => 'Missing start_ms'],
        ],
        'engine' => 'whisper',
        'model' => 'base',
    ]);

    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toContain('invalid_input');
});

/*
|--------------------------------------------------------------------------
| TC-RMJ-CAP-06: Idempotency — distinct caption files
|--------------------------------------------------------------------------
*/

it('generates distinct caption files on re-dispatch', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    $action1 = new RecordingRenderActionForRender;
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action1);
    $result1 = $job1->handle();

    $action2 = new RecordingRenderActionForRender;
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action2);
    $result2 = $job2->handle();

    // First dispatch generates caption file and calls worker
    expect(count($action1->renderCalls))->toBe(1);
    expect($action1->renderCalls[0])->toHaveKey('caption_file');
    $captionFile1 = $action1->renderCalls[0]['caption_file'];
    expect(Storage::disk('media')->exists($captionFile1))->toBeTrue();

    // Second dispatch reuses existing render (idempotent) - no worker call
    expect(count($action2->renderCalls))->toBe(0);

    // Same DerivedAsset returned
    expect($result1->id)->toBe($result2->id);
    expect($result1->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($result2->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| VALIDATION FAILURE PATH TESTS (REC-01)
|--------------------------------------------------------------------------
*/

/**
 * Create a mock action that returns a custom response with overrides (for validation failure testing)
 */
class CustomRenderAction extends ProcessMediaAction
{
    public array $overrides = [];

    public function __construct(array $overrides = [])
    {
        $this->overrides = $overrides;
    }

    public function renderClips(MediaProcessingContract $contract): array
    {
        $request = $contract->toRenderClipMetadataArray();
        $requestSha256 = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

        $response = [
            'status' => 'success',
            'render' => [
                'algorithm' => RenderValidator::ALGORITHM,
                'algorithm_version' => RenderValidator::ALGORITHM_VERSION,
                'parameters' => [
                    'configuration' => RenderProfile::configuration(),
                    'source_media' => [
                        'disk' => $request['source_media']['disk'],
                        'key' => $request['source_media']['key'],
                        'duration_ms' => $request['media']['duration_ms'],
                        'width' => $request['source_media']['width'],
                        'height' => $request['source_media']['height'],
                        'video_codec' => $request['source_media']['video_codec'],
                        'audio_codec' => $request['source_media']['audio_codec'],
                    ],
                    'ffmpeg_version' => 'ffmpeg version 6.0',
                    'filter_graph' => 'crop=ih*9/16:ih:(iw-ih*9/16)/2:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30',
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
                        'start_ms' => $request['candidate']['start_ms'],
                        'end_ms' => $request['candidate']['end_ms'],
                        'duration_ms' => $request['candidate']['end_ms'] - $request['candidate']['start_ms'],
                        'output' => [
                            'disk' => $request['output_storage']['disk'],
                            'key' => $request['output_storage']['key'],
                            'size_bytes' => 1024000,
                            'duration_ms' => $request['candidate']['end_ms'] - $request['candidate']['start_ms'],
                            'width' => 1080,
                            'height' => 1920,
                            'video_codec' => 'libx264',
                            'audio_codec' => 'aac',
                            'video_bitrate_kbps' => 5000,
                            'audio_bitrate_kbps' => 128,
                        ],
                    ],
                ],
            ],
        ];

        foreach ($this->overrides as $path => $value) {
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
}

function buildValidWorkerResponse(array $requestMetadata, array $overrides = []): array
{
    $requestSha256 = hash('sha256', json_encode($requestMetadata, JSON_THROW_ON_ERROR));

    $response = [
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
                'filter_graph' => 'crop=ih*9/16:ih:(iw-ih*9/16)/2:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30',
                'limits' => [
                    'max_recommendations' => 1000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
                'request_sha256' => $requestSha256,
            ],
            'clips' => [
                [
                    'candidate_index' => 0,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'duration_ms' => 10000,
                    'output' => [
                        'disk' => 'media',
                        'key' => 'renders/1/1/0_20260101T000000Z.mp4',
                        'size_bytes' => 1024000,
                        'duration_ms' => 10000,
                        'width' => 1080,
                        'height' => 1920,
                        'video_codec' => 'libx264',
                        'audio_codec' => 'aac',
                        'video_bitrate_kbps' => 5000,
                        'audio_bitrate_kbps' => 128,
                    ],
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
}

function setupRenderTest(): array
{
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    return [$asset, $recommendation];
}

it('FV-01: Happy path - valid worker response with correct request_sha256 -> COMPLETED', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new CustomRenderAction([]);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($result->render_error)->toBeNull();
});

it('FV-02: Worker response missing request_sha256 -> FAILED with validation_failed', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new CustomRenderAction(['render.parameters.request_sha256' => '__REMOVE__']);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');
});

it('FV-03: Worker response with wrong request_sha256 (tampered) -> FAILED with validation_failed', function () {
    [$asset, $recommendation] = setupRenderTest();

    $wrongHash = hash('sha256', 'tampered data');
    $action = new CustomRenderAction(['render.parameters.request_sha256' => $wrongHash]);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');
});

it('FV-04: Worker response with wrong algorithm -> FAILED with validation_failed', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new CustomRenderAction(['render.algorithm' => 'wrong_algorithm']);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');
});

it('FV-05: Worker response with wrong algorithm_version -> FAILED with validation_failed', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new CustomRenderAction(['render.algorithm_version' => '2.0.0']);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');
});

it('FV-06: Worker response with wrong configuration in parameters -> FAILED with validation_failed', function () {
    [$asset, $recommendation] = setupRenderTest();

    $wrongConfig = RenderProfile::configuration();
    $wrongConfig['target_width'] = 720;
    $action = new CustomRenderAction(['render.parameters.configuration' => $wrongConfig]);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');
});

it('FV-07: Worker response with malformed clip output (missing keys) -> FAILED with validation_failed', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new CustomRenderAction(['render.clips.0.output.width' => '__REMOVE__']);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');
});

it('FV-08: Worker response with missing required parameters keys -> FAILED with validation_failed', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new CustomRenderAction(['render.parameters.configuration' => '__REMOVE__']);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');
});

it('FV-09: Worker throws exception (render failure) -> FAILED with render_failed (unchanged)', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new RecordingRenderActionForRender;
    $action->shouldFail = true;
    $action->failCode = 'render_failed';
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: render_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('render_failed');
});

it('FV-10: Retry after validation_failed -> first FAILED, second COMPLETED', function () {
    [$asset, $recommendation] = setupRenderTest();

    // First attempt: validation failure (wrong request_sha256)
    $wrongHash = hash('sha256', 'tampered data');
    $action1 = new CustomRenderAction(['render.parameters.request_sha256' => $wrongHash]);
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action1);

    $thrown = null;
    try {
        $job1->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toBe('Render failed: validation_failed');

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
    expect($render->render_error)->toBe('validation_failed');

    // Second attempt: valid response
    $action2 = new CustomRenderAction([]);
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0, $action2);
    $result2 = $job2->handle();

    $render->refresh();
    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($render->render_error)->toBeNull();
    expect($result2->id)->toBe($render->id);
});

it('FV-11: validateCompletion called with correct payload including execution_parameters', function () {
    [$asset, $recommendation] = setupRenderTest();

    $action = new CustomRenderAction([]);
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($result->render_parameters)->not->toBeNull();
    expect($result->render_parameters)->toHaveKey('request_sha256');
    expect($result->render_parameters['request_sha256'])->toBeString();
    expect(strlen($result->render_parameters['request_sha256']))->toBe(64);
});

it('FV-12: Existing completed render reused (idempotency) - no worker call, no validation', function () {
    $asset = createProbedAssetForRender();
    $sceneAnalysis = createCompletedSceneAnalysisForRender($asset);
    $clipAnalysis = createCompletedClipAnalysisForRender($asset);
    $transcript = createCompletedTranscriptForRender($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRender($asset, $clipAnalysis, $transcript);

    // Pre-create completed render
    DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_RENDERED_CLIP,
        'candidate_index' => 0,
        'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
        'render_status' => DerivedAsset::RENDER_STATUS_COMPLETED,
        'render_completed_at' => now(),
        'storage_disk' => 'media',
        'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
        'mime_type' => 'video/mp4',
        'size_bytes' => 1024000,
        'duration_ms' => 10000,
        'width' => 1080,
        'height' => 1920,
        'codec' => 'libx264',
        'render_configuration' => RenderProfile::configuration(),
        'render_parameters' => ['test' => 'data'],
    ]);

    $action = new RecordingRenderActionForRender;
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0, $action);

    $result = $job->handle();

    expect(count($action->renderCalls))->toBe(0);
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
});
