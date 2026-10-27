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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Use real MinIO disk (not fake) - this is the critical difference from existing tests
    // Ensure render config is set
    config([
        'media.render_timeout_seconds' => '300',
        'media.render_target_width' => 1080,
        'media.render_target_height' => 1920,
        'media.render_target_fps' => 30,
        'media.render_video_codec' => 'libx264',
        'media.render_video_bitrate_kbps' => 5000,
        'media.render_audio_codec' => 'aac',
        'media.render_audio_bitrate_kbps' => 128,
    ]);

    // Verify MinIO is available - skip tests if not
    try {
        Storage::disk('media')->assertExists('');
    } catch (\Throwable $e) {
        $this->markTestSkipped('MinIO not available: ' . $e->getMessage());
    }
});

/*
|--------------------------------------------------------------------------
| Fixtures for E2E Caption Flow Tests
|--------------------------------------------------------------------------
*/

/**
 * Create a test asset using the real test video fixture
 */
protected function createTestAssetForE2E(): MediaAsset
{
    $asset = MediaAsset::factory()->create([
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
    ]);

    // Update storage_key to use actual asset ID for uniqueness
    $asset->update([
        'storage_key' => "projects/{$asset->project_id}/assets/{$asset->id}/source.mp4",
    ]);

    // Copy the real test video fixture to MinIO at the expected location
    // The fixture is at the Laravel app's tests/fixtures/
    $fixturePath = __DIR__ . '/../../../fixtures/caption_test_video.mp4';
    
    if (file_exists($fixturePath) && filesize($fixturePath) > 1000) {
        // Verify it's a valid video file by checking magic bytes
        $content = file_get_contents($fixturePath, false, null, 0, 12);
        if (strpos($content, 'ftyp') !== false || strpos($content, 'moov') !== false) {
            Storage::disk('media')->put($asset->storage_key, file_get_contents($fixturePath));
        } else {
            $this->markTestSkipped('Test video fixture at ' . $fixturePath . ' is not a valid MP4 file');
        }
    } else {
        $this->markTestSkipped('Test video fixture not found or too small at ' . $fixturePath . ' (need real 30s 1920x1080 h264/aac video)');
    }

    return $asset->fresh();
}

function createSceneAnalysisForE2E(MediaAsset $asset): MediaSceneAnalysis
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

function createClipAnalysisForE2E(MediaAsset $asset): MediaClipAnalysis
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

/**
 * Create a completed transcript with segments matching the test video (30s duration)
 * Candidate clip index 0: 0-10000ms
 * Expected projected segments for candidate 0: First caption segment (0-10000ms local)
 */
function createTranscriptForE2E(MediaAsset $asset): MediaTranscript
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
        'full_text' => 'First caption segment Second caption segment Third caption segment',
        'segments' => [
            ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First caption segment'],
            ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second caption segment'],
            ['start_ms' => 20000, 'end_ms' => 30000, 'text' => 'Third caption segment'],
        ],
        'engine' => 'whisper',
        'model' => 'base',
    ]);
}

function createRecommendationForE2E(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, ?MediaTranscript $transcript): MediaClipRecommendation
{
    $executionParameters = [
        'timeout_seconds' => ClipRankingProfile::timeoutSeconds(),
        'lock_wait_seconds' => ClipRankingProfile::lockWaitSeconds(),
    ];

    // Build text hashes if transcript exists
    $textHashes = [];
    if ($transcript) {
        foreach ($transcript->segments as $index => $segment) {
            $textHashes[] = ['index' => $index, 'sha256' => hash('sha256', $segment['text'])];
        }
    }

    $transcriptState = $transcript ? 'completed_valid' : 'none';

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
            'transcript_state' => $transcriptState,
            'projection_version' => '1.0.0',
            'text_hashes' => $textHashes,
            'request_sha256' => hash('sha256', 'test'),
        ],
        'execution_parameters' => $executionParameters,
    ]);
}

/*
|--------------------------------------------------------------------------
| E2E-CAP-01: Happy Path — Complete Caption Flow
|--------------------------------------------------------------------------
*/

it('completes full caption flow with real MinIO, worker, and FFmpeg', function () {
    $asset = $this->createTestAssetForE2E();
    $sceneAnalysis = createSceneAnalysisForE2E($asset);
    $clipAnalysis = createClipAnalysisForE2E($asset);
    $transcript = createTranscriptForE2E($asset);
    $recommendation = createRecommendationForE2E($asset, $clipAnalysis, $transcript);

    // Dispatch RenderMediaClip job with REAL ProcessMediaAction (no mock)
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);

    $result = $job->handle();

    // Assert job completes without exception
    expect($result)->not->toBeNull();
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);

    // Assert SRT file exists on MinIO at correct key pattern
    $srtFiles = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    expect($srtFiles)->not->toBeEmpty();

    $captionFile = $srtFiles[0];
    expect($captionFile)->toMatch('/^projects\/\d+\/captions\/\d+\/\d+\/[a-zA-Z0-9._-]+\/[a-f0-9-]+\.srt$/');

    // Assert SRT content matches expected format
    $srtContent = Storage::disk('media')->get($captionFile);
    expect($srtContent)->toContain('00:00:00,000 --> 00:00:10,000');
    expect($srtContent)->toContain('First caption segment');

    // Assert worker was invoked and contract included caption_file
    // This is validated via DerivedAsset render_parameters
    $renderParams = $result->render_parameters;
    expect($renderParams)->toHaveKey('filter_graph');
    expect($renderParams['filter_graph'])->toContain('subtitles=');

    // Assert output video has subtitle stream via FFprobe
    $outputPath = Storage::disk('media')->path($result->storage_key);
    if (file_exists($outputPath)) {
        $probeOutput = shell_exec("ffprobe -v quiet -print_format json -show_streams \"{$outputPath}\" 2>/dev/null");
        $probe = json_decode($probeOutput ?? '{}', true);
        
        $hasSubtitleStream = false;
        if (isset($probe['streams'])) {
            foreach ($probe['streams'] as $stream) {
                if (($stream['codec_type'] ?? '') === 'subtitle') {
                    $hasSubtitleStream = true;
                    break;
                }
            }
        }
        expect($hasSubtitleStream)->toBeTrue();
    }

    // Assert output video properties
    expect($result->duration_ms)->toBe(10000); // 10 seconds ± tolerance
    expect($result->width)->toBe(1080);
    expect($result->height)->toBe(1920);
    expect($renderParams)->toHaveKey('request_sha256');
    expect($renderParams['request_sha256'])->toMatch('/^[a-f0-9]{64}$/');
});

/*
|--------------------------------------------------------------------------
| E2E-CAP-02: No Transcript
|--------------------------------------------------------------------------
*/

it('renders without captions when no transcript exists', function () {
    $asset = $this->createTestAssetForE2E();
    $sceneAnalysis = createSceneAnalysisForE2E($asset);
    $clipAnalysis = createClipAnalysisForE2E($asset);
    // No transcript created
    $recommendation = createRecommendationForE2E($asset, $clipAnalysis, null);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $result = $job->handle();

    expect($result)->not->toBeNull();
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);

    // Assert NO SRT files in captions path
    $srtFiles = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    expect($srtFiles)->toBeEmpty();

    // Assert filter graph does NOT contain subtitles=
    $renderParams = $result->render_parameters;
    expect($renderParams['filter_graph'])->not->toContain('subtitles=');

    // Output video still valid
    expect($result->duration_ms)->toBe(10000);
    expect($result->width)->toBe(1080);
    expect($result->height)->toBe(1920);
});

/*
|--------------------------------------------------------------------------
| E2E-CAP-03: Transcript Status Not Completed
|--------------------------------------------------------------------------
*/

it('renders without captions when transcript status is not completed', function () {
    $asset = $this->createTestAssetForE2E();
    $sceneAnalysis = createSceneAnalysisForE2E($asset);
    $clipAnalysis = createClipAnalysisForE2E($asset);
    $transcript = createTranscriptForE2E($asset);
    $transcript->update(['status' => MediaTranscript::STATUS_PENDING]); // Not completed
    $recommendation = createRecommendationForE2E($asset, $clipAnalysis, $transcript);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $result = $job->handle();

    expect($result)->not->toBeNull();
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);

    // Assert NO SRT files
    $srtFiles = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    expect($srtFiles)->toBeEmpty();

    // Assert filter graph does NOT contain subtitles=
    $renderParams = $result->render_parameters;
    expect($renderParams['filter_graph'])->not->toContain('subtitles=');
});

/*
|--------------------------------------------------------------------------
| E2E-CAP-04: Empty Projection (Segments Outside Clip Range)
|--------------------------------------------------------------------------
*/

it('renders without captions when all transcript segments are outside clip range', function () {
    $asset = $this->createTestAssetForE2E();
    $sceneAnalysis = createSceneAnalysisForE2E($asset);
    $clipAnalysis = createClipAnalysisForE2E($asset);

    // Create transcript with segments at 20000-30000ms (candidate is 0-10000ms)
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

    $recommendation = createRecommendationForE2E($asset, $clipAnalysis, $transcript);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $result = $job->handle();

    expect($result)->not->toBeNull();
    expect($result->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);

    // Assert NO SRT files (projection yields empty)
    $srtFiles = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    expect($srtFiles)->toBeEmpty();

    // Assert filter graph does NOT contain subtitles=
    $renderParams = $result->render_parameters;
    expect($renderParams['filter_graph'])->not->toContain('subtitles=');
});

/*
|--------------------------------------------------------------------------
| E2E-CAP-05: Malformed Transcript Segments
|--------------------------------------------------------------------------
*/

it('fails with invalid_input when transcript segments are malformed', function () {
    $asset = $this->createTestAssetForE2E();
    $sceneAnalysis = createSceneAnalysisForE2E($asset);
    $clipAnalysis = createClipAnalysisForE2E($asset);

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

    // Malformed segment: missing start_ms
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

    $recommendation = createRecommendationForE2E($asset, $clipAnalysis, $transcript);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);

    $thrown = null;
    try {
        $job->handle();
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getMessage())->toContain('invalid_input');

    // Assert no completed DerivedAsset created
    $renders = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('render_status', DerivedAsset::RENDER_STATUS_COMPLETED)
        ->get();
    expect($renders->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| E2E-CAP-06: Idempotency with Captions
|--------------------------------------------------------------------------
*/

it('reuses existing render with same transcript (idempotent)', function () {
    $asset = $this->createTestAssetForE2E();
    $sceneAnalysis = createSceneAnalysisForE2E($asset);
    $clipAnalysis = createClipAnalysisForE2E($asset);
    $transcript = createTranscriptForE2E($asset);
    $recommendation = createRecommendationForE2E($asset, $clipAnalysis, $transcript);

    // First dispatch
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $result1 = $job1->handle();

    expect($result1->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);

    $captionFilesAfterFirst = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    $captionKey1 = $captionFilesAfterFirst[0] ?? null;
    expect($captionKey1)->not->toBeNull();

    // Second dispatch - same parameters
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $result2 = $job2->handle();

    // Assert same DerivedAsset returned
    expect($result1->id)->toBe($result2->id);

    // Assert no new SRT file created
    $captionFilesAfterSecond = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    expect(count($captionFilesAfterSecond))->toBe(count($captionFilesAfterFirst));

    // Worker not invoked again (only one render_parameters entry in history)
    // Note: This would need logging or inspection of the single DerivedAsset
    expect($result1->render_parameters)->toBe($result2->render_parameters);
});

/*
|--------------------------------------------------------------------------
| E2E-CAP-07: Transcript Change Triggers New Render
|--------------------------------------------------------------------------
*/

it('creates new render when transcript segments change', function () {
    $asset = $this->createTestAssetForE2E();
    $sceneAnalysis = createSceneAnalysisForE2E($asset);
    $clipAnalysis = createClipAnalysisForE2E($asset);
    $transcript = createTranscriptForE2E($asset);
    $recommendation = createRecommendationForE2E($asset, $clipAnalysis, $transcript);

    // First render
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $result1 = $job1->handle();

    expect($result1->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    $captionFilesAfterFirst = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    $captionKey1 = $captionFilesAfterFirst[0] ?? null;

    // Modify transcript segments (simulate user edit)
    $transcript->update([
        'segments' => [
            ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First caption segment MODIFIED'],
            ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second caption segment MODIFIED'],
            ['start_ms' => 20000, 'end_ms' => 30000, 'text' => 'Third caption segment MODIFIED'],
        ],
        'full_text' => 'First caption segment MODIFIED Second caption segment MODIFIED Third caption segment MODIFIED',
    ]);

    // Second dispatch after transcript change
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $result2 = $job2->handle();

    // Assert new DerivedAsset created
    expect($result1->id)->not->toBe($result2->id);
    expect($result2->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);

    // Assert two completed DerivedAsset rows exist
    $renders = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->where('render_status', DerivedAsset::RENDER_STATUS_COMPLETED)
        ->get();
    expect($renders->count())->toBe(2);

    // Assert new SRT file created (different UUID in key)
    $captionFilesAfterSecond = Storage::disk('media')->allFiles("projects/{$asset->project_id}/captions/*");
    expect(count($captionFilesAfterSecond))->toBe(2);
    expect($captionFilesAfterSecond)->not->toContain($captionKey1);
});