<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Services\ProcessMediaAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Issue60DbGuard;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Issue #60 atomic first-create / real termination TEST-ONLY proof on the
 * current implementation. Real PostgreSQL on the authorized disposable
 * target with committed fixtures and no outer transaction. An independent
 * owner PHP process is SIGKILLed mid-claim while its transaction is open;
 * server-side session disappearance and durable state are observed from a
 * separate connection with bounded deadlines.
 */

function atomicCreateGuard(): void
{
    $expectedDb = Issue60DbGuard::expectedDatabase();
    expect(extension_loaded('pdo_pgsql'))->toBeTrue('pdo_pgsql must be loaded');
    expect(config('database.default'))->toBe('pgsql');
    expect(config('database.connections.pgsql.driver'))->toBe('pgsql');
    expect(config('database.connections.pgsql.database'))->toBe($expectedDb);
    expect(DB::select('SELECT 1 AS one')[0]->one)->toBe(1);
    expect(DB::select('SELECT current_database() AS db')[0]->db)->toBe($expectedDb);
    expect(DB::connection()->getDatabaseName())->toBe($expectedDb);
    expect(DB::transactionLevel())->toBe(0);
}

function atomicCreateReadyAsset(string $key): MediaAsset
{
    $probe = ['duration_ms' => 30000, 'video_codec' => 'h264', 'audio_codec' => null];

    $asset = MediaAsset::factory()->create();
    $asset->markQueued($key);
    $asset->markProcessing();
    $asset->markProbed($probe, 30000);

    $scene = MediaSceneAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaSceneAnalysis::STATUS_PENDING]);
    $scene->markDetecting();
    $scene->markCompleted('deterministic', '0.0.0', ['threshold' => 27], [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]], 30000);

    return $asset;
}

function atomicCreateCleanup(?MediaAsset $asset): void
{
    try {
        if ($asset !== null) {
            $project = $asset->project;
            $user = $project?->user;
            MediaClipAnalysis::where('media_asset_id', $asset->id)->delete();
            MediaSceneAnalysis::where('media_asset_id', $asset->id)->delete();
            MediaAsset::where('id', $asset->id)->delete();
            $project?->delete();
            $user?->delete();
        }
    } catch (\Throwable) {
    }
}

function atomicCreateWaitFile(string $path, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (! file_exists($path)) {
        if (microtime(true) > $deadline) {
            return false;
        }
        usleep(20000);
    }

    return true;
}

function atomicCreateSessionGone(int $pid, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $count = (int) (DB::select('SELECT count(*) AS c FROM pg_stat_activity WHERE pid = ?', [$pid])[0]->c ?? 1);
        if ($count === 0) {
            return true;
        }
        usleep(200000);
    }

    return false;
}

function atomicCreateGoldenAction(): ProcessMediaAction
{
    return new class extends ProcessMediaAction
    {
        public function analyzeClips(MediaProcessingContract $contract): array
        {
            return [
                'status' => 'success',
                'analysis' => [
                    'algorithm' => 'scene_timing_baseline',
                    'algorithm_version' => '1.0.0',
                    'parameters' => [
                        'configuration' => [
                            'min_duration_ms' => 5000,
                            'target_duration_ms' => 30000,
                            'max_duration_ms' => 60000,
                            'max_candidates' => 20,
                            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                        ],
                        'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                        'transcript_used' => false,
                        'candidate_policy' => 'whole_scene_non_overlapping',
                        'timing_policy' => 'original_media_ms',
                        'transcript_policy' => 'optional_strict_unshifted',
                        'boundary_policy' => 'strict_interior_speech_cut',
                        'score_scale' => 1000000,
                        'rounding' => 'half_up',
                        'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
                    ],
                    'candidates' => [
                        [
                            'index' => 0,
                            'start_ms' => 0,
                            'end_ms' => 30000,
                            'rank' => 1,
                            'score' => 1,
                            'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                            'source_scene_indexes' => [0],
                        ],
                    ],
                ],
            ];
        }
    };
}

beforeEach(function () {
    atomicCreateGuard();
    Artisan::call('migrate', ['--force' => true]);
});

it('SIGKILL mid-claim on a first-create leaves absence when creation is atomic', function () {
    $asset = atomicCreateReadyAsset('660e8400-e29b-41d4-a716-446655440090');

    $runDir = sys_get_temp_dir().'/issue60-atomic-'.uniqid('', true);
    mkdir($runDir, 0777, true);
    $owner = new Process([PHP_BINARY, base_path('tests/Support/Issue60MatrixChild.php'), (string) $asset->id, '660e8400-e29b-41d4-a716-446655440090', 'owner', $runDir, 'hold']);
    $owner->setTimeout(90.0);

    try {
        $owner->start();

        expect(atomicCreateWaitFile($runDir.'/owner_ready', 25.0))->toBeTrue('owner must reach barrier');
        expect(json_decode((string) file_get_contents($runDir.'/owner_preread.json'), true)['status'])->toBeNull('owner must observe absence before insert');
        file_put_contents($runDir.'/release', 'go');
        expect(atomicCreateWaitFile($runDir.'/owner_entered', 25.0))->toBeTrue('owner must hold the claim transaction open');

        $ownerPid = (int) (json_decode((string) file_get_contents($runDir.'/owner_preread.json'), true)['pg_pid'] ?? 0);
        expect($ownerPid)->toBeGreaterThan(0);
        $parentPid = (int) (DB::select('SELECT pg_backend_pid() AS pid')[0]->pid ?? 0);
        expect($parentPid)->toBeGreaterThan(0);
        expect($ownerPid)->not->toBe($parentPid, 'owner must run on an independent backend');

        $owner->stop(0, SIGKILL);
        expect($owner->isRunning())->toBeFalse('SIGKILL must terminate the owner process');
        expect(atomicCreateSessionGone($ownerPid, 15.0))->toBeTrue('server must drop the terminated backend and roll back');

        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->where('status', MediaClipAnalysis::STATUS_ANALYZING)->exists())->toBeFalse('no committed analyzing may survive termination');
        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->exists())->toBeFalse('aborted first-create must roll back to absence');

        app()->instance(ProcessMediaAction::class, atomicCreateGoldenAction());
        (new ProcessMediaAsset($asset, '660e8400-e29b-41d4-a716-446655440090'))->handle();

        $retried = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($retried->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        expect($retried->candidates)->not->toBeEmpty();
    } finally {
        try {
            if ($owner->isRunning()) {
                $owner->stop(5.0);
            }
        } catch (\Throwable) {
        }
        foreach (glob($runDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($runDir);
        atomicCreateCleanup($asset);
    }
});

it('SIGKILL mid-claim preserves a preseeded pending row exactly', function () {
    $asset = atomicCreateReadyAsset('660e8400-e29b-41d4-a716-446655440091');
    MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
    $before = MediaClipAnalysis::where('media_asset_id', $asset->id)->first()->getRawOriginal();

    $runDir = sys_get_temp_dir().'/issue60-atomic-'.uniqid('', true);
    mkdir($runDir, 0777, true);
    $owner = new Process([PHP_BINARY, base_path('tests/Support/Issue60MatrixChild.php'), (string) $asset->id, '660e8400-e29b-41d4-a716-446655440091', 'owner', $runDir, 'hold']);
    $owner->setTimeout(90.0);

    try {
        $owner->start();

        expect(atomicCreateWaitFile($runDir.'/owner_ready', 25.0))->toBeTrue('owner must reach barrier');
        file_put_contents($runDir.'/release', 'go');
        expect(atomicCreateWaitFile($runDir.'/owner_entered', 25.0))->toBeTrue('owner must hold the claim transaction open');

        $ownerPid = (int) (json_decode((string) file_get_contents($runDir.'/owner_preread.json'), true)['pg_pid'] ?? 0);
        expect($ownerPid)->toBeGreaterThan(0);
        $parentPid = (int) (DB::select('SELECT pg_backend_pid() AS pid')[0]->pid ?? 0);
        expect($parentPid)->toBeGreaterThan(0);
        expect($ownerPid)->not->toBe($parentPid, 'owner must run on an independent backend');
        $owner->stop(0, SIGKILL);
        expect($owner->isRunning())->toBeFalse('SIGKILL must terminate the owner process');
        expect(atomicCreateSessionGone($ownerPid, 15.0))->toBeTrue('server must drop the terminated backend and roll back');

        $after = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($after)->not->toBeNull();
        expect($after->getRawOriginal())->toBe($before);

        $retry = new class extends ProcessMediaAction
        {
            public function analyzeClips(MediaProcessingContract $contract): array
            {
                return [
                    'status' => 'success',
                    'analysis' => [
                        'algorithm' => 'scene_timing_baseline',
                        'algorithm_version' => '1.0.0',
                        'parameters' => [
                            'configuration' => [
                                'min_duration_ms' => 5000,
                                'target_duration_ms' => 30000,
                                'max_duration_ms' => 60000,
                                'max_candidates' => 20,
                                'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                            ],
                            'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                            'transcript_used' => false,
                            'candidate_policy' => 'whole_scene_non_overlapping',
                            'timing_policy' => 'original_media_ms',
                            'transcript_policy' => 'optional_strict_unshifted',
                            'boundary_policy' => 'strict_interior_speech_cut',
                            'score_scale' => 1000000,
                            'rounding' => 'half_up',
                            'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
                        ],
                        'candidates' => [
                            [
                                'index' => 0,
                                'start_ms' => 0,
                                'end_ms' => 30000,
                                'rank' => 1,
                                'score' => 1,
                                'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                                'source_scene_indexes' => [0],
                            ],
                        ],
                    ],
                ];
            }
        };
        app()->instance(ProcessMediaAction::class, $retry);
        (new ProcessMediaAsset($asset, '660e8400-e29b-41d4-a716-446655440091'))->handle();

        $retried = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($retried->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        expect($retried->candidates)->not->toBeEmpty();
    } finally {
        try {
            if ($owner->isRunning()) {
                $owner->stop(5.0);
            }
        } catch (\Throwable) {
        }
        foreach (glob($runDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($runDir);
        atomicCreateCleanup($asset);
    }
});
