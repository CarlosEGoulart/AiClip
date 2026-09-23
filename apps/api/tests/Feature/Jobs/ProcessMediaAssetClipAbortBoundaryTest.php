<?php

namespace Tests\Feature\Jobs;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Services\ProcessMediaAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\ClipAnalysisFixture as Fixture;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Issue #60 abort-boundary TEST-ONLY pass on the current implementation.
 *
 * Real PostgreSQL on the authorized disposable target with committed
 * fixtures and no outer RefreshDatabase transaction. Each unexpected
 * throwable is injected inside the actual clip claim transaction
 * (transaction level is observed at injection). Callers catch the
 * sanitized signal individually so persistence assertions execute.
 */

function abortBoundaryGuard(): void
{
    expect(extension_loaded('pdo_pgsql'))->toBeTrue('pdo_pgsql must be loaded');
    expect(config('database.default'))->toBe('pgsql');
    expect(config('database.connections.pgsql.driver'))->toBe('pgsql');
    expect(config('database.connections.pgsql.database'))->toBe('aiclip_test_issue60');
    expect(DB::select('SELECT 1 AS one')[0]->one)->toBe(1);
    expect(DB::select('SELECT current_database() AS db')[0]->db)->toBe('aiclip_test_issue60');
    expect(DB::connection()->getDatabaseName())->toBe('aiclip_test_issue60');
    expect(DB::transactionLevel())->toBe(0);
}

function abortBoundaryReadyAsset(string $key): MediaAsset
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

function abortBoundaryCleanup(?MediaAsset $asset): void
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

function abortBoundaryThrowingAction(\Throwable $failure, array &$levels): ProcessMediaAction
{
    return new class($failure, $levels) extends ProcessMediaAction
    {
        public function __construct(private \Throwable $failure, private array &$levels) {}

        public function analyzeClips(MediaProcessingContract $contract): array
        {
            $this->levels[] = DB::transactionLevel();

            throw $this->failure;
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

function abortBoundaryAssertSignal(?\Throwable $signal, string $context): void
{
    expect($signal)->not->toBeNull($context.': caller must receive the sanitized abort signal');
    expect($signal)->toBeInstanceOf(ProcessMediaException::class, $context.': abort uses the existing exception type');
    expect($signal->getMessage())->toBe('clip_analysis_aborted', $context.': abort carries the fixed sanitized discriminator');
    expect($signal->getPrevious())->toBeNull($context.': abort carries no previous cause');
}

beforeEach(function () {
    abortBoundaryGuard();
    Artisan::call('migrate', ['--force' => true]);
});

it('unexpected RuntimeException aborts with sanitized signal and full rollback', function () {
    $levels = [];
    app()->instance(ProcessMediaAction::class, abortBoundaryThrowingAction(new \RuntimeException('SENTINEL_RUNTIME_ABORT'), $levels));

    $assetPending = abortBoundaryReadyAsset('660e8400-e29b-41d4-a716-446655440080');
    $assetFailed = abortBoundaryReadyAsset('660e8400-e29b-41d4-a716-446655440081');
    $assetNew = abortBoundaryReadyAsset('660e8400-e29b-41d4-a716-446655440082');

    try {
        MediaClipAnalysis::create(['media_asset_id' => $assetPending->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $failed = MediaClipAnalysis::create(['media_asset_id' => $assetFailed->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $failed->markAnalyzing();
        $failed->markFailed('orig_error');

        $pendingBefore = MediaClipAnalysis::where('media_asset_id', $assetPending->id)->first()->getRawOriginal();
        $failedBefore = MediaClipAnalysis::where('media_asset_id', $assetFailed->id)->first()->getRawOriginal();

        $signalPending = null;
        try {
            (new ProcessMediaAsset($assetPending, '660e8400-e29b-41d4-a716-446655440080'))->handle();
        } catch (\Throwable $e) {
            $signalPending = $e;
        }

        $signalFailed = null;
        try {
            (new ProcessMediaAsset($assetFailed, '660e8400-e29b-41d4-a716-446655440081'))->handle();
        } catch (\Throwable $e) {
            $signalFailed = $e;
        }

        $signalNew = null;
        try {
            (new ProcessMediaAsset($assetNew, '660e8400-e29b-41d4-a716-446655440082'))->handle();
        } catch (\Throwable $e) {
            $signalNew = $e;
        }

        expect($levels)->not->toBeEmpty('injection must occur inside the claim');
        foreach ($levels as $level) {
            expect($level)->toBeGreaterThanOrEqual(1, 'throwable must originate inside the claim transaction');
        }

        $pendingAfter = MediaClipAnalysis::where('media_asset_id', $assetPending->id)->first();
        expect($pendingAfter->getRawOriginal())->toBe($pendingBefore);
        expect($pendingAfter->status)->not->toBe(MediaClipAnalysis::STATUS_ANALYZING);

        $failedAfter = MediaClipAnalysis::where('media_asset_id', $assetFailed->id)->first();
        expect($failedAfter->getRawOriginal())->toBe($failedBefore);
        expect($failedAfter->error)->toBe('orig_error');

        expect(MediaClipAnalysis::where('media_asset_id', $assetNew->id)->exists())->toBeFalse('new-row abort must roll back to absence');

        foreach ([$assetPending, $assetFailed, $assetNew] as $asset) {
            expect($asset->fresh()->processing_status)->not->toBe(MediaAsset::PROCESSING_COMPLETED);
            expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->where('error', 'analysis_failed')->exists())->toBeFalse();
        }
        expect(json_encode(MediaClipAnalysis::whereIn('media_asset_id', [$assetPending->id, $assetFailed->id])->pluck('error')))->not->toContain('SENTINEL_RUNTIME_ABORT');

        abortBoundaryAssertSignal($signalPending, 'pending');
        abortBoundaryAssertSignal($signalFailed, 'failed');
        abortBoundaryAssertSignal($signalNew, 'new-row');
    } finally {
        abortBoundaryCleanup($assetPending);
        abortBoundaryCleanup($assetFailed);
        abortBoundaryCleanup($assetNew);
    }
});

it('unexpected Error aborts with sanitized signal and preserves durable state', function () {
    $levels = [];
    app()->instance(ProcessMediaAction::class, abortBoundaryThrowingAction(new \Error('SENTINEL_ERROR_ABORT'), $levels));

    $asset = abortBoundaryReadyAsset('660e8400-e29b-41d4-a716-446655440083');

    try {
        MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $before = MediaClipAnalysis::where('media_asset_id', $asset->id)->first()->getRawOriginal();

        $signal = null;
        try {
            (new ProcessMediaAsset($asset, '660e8400-e29b-41d4-a716-446655440083'))->handle();
        } catch (\Throwable $e) {
            $signal = $e;
        }

        expect($levels)->not->toBeEmpty();
        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->first()->getRawOriginal())->toBe($before);
        expect($asset->fresh()->processing_status)->not->toBe(MediaAsset::PROCESSING_COMPLETED);

        abortBoundaryAssertSignal($signal, 'error');
    } finally {
        abortBoundaryCleanup($asset);
    }
});

it('unexpected throwable through the real action transport is not converted to worker failure', function () {
    $transportFailure = new class extends ProcessMediaAction
    {
        public array $levels = [];

        protected function createProcess(array $command): Process
        {
            $this->levels[] = DB::transactionLevel();

            throw new \RuntimeException('SENTINEL_TRANSPORT_ABORT');
        }
    };

    $asset = abortBoundaryReadyAsset('660e8400-e29b-41d4-a716-446655440084');

    try {
        app()->instance(ProcessMediaAction::class, $transportFailure);

        $jobSignal = null;
        try {
            (new ProcessMediaAsset($asset, '660e8400-e29b-41d4-a716-446655440084'))->handle();
        } catch (\Throwable $e) {
            $jobSignal = $e;
        }

        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->where('error', 'analysis_failed')->exists())->toBeFalse('transport abort must not commit a worker failure');
        expect($asset->fresh()->processing_status)->not->toBe(MediaAsset::PROCESSING_COMPLETED);

        abortBoundaryAssertSignal($jobSignal, 'transport');

        $directSignal = null;
        try {
            app(ProcessMediaAction::class)->analyzeClips(Fixture::contract());
        } catch (\Throwable $e) {
            $directSignal = $e;
        }
        expect($directSignal)->toBeInstanceOf(ProcessMediaException::class);
        expect($directSignal->getMessage())->toBe('clip_analysis_aborted');
        expect($directSignal->getPrevious())->toBeNull();
    } finally {
        abortBoundaryCleanup($asset);
    }
});

it('missing analyzeClips interaction aborts instead of resolving silently', function () {
    $actionMock = \Mockery::mock(ProcessMediaAction::class);
    $actionMock->shouldReceive('probe')->andReturn(['status' => 'success', 'probe' => ['duration_ms' => 30000, 'video_codec' => 'h264', 'audio_codec' => null]]);
    app()->instance(ProcessMediaAction::class, $actionMock);

    $asset = abortBoundaryReadyAsset('660e8400-e29b-41d4-a716-446655440085');

    try {
        $signal = null;
        try {
            (new ProcessMediaAsset($asset, '660e8400-e29b-41d4-a716-446655440085'))->handle();
        } catch (\Throwable $e) {
            $signal = $e;
        }

        expect($asset->fresh()->processing_status)->not->toBe(MediaAsset::PROCESSING_COMPLETED);
        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->where('error', 'analysis_failed')->exists())->toBeFalse();

        abortBoundaryAssertSignal($signal, 'missing-interaction');
    } finally {
        \Mockery::close();
        abortBoundaryCleanup($asset);
    }
});
