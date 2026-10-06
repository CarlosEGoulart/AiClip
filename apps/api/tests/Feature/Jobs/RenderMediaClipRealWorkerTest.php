<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RenderMediaClip;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ClipRankingProfile;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixtures for RenderMediaClip real worker tests
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    // Register a test disk that points to the app root so the worker (running from app root)
    // can access files via relative paths
    Storage::extend('test-worker', function () {
        return new Filesystem(new LocalFilesystemAdapter(base_path()));
    });
});

function createProbedAssetForRealWorker(array $overrides = []): MediaAsset
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
        // Use test-worker disk (points to app root) so worker can read file directly via ffmpeg
        'storage_disk' => 'test-worker',
        'storage_key' => 'tests/real-worker/source.mp4',
    ], $overrides));

    // Update storage_key to use actual asset ID for uniqueness
    $asset->update([
        'storage_key' => "tests/real-worker/{$asset->id}/source.mp4",
    ]);

    // Generate a valid test video using FFmpeg that matches the expected probe data
    // Cache the generated video to avoid regenerating for each test
    $appRoot = getcwd(); // This is apps/api when running php artisan test
    $cachePath = $appRoot . '/tests/real-worker/cached_source.mp4';
    $destPath = $appRoot . '/' . $asset->storage_key;
    $dir = dirname($destPath);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Generate cached video if it doesn't exist
    if (! file_exists($cachePath) || filesize($cachePath) === 0) {
        // Generate 30-second test video: 1920x1080, 30fps, h264, aac audio
        // Use ultrafast preset for speed
        $ffmpegCmd = "ffmpeg -y -f lavfi -i testsrc=duration=30:size=1920x1080:rate=30 -f lavfi -i sine=frequency=1000:duration=30 -c:v libx264 -preset ultrafast -c:a aac -pix_fmt yuv420p -t 30 {$cachePath} 2>&1";
        $result = shell_exec($ffmpegCmd);
        if ($result === null || ! file_exists($cachePath) || filesize($cachePath) === 0) {
            throw new \RuntimeException("Failed to generate cached test video: {$result}");
        }
    }

    // Copy cached video to test-specific location
    if (! copy($cachePath, $destPath)) {
        throw new \RuntimeException("Failed to copy cached video to {$destPath}");
    }

    return $asset->fresh();
}

function createCompletedSceneAnalysisForRealWorker(MediaAsset $asset): MediaSceneAnalysis
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

function createCompletedClipAnalysisForRealWorker(MediaAsset $asset): MediaClipAnalysis
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

function createCompletedTranscriptForRealWorker(MediaAsset $asset, MediaSceneAnalysis $sceneAnalysis): MediaTranscript
{
    $derivedAsset = DerivedAsset::create([
        'media_asset_id' => $asset->id,
        'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        // Use local disk for derived assets too
        'storage_disk' => 'local',
        'storage_key' => "tests/real-worker/{$asset->id}/derivatives/audio/test.wav",
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

function createCompletedRecommendationForRealWorker(MediaAsset $asset, MediaClipAnalysis $clipAnalysis, MediaTranscript $transcript): MediaClipRecommendation
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
| Real worker tests use the actual ProcessMediaAction which calls python -m aiclip_worker.cli render-clip
|--------------------------------------------------------------------------
*/

// The tests use the real ProcessMediaAction (default) which invokes the Python worker CLI
// No custom action needed - the worker is called via: python -m aiclip_worker.cli render-clip

/*
|--------------------------------------------------------------------------
| M6.2 Real Worker Integration Tests (TC-RMR-CAP-01 through TC-RMR-CAP-06)
|--------------------------------------------------------------------------
*/

// Disable global ProcessMediaAction mock for these tests
beforeEach(function () {
    // Clear the global mock from TestCase::setUp()
    app()->forgetInstance(ProcessMediaAction::class);
    // Bind the real ProcessMediaAction
    app()->bind(ProcessMediaAction::class, ProcessMediaAction::class);
});

/*
| TC-RMR-CAP-01: Full job with real worker, FFmpeg, transcript
*/
it('full job with real worker, FFmpeg, transcript produces DerivedAsset with captions', function () {
    // Check if FFmpeg is available
    $ffmpegCheck = shell_exec('which ffmpeg');
    if (! $ffmpegCheck) {
        $this->markTestSkipped('FFmpeg not available');
    }

    // Font is installed via fonts-dejavu-core in CI workflow
    // No need to check for it here

    $asset = createProbedAssetForRealWorker();
    $sceneAnalysis = createCompletedSceneAnalysisForRealWorker($asset);
    $clipAnalysis = createCompletedClipAnalysisForRealWorker($asset);
    $transcript = createCompletedTranscriptForRealWorker($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRealWorker($asset, $clipAnalysis, $transcript);

    // Use default ProcessMediaAction which calls python -m aiclip_worker.cli render-clip
    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);

    $result = $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
    expect($render->storage_disk)->toBe('media');
    expect($render->storage_key)->not->toBeNull();
    expect($render->mime_type)->toBe('video/mp4');
    expect($render->size_bytes)->toBeGreaterThan(0);
    expect($render->duration_ms)->toBeGreaterThan(0);
    expect($render->width)->toBe(1080);
    expect($render->height)->toBe(1920);
    expect($render->codec)->toBe('libx264');

    // Verify captions were rendered (filter_graph contains drawtext)
    expect($render->render_parameters)->toHaveKey('filter_graph');
    expect($render->render_parameters['filter_graph'])->toContain('drawtext');

    // Verify caption configuration in parameters
    expect($render->render_parameters['configuration'])->toHaveKey('captions');
});

/*
| TC-RMR-CAP-02: Output file exists at expected key
*/
it('output file exists at expected storage key', function () {
    $ffmpegCheck = shell_exec('which ffmpeg');
    if (! $ffmpegCheck) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $asset = createProbedAssetForRealWorker();
    $sceneAnalysis = createCompletedSceneAnalysisForRealWorker($asset);
    $clipAnalysis = createCompletedClipAnalysisForRealWorker($asset);
    $transcript = createCompletedTranscriptForRealWorker($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRealWorker($asset, $clipAnalysis, $transcript);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);

    $result = $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    // Check file exists in storage
    $disk = Storage::disk($render->storage_disk);
    expect($disk->exists($render->storage_key))->toBeTrue();
});

/*
| TC-RMR-CAP-03: DerivedAsset render_parameters includes caption config and filter_graph
*/
it('DerivedAsset render_parameters includes caption config and filter_graph', function () {
    $ffmpegCheck = shell_exec('which ffmpeg');
    if (! $ffmpegCheck) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $asset = createProbedAssetForRealWorker();
    $sceneAnalysis = createCompletedSceneAnalysisForRealWorker($asset);
    $clipAnalysis = createCompletedClipAnalysisForRealWorker($asset);
    $transcript = createCompletedTranscriptForRealWorker($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRealWorker($asset, $clipAnalysis, $transcript);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);

    $result = $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    $params = $render->render_parameters;

    // Check required parameters keys
    expect($params)->toHaveKeys([
        'configuration',
        'source_media',
        'ffmpeg_version',
        'filter_graph',
        'limits',
        'request_sha256',
    ]);

    // Check configuration includes captions
    expect($params['configuration'])->toHaveKey('captions');
    expect($params['configuration']['captions']['enabled'])->toBeTrue();
    expect($params['configuration']['captions']['font_size'])->toBe(72);

    // Check filter_graph contains drawtext
    expect($params['filter_graph'])->toContain('drawtext');

    // Check ffmpeg_version is present
    expect($params['ffmpeg_version'])->not->toBeEmpty();

    // Check limits
    expect($params['limits'])->toBe([
        'max_recommendations' => 1000,
        'max_input_bytes' => 8388608,
        'max_duration_ms' => 2147483647,
    ]);
});

/*
| TC-RMR-CAP-04: DerivedAsset output metadata matches probe
*/
it('DerivedAsset output metadata matches probe within tolerance', function () {
    $ffmpegCheck = shell_exec('which ffmpeg');
    if (! $ffmpegCheck) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $asset = createProbedAssetForRealWorker();
    $sceneAnalysis = createCompletedSceneAnalysisForRealWorker($asset);
    $clipAnalysis = createCompletedClipAnalysisForRealWorker($asset);
    $transcript = createCompletedTranscriptForRealWorker($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRealWorker($asset, $clipAnalysis, $transcript);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);

    $result = $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    // Probe the output file to verify metadata
    $disk = Storage::disk($render->storage_disk);
    $tempPath = sys_get_temp_dir().'/'.basename($render->storage_key);
    $disk->download($render->storage_key, $tempPath);

    $probeOutput = shell_exec("ffprobe -v quiet -print_format json -show_streams -show_format {$tempPath}");
    @unlink($tempPath);

    $probeData = json_decode($probeOutput, true);

    $videoStream = null;
    foreach ($probeData['streams'] as $stream) {
        if ($stream['codec_type'] === 'video') {
            $videoStream = $stream;
            break;
        }
    }

    expect($videoStream)->not->toBeNull();

    // Verify width/height match configuration
    expect($videoStream['width'])->toBe(1080);
    expect($videoStream['height'])->toBe(1920);

    // Verify codec is h264 (libx264 output)
    expect($videoStream['codec_name'])->toBe('h264');

    // Verify duration matches expected (within 50ms)
    $expectedDuration = 10000; // candidate 0: 0-10000ms
    $actualDuration = intval(floatval($videoStream['duration'] ?? $probeData['format']['duration'] ?? 0) * 1000);
    expect(abs($actualDuration - $expectedDuration))->toBeLessThanOrEqual(50);

    // Verify output metadata in DerivedAsset matches probe
    expect($render->width)->toBe(1080);
    expect($render->height)->toBe(1920);
    expect($render->codec)->toBe('libx264');
    expect(abs($render->duration_ms - $expectedDuration))->toBeLessThanOrEqual(50);
});

/*
| TC-RMR-CAP-05: Different candidate_index produces distinct DerivedAsset row
*/
it('different candidate_index produces distinct DerivedAsset row', function () {
    $ffmpegCheck = shell_exec('which ffmpeg');
    if (! $ffmpegCheck) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $asset = createProbedAssetForRealWorker();
    $sceneAnalysis = createCompletedSceneAnalysisForRealWorker($asset);
    $clipAnalysis = createCompletedClipAnalysisForRealWorker($asset);
    $transcript = createCompletedTranscriptForRealWorker($asset, $sceneAnalysis);
    $recommendation = createCompletedRecommendationForRealWorker($asset, $clipAnalysis, $transcript);

    // Render candidate 0
    $job1 = new RenderMediaClip($asset->id, $recommendation->id, 0);
    $job1->handle();

    // Render candidate 1
    $job2 = new RenderMediaClip($asset->id, $recommendation->id, 1);
    $job2->handle();

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
    expect($render0->candidate_index)->toBe(0);
    expect($render1->candidate_index)->toBe(1);
    expect($render0->storage_key)->not->toBe($render1->storage_key);
});

/*
| TC-RMR-CAP-06: Without transcript: M6.1 regression still works
*/
it('without transcript: M6.1 regression still works (no captions)', function () {
    $ffmpegCheck = shell_exec('which ffmpeg');
    if (! $ffmpegCheck) {
        $this->markTestSkipped('FFmpeg not available');
    }

    $asset = createProbedAssetForRealWorker();
    $sceneAnalysis = createCompletedSceneAnalysisForRealWorker($asset);
    $clipAnalysis = createCompletedClipAnalysisForRealWorker($asset);

    // No transcript - create recommendation with transcript_state = absent
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
                'semantic_score' => 0.95,
                'semantic_rank' => 1,
                'reason' => null,
            ],
        ],
        'input_snapshot' => [
            'm4_analysis_id' => $clipAnalysis->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => $clipAnalysis->candidates,
            'duration_ms' => 30000,
            'transcript_state' => 'absent',
            'projection_version' => '1.0.0',
            'text_hashes' => [],
            'request_sha256' => hash('sha256', 'test'),
        ],
        'execution_parameters' => [
            'timeout_seconds' => 60,
            'lock_wait_seconds' => 65,
        ],
    ]);

    $job = new RenderMediaClip($asset->id, $recommendation->id, 0);

    $result = $job->handle();

    $render = DerivedAsset::where('media_asset_id', $asset->id)
        ->where('type', DerivedAsset::TYPE_RENDERED_CLIP)
        ->where('candidate_index', 0)
        ->first();

    expect($render)->not->toBeNull();
    expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);

    // Verify no captions in filter_graph (M6.1 behavior)
    expect($render->render_parameters['filter_graph'])->not->toContain('drawtext');

    // Verify configuration doesn't have captions key or it's empty
    $config = $render->render_parameters['configuration'];
    expect($config)->not->toHaveKey('captions'); // or empty if present
});