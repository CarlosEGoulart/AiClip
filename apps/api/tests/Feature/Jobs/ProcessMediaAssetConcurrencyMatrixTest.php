<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Services\ClipAnalysisValidator;
use App\Services\ProcessMediaAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Issue #60 L4 matrix, TEST-ONLY pass on the current implementation.
 *
 * Real PostgreSQL on the authorized disposable target with committed
 * fixtures; no outer RefreshDatabase/DatabaseTransactions. Concurrency
 * scenarios use independent PHP processes with deterministic filesystem
 * barriers. No production code is changed by this file.
 */

function issue60Guard(): int
{
    expect(extension_loaded('pdo_pgsql'))->toBeTrue('pdo_pgsql must be loaded');
    expect(config('database.default'))->toBe('pgsql');
    expect(config('database.connections.pgsql.driver'))->toBe('pgsql');
    expect(config('database.connections.pgsql.database'))->toBe('aiclip_test_issue60');
    expect(DB::select('SELECT 1 AS one')[0]->one)->toBe(1);
    expect(DB::select('SELECT current_database() AS db')[0]->db)->toBe('aiclip_test_issue60');
    expect(DB::connection()->getDatabaseName())->toBe('aiclip_test_issue60');
    expect(DB::transactionLevel())->toBe(0);

    $pid = (int) (DB::select('SELECT pg_backend_pid() AS pid')[0]->pid ?? 0);
    expect($pid)->toBeGreaterThan(0);

    return $pid;
}

function issue60ReadyAsset(string $key): array
{
    $probe = ['duration_ms' => 30000, 'video_codec' => 'h264', 'audio_codec' => null];
    $scenes = [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]];

    $asset = MediaAsset::factory()->create();
    $asset->markQueued($key);
    $asset->markProcessing();
    $asset->markProbed($probe, 30000);

    $scene = MediaSceneAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaSceneAnalysis::STATUS_PENDING]);
    $scene->markDetecting();
    $scene->markCompleted('deterministic', '0.0.0', ['threshold' => 27], $scenes, 30000);

    return [$asset, $key, $scenes];
}

function issue60Golden(): array
{
    $configuration = [
        'min_duration_ms' => 5000, 'target_duration_ms' => 30000,
        'max_duration_ms' => 60000, 'max_candidates' => 20,
        'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
    ];

    return [
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline', 'algorithm_version' => '1.0.0',
            'parameters' => [
                'configuration' => $configuration,
                'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                'transcript_used' => false,
                'candidate_policy' => 'whole_scene_non_overlapping',
                'timing_policy' => 'original_media_ms',
                'transcript_policy' => 'optional_strict_unshifted',
                'boundary_policy' => 'strict_interior_speech_cut',
                'score_scale' => 1000000, 'rounding' => 'half_up',
                'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
            ],
            'candidates' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 30000, 'rank' => 1, 'score' => 1,
                    'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                    'source_scene_indexes' => [0]],
            ],
        ],
    ];
}

function issue60ImmediateAction(array $response): ProcessMediaAction
{
    return new class($response) extends ProcessMediaAction
    {
        public function __construct(private array $response) {}

        public function analyzeClips(MediaProcessingContract $contract): array
        {
            return $this->response;
        }

        public function probe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_probe_call');
        }

        public function detectScenes(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_scene_call');
        }

        public function extractAudio(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_extract_call');
        }

        public function transcribe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_transcribe_call');
        }
    };
}

function issue60Cleanup(?MediaAsset $asset): void
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

function issue60Spawn(string $assetId, string $key, string $role, string $runDir, string $mode, string $param = ''): Process
{
    $process = new Process([PHP_BINARY, base_path('tests/Support/Issue60MatrixChild.php'), $assetId, $key, $role, $runDir, $mode, $param]);
    $process->setTimeout(90.0);

    return $process;
}

function issue60WaitFile(string $path, float $seconds): bool
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

beforeEach(function () {
    issue60Guard();
    Artisan::call('migrate', ['--force' => true]);
});

it('C2 concurrent failed retries serialize with one owner and contender reuse', function () {
    [$asset, $key] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440062');

    $row = MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
    $row->markAnalyzing();
    $row->markFailed('stale_error');
    $rowId = $row->id;

    $runDir = sys_get_temp_dir().'/issue60-c2-'.uniqid('', true);
    mkdir($runDir, 0777, true);
    $owner = issue60Spawn((string) $asset->id, $key, 'owner', $runDir, 'hold');
    $contender = issue60Spawn((string) $asset->id, $key, 'contender', $runDir, 'contend');

    try {
        $owner->start();
        $contender->start();

        expect(issue60WaitFile($runDir.'/owner_ready', 25.0))->toBeTrue('owner must reach barrier');
        expect(issue60WaitFile($runDir.'/contender_ready', 25.0))->toBeTrue('contender must reach barrier');
        expect(json_decode((string) file_get_contents($runDir.'/owner_preread.json'), true)['status'])
            ->toBe(MediaClipAnalysis::STATUS_FAILED);
        expect(json_decode((string) file_get_contents($runDir.'/contender_preread.json'), true)['status'])
            ->toBe(MediaClipAnalysis::STATUS_FAILED);

        file_put_contents($runDir.'/release', 'go');
        issue60WaitFile($runDir.'/owner_entered', 20.0);
        issue60WaitFile($runDir.'/contender_in_handle', 20.0);
        file_put_contents($runDir.'/release_worker', 'go');

        $owner->wait();
        $contender->wait();

        $ownerResult = json_decode((string) file_get_contents($runDir.'/owner_result.json'), true);
        $contenderResult = json_decode((string) file_get_contents($runDir.'/contender_result.json'), true);
        expect($ownerResult['ok'])->toBeTrue('owner retry must succeed');
        expect($contenderResult['ok'])->toBeTrue('contender retry must reuse instead of failing');

        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->count())->toBe(1);
        $clip = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($clip->id)->toBe($rowId, 'same row must be claimed serially');
        expect($clip->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        expect($clip->error)->toBeNull('stale error must be cleared by the owner');

        $calls = array_values(array_filter(explode(PHP_EOL, (string) @file_get_contents($runDir.'/worker_calls.log'))));
        expect(count($calls))->toBe(1, 'exactly one total worker invocation is required');
    } finally {
        try {
            if ($owner->isRunning()) {
                $owner->stop(5.0);
            }
        } catch (\Throwable) {
        }
        try {
            if ($contender->isRunning()) {
                $contender->stop(5.0);
            }
        } catch (\Throwable) {
        }
        foreach (glob($runDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($runDir);
        issue60Cleanup($asset);
    }
});

it('C3 contender observes a bounded lock wait while the owner holds the row', function () {
    [$asset, $key] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440063');

    $runDir = sys_get_temp_dir().'/issue60-c3-'.uniqid('', true);
    mkdir($runDir, 0777, true);
    $owner = issue60Spawn((string) $asset->id, $key, 'owner', $runDir, 'hold');
    $contender = issue60Spawn((string) $asset->id, $key, 'contender', $runDir, 'contend', 'timeout=1');

    try {
        // Deterministic owner-first: owner holds the row lock before the
        // contender (timeout=1, derived bound 6s) enters the claim.
        $owner->start();
        expect(issue60WaitFile($runDir.'/owner_ready', 25.0))->toBeTrue('owner must reach barrier');
        file_put_contents($runDir.'/release_owner', 'go');
        expect(issue60WaitFile($runDir.'/owner_entered', 25.0))->toBeTrue('owner must hold the row lock');

        $contender->start();
        expect(issue60WaitFile($runDir.'/contender_ready', 25.0))->toBeTrue('contender must reach barrier');
        $startedAt = microtime(true);
        file_put_contents($runDir.'/release_contender', 'go');

        // The spec bound is 6s with 5s scheduling tolerance: a bounded
        // contender must resolve within 11s while the owner still holds.
        $resolved = issue60WaitFile($runDir.'/contender_result.json', 16.0);
        $elapsed = microtime(true) - $startedAt;
        file_put_contents($runDir.'/release_worker', 'go');

        $owner->wait();
        $contender->wait();

        expect($resolved)->toBeTrue('contender must resolve while the owner still holds the lock');
        expect($elapsed)->toBeLessThanOrEqual(11.0, 'contender wait must be bounded by timeout+5 (6s) plus tolerance');

        $contenderResult = json_decode((string) file_get_contents($runDir.'/contender_result.json'), true);
        $calls = array_values(array_filter(explode(PHP_EOL, (string) @file_get_contents($runDir.'/worker_calls.log'))));
        $contenderCalls = array_values(array_filter($calls, fn ($line) => str_starts_with($line, 'contender:')));
        expect($contenderCalls)->toBeEmpty('contender must make no worker call');
        expect($contenderResult['ok'])->toBeTrue();

        $clip = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($clip->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
    } finally {
        try {
            if ($owner->isRunning()) {
                $owner->stop(5.0);
            }
        } catch (\Throwable) {
        }
        try {
            if ($contender->isRunning()) {
                $contender->stop(5.0);
            }
        } catch (\Throwable) {
        }
        foreach (glob($runDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($runDir);
        issue60Cleanup($asset);
    }
});

it('C4 unexpected abort leaves no committed analyzing or partial snapshot', function () {
    $crashing = new class extends ProcessMediaAction
    {
        public function analyzeClips(MediaProcessingContract $contract): array
        {
            throw new \RuntimeException('synthetic_crash_after_transition');
        }

        public function probe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_probe_call');
        }

        public function detectScenes(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_scene_call');
        }

        public function extractAudio(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_extract_call');
        }

        public function transcribe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_transcribe_call');
        }
    };
    app()->instance(ProcessMediaAction::class, $crashing);

    [$assetA, $keyA] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440064');
    [$assetB, $keyB] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440065');
    [$assetC, $keyC] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440066');

    try {
        $pending = MediaClipAnalysis::create(['media_asset_id' => $assetA->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $failed = MediaClipAnalysis::create(['media_asset_id' => $assetB->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $failed->markAnalyzing();
        $failed->markFailed('orig_error');

        $signals = [];
        foreach ([[$assetA, $keyA], [$assetB, $keyB], [$assetC, $keyC]] as [$asset, $key]) {
            try {
                (new ProcessMediaAsset($asset, $key))->handle();
            } catch (\Throwable $e) {
                $signals[] = $e;
            }
        }

        expect($signals)->toHaveCount(3, 'each aborted attempt must signal the caller');
        foreach ($signals as $signal) {
            expect($signal)->toBeInstanceOf(ProcessMediaException::class);
            expect($signal->getMessage())->toBe('clip_analysis_aborted');
            expect($signal->getPrevious())->toBeNull();
        }

        expect(MediaClipAnalysis::where('media_asset_id', $assetA->id)->first()->status)
            ->toBe(MediaClipAnalysis::STATUS_PENDING, 'preseeded pending must survive an unexpected abort exactly');
        expect(MediaClipAnalysis::where('media_asset_id', $assetB->id)->first()->error)
            ->toBe('orig_error', 'preseeded failed state must survive an unexpected abort exactly');
        expect(MediaClipAnalysis::where('media_asset_id', $assetC->id)->exists())
            ->toBeFalse('new-row abort must roll back to absence');
    } finally {
        issue60Cleanup($assetA);
        issue60Cleanup($assetB);
        issue60Cleanup($assetC);
    }
});

it('C5 real queue exhausts upstream_not_ready in three bounded attempts', function () {
    // Explicit test config override selects the database queue without any
    // global config edit; the worker subprocess inherits it via env.
    config(['queue.default' => 'database']);
    expect(config('queue.default'))->toBe('database');

    $probe = ['duration_ms' => 30000, 'video_codec' => 'h264', 'audio_codec' => null];
    $key = '660e8400-e29b-41d4-a716-446655440067';
    $asset = MediaAsset::factory()->create();
    $asset->markQueued($key);
    $asset->markProcessing();
    $asset->markProbed($probe, 30000);
    $pendingScene = MediaSceneAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaSceneAnalysis::STATUS_PENDING]);
    $sceneBefore = $pendingScene->getRawOriginal();

    [$doneAsset, $doneKey] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440068');
    app()->instance(ProcessMediaAction::class, issue60ImmediateAction(issue60Golden()));
    (new ProcessMediaAsset($doneAsset, $doneKey))->handle();
    $doneClipBefore = MediaClipAnalysis::where('media_asset_id', $doneAsset->id)->first()->getRawOriginal();
    $doneAssetBefore = $doneAsset->fresh()->getRawOriginal();

    $runOnce = function (): void {
        $once = new Process(
            [PHP_BINARY, 'artisan', 'queue:work', '--queue=issue60c5', '--once', '--tries=3', '--timeout=30'],
            base_path(),
            ['QUEUE_CONNECTION' => 'database']
        );
        $once->setTimeout(60.0);
        $once->run();
    };

    try {
        ProcessMediaAsset::dispatch($asset, $key)->onQueue('issue60c5');

        $delays = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $released = false;
            $availableBy = microtime(true) + 25.0;
            while (microtime(true) < $availableBy) {
                $pending = DB::table('jobs')->where('queue', 'issue60c5')->where('payload', 'like', '%'.$key.'%')->first();
                if ($pending !== null && (int) $pending->available_at <= time()) {
                    $released = true;
                    break;
                }
                usleep(200000);
            }
            expect($released)->toBeTrue('attempt '.$attempt.' must become available on the real queue');
            $runOnce();
            $job = DB::table('jobs')->where('queue', 'issue60c5')->where('payload', 'like', '%'.$key.'%')->first();
            if ($attempt < 3) {
                expect($job)->not->toBeNull('attempt '.$attempt.' must release back onto the real queue');
                expect((int) $job->attempts)->toBe($attempt);
                $delays[] = (int) $job->available_at - time();
            }
        }
        foreach ($delays as $delay) {
            expect($delay)->toBeGreaterThanOrEqual(4, 'not-ready release must be bounded at 5s per attempt');
        }
        $failed = DB::table('failed_jobs')->where('payload', 'like', '%'.$key.'%')->first();
        expect($failed)->not->toBeNull('job must exhaust to failed_jobs after three attempts');

        // Dead poll loop removed; per-attempt runs observe releases directly.

        // Exhaustion asserted below via failed_jobs.

        // Attempt spacing asserted above via per-attempt available_at delays.

        $clip = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($clip->status)->toBe(MediaClipAnalysis::STATUS_FAILED);
        expect($clip->error)->toBe('upstream_not_ready');
        expect($asset->fresh()->processing_status)->toBe(MediaAsset::PROCESSING_FAILED);

        $sorted = function (array $row): array {
            ksort($row);

            return $row;
        };
        expect(MediaSceneAnalysis::where('media_asset_id', $asset->id)->first()->status)->toBe($sceneBefore['status']);
        expect($sorted(MediaClipAnalysis::where('media_asset_id', $doneAsset->id)->first()->getRawOriginal()))->toBe($sorted($doneClipBefore));
        expect($sorted($doneAsset->fresh()->getRawOriginal()))->toBe($sorted($doneAssetBefore));
    } finally {
        try {
            // --once workers exit on their own; nothing to stop.
        } catch (\Throwable) {
        }
        try {
            DB::table('jobs')->where('payload', 'like', '%'.$key.'%')->delete();
            DB::table('failed_jobs')->where('payload', 'like', '%'.$key.'%')->delete();
        } catch (\Throwable) {
        }
        issue60Cleanup($asset);
        issue60Cleanup($doneAsset);
    }
});

it('C6 stale failure callback cannot overwrite completed snapshot or asset', function () {
    [$asset, $key] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440069');
    app()->instance(ProcessMediaAction::class, issue60ImmediateAction(issue60Golden()));

    try {
        (new ProcessMediaAsset($asset, $key))->handle();
        $clip = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($clip->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        $clipBefore = $clip->getRawOriginal();
        $assetBefore = $asset->fresh()->getRawOriginal();

        $stale = new ProcessMediaAsset($asset, $key);
        $stale->failed(new ProcessMediaException('upstream_not_ready', 1));
        $stale->failed(new \RuntimeException('stale_generic_failure'));

        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->first()->getRawOriginal())->toBe($clipBefore);
        expect($asset->fresh()->getRawOriginal())->toBe($assetBefore);
    } finally {
        issue60Cleanup($asset);
    }
});

it('C7 asset deletion cascades and stale callers cannot recreate analysis', function () {
    [$asset, $key] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440070');
    app()->instance(ProcessMediaAction::class, issue60ImmediateAction(issue60Golden()));

    try {
        $clip = MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $assetId = $asset->id;
        $asset->delete();

        expect(MediaClipAnalysis::where('media_asset_id', $assetId)->exists())
            ->toBeFalse('FK cascade must leave no orphan analysis');

        (new ProcessMediaAsset($asset, $key))->handle();
        expect(MediaClipAnalysis::where('media_asset_id', $assetId)->exists())
            ->toBeFalse('stale handle must not recreate deleted analysis');

        (new ProcessMediaAsset($asset, $key))->failed(new ProcessMediaException('upstream_not_ready', 1));
        expect(MediaClipAnalysis::where('media_asset_id', $assetId)->exists())
            ->toBeFalse('stale callback must not recreate deleted analysis');
    } finally {
        try {
            MediaClipAnalysis::where('media_asset_id', $asset->id)->delete();
            MediaSceneAnalysis::where('media_asset_id', $asset->id)->delete();
            MediaAsset::where('id', $asset->id)->delete();
            $asset->project()->delete();
        } catch (\Throwable) {
        }
    }
});

it('C8 upstream stages execute outside the clip row lock', function () {
    $levels = [];
    $order = [];

    $staged = new class($levels, $order) extends ProcessMediaAction
    {
        public function __construct(private array &$levels, private array &$order) {}

        public function probe(MediaProcessingContract $contract): array
        {
            $this->levels['probe'] = DB::transactionLevel();
            $this->order[] = 'probe';

            return ['status' => 'success', 'probe' => ['duration_ms' => 30000, 'video_codec' => 'h264', 'audio_codec' => null]];
        }

        public function detectScenes(MediaProcessingContract $contract): array
        {
            $this->levels['detect'] = DB::transactionLevel();
            $this->order[] = 'detect';

            return ['status' => 'success', 'scene_detection' => [
                'detector' => 'deterministic', 'detector_version' => '0.0.0', 'parameters' => ['threshold' => 27],
                'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]],
            ]];
        }

        public function analyzeClips(MediaProcessingContract $contract): array
        {
            $this->levels['analyze'] = DB::transactionLevel();
            $this->order[] = 'analyze';

            return issue60Golden();
        }

        public function extractAudio(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_extract_call');
        }

        public function transcribe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_transcribe_call');
        }
    };
    app()->instance(ProcessMediaAction::class, $staged);

    $key = '660e8400-e29b-41d4-a716-446655440071';
    $asset = MediaAsset::factory()->create();

    try {
        (new ProcessMediaAsset($asset, $key))->handle();

        expect($levels['probe'])->toBe(0, 'probe must run outside any clip transaction');
        expect($levels['detect'])->toBe(0, 'scene detection must run outside any clip transaction');
        expect($levels['analyze'])->toBeGreaterThanOrEqual(1, 'clip worker must run inside the exclusive claim');
        expect(array_search('detect', $order))->toBeLessThan(array_search('analyze', $order));
    } finally {
        issue60Cleanup($asset);
    }
});

it('C9 clip transaction settings do not leak to later transactions', function () {
    DB::statement("SET SESSION lock_timeout = '7s'");
    config(['media.clip_analysis_timeout_seconds' => 1]);

    $captured = [];
    $capturing = new class($captured) extends ProcessMediaAction
    {
        public function __construct(private array &$captured) {}

        public function analyzeClips(MediaProcessingContract $contract): array
        {
            $row = DB::select("SELECT current_setting('lock_timeout') AS setting");
            $this->captured[] = $row[0]->setting ?? null;

            return issue60Golden();
        }

        public function probe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_probe_call');
        }

        public function detectScenes(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_scene_call');
        }

        public function extractAudio(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_extract_call');
        }

        public function transcribe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_transcribe_call');
        }
    };
    app()->instance(ProcessMediaAction::class, $capturing);

    [$asset, $key] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440072');

    $toMs = function (?string $value): ?int {
        if ($value === null) {
            return null;
        }
        if (str_ends_with($value, 'ms')) {
            return (int) substr($value, 0, -2);
        }
        if (str_ends_with($value, 's')) {
            return (int) substr($value, 0, -1) * 1000;
        }

        return is_numeric($value) ? (int) $value : null;
    };

    try {
        (new ProcessMediaAsset($asset, $key))->handle();

        expect($toMs($captured[0] ?? null))->toBe(6000, 'claim must apply the derived timeout+5 bound transaction-locally');
        expect($toMs(DB::select("SELECT current_setting('lock_timeout') AS setting")[0]->setting ?? null))
            ->toBe(7000, 'prior session setting must be restored after commit');
    } finally {
        try {
            DB::statement("SET SESSION lock_timeout = '0'");
        } catch (\Throwable) {
        }
        issue60Cleanup($asset);
    }
});

it('E1 malformed worker output commits sanitized failure and allows serial retry', function () {
    [$asset, $key] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440073');

    $e1 = new class extends ProcessMediaAction
    {
        public bool $malformed = true;

        public function probe(MediaProcessingContract $contract): array
        {
            return ['status' => 'success', 'probe' => ['duration_ms' => 30000, 'video_codec' => 'h264', 'audio_codec' => null]];
        }

        public function detectScenes(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_scene_call');
        }

        public function extractAudio(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_extract_call');
        }

        public function transcribe(MediaProcessingContract $contract): array
        {
            throw new ProcessMediaException('unexpected_transcribe_call');
        }

        protected function createProcess(array $command): Process
        {
            $malformed = $this->malformed;

            return new class($malformed) extends Process
            {
                public function __construct(private bool $malformed)
                {
                    parent::__construct(['recording-worker']);
                }

                public function run(?callable $callback = null, array $env = []): int
                {
                    return 0;
                }

                public function isSuccessful(): bool
                {
                    return true;
                }

                public function getOutput(): string
                {
                    if ($this->malformed) {
                        return '{"status":"success","analysis":null}';
                    }

                    return json_encode(issue60Golden(), JSON_THROW_ON_ERROR);
                }

                public function getExitCode(): ?int
                {
                    return 0;
                }
            };
        }
    };
    app()->instance(ProcessMediaAction::class, $e1);

    try {
        (new ProcessMediaAsset($asset, $key))->handle();

        $clip = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($clip->status)->toBe(MediaClipAnalysis::STATUS_FAILED);
        expect($clip->error)->toBe('analysis_failed');
        expect($clip->candidates ?? [])->toBeEmpty();
        expect($clip->input_snapshot)->toBeNull();
        $sceneStatus = MediaSceneAnalysis::where('media_asset_id', $asset->id)->first()->status;
        expect($sceneStatus)->toBe(MediaSceneAnalysis::STATUS_COMPLETED);

        app()->instance(ProcessMediaAction::class, $e1);
        $e1->malformed = false;
        (new ProcessMediaAsset($asset, $key))->handle();

        $retry = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($retry->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        expect($retry->candidates)->not->toBeEmpty();
    } finally {
        issue60Cleanup($asset);
    }
});

it('E2 real worker process timeout terminates the child and fails sanitarily', function () {
    config(['media.clip_analysis_timeout_seconds' => 1]);
    [$asset, $key] = issue60ReadyAsset('660e8400-e29b-41d4-a716-446655440074');

    $spawned = [];
    $sleeping = new class($spawned) extends ProcessMediaAction
    {
        public function __construct(private array &$spawned) {}

        protected function createProcess(array $command): Process
        {
            $process = new Process(['sleep', '10']);
            $this->spawned[] = $process;

            return $process;
        }
    };
    app()->instance(ProcessMediaAction::class, $sleeping);

    try {
        $startedAt = microtime(true);
        (new ProcessMediaAsset($asset, $key))->handle();
        $elapsed = microtime(true) - $startedAt;

        expect($elapsed)->toBeLessThan(9.0, 'timed-out worker must be terminated by the timeout bound');
        $clip = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($clip->status)->toBe(MediaClipAnalysis::STATUS_FAILED);
        expect($clip->error)->toBe('analysis_failed');
        expect($clip->candidates ?? [])->toBeEmpty();
        expect(MediaSceneAnalysis::where('media_asset_id', $asset->id)->first()->status)
            ->toBe(MediaSceneAnalysis::STATUS_COMPLETED);
        foreach ($spawned as $child) {
            expect($child->isRunning())->toBeFalse('no live worker child may remain');
        }
    } finally {
        foreach ($spawned as $child) {
            try {
                if ($child->isRunning()) {
                    $child->stop(2.0);
                }
            } catch (\Throwable) {
            }
        }
        issue60Cleanup($asset);
    }
});

it('V active configuration exposes no independent lock-wait setting', function () {
    expect(config('media'))->not->toHaveKey('clip_analysis_lock_wait_seconds');
});

it('V completion accepts the derived timeout pair without the removed setting', function () {
    $golden = issue60Golden()['analysis'];
    $snapshot = [
        'duration_ms' => 30000,
        'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]],
        'configuration' => $golden['parameters']['configuration'],
    ];

    ClipAnalysisValidator::validateCompletion($golden, $snapshot, ['timeout_seconds' => 30, 'lock_wait_seconds' => 35]);

    ClipAnalysisValidator::validateCompletion($golden, $snapshot, ['timeout_seconds' => 7, 'lock_wait_seconds' => 12]);
});
