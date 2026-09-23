<?php

namespace Tests\Feature\Jobs;

use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
 * Issue #60 C1 focused RED: concurrent first creation on real PostgreSQL.
 *
 * No RefreshDatabase/DatabaseTransactions outer transaction: fixtures are
 * committed so independent child PHP processes can observe them. Guards verify
 * the authorized disposable target before any destructive setup.
 */
it('concurrent first creation yields one durable row with one worker call and loser reuse', function () {
    // Parent guards before any destructive operation.
    expect(extension_loaded('pdo_pgsql'))->toBeTrue('pdo_pgsql must be loaded');
    expect(config('database.default'))->toBe('pgsql');
    expect(config('database.connections.pgsql.driver'))->toBe('pgsql');
    expect(config('database.connections.pgsql.database'))->toBe('aiclip_test_issue60');
    expect(DB::select('SELECT 1 AS one')[0]->one)->toBe(1);
    expect(DB::select('SELECT current_database() AS db')[0]->db)->toBe('aiclip_test_issue60');
    expect(DB::connection()->getDatabaseName())->toBe('aiclip_test_issue60');
    expect(DB::transactionLevel())->toBe(0);

    $parentPidRow = DB::select('SELECT pg_backend_pid() AS pid');
    $parentPgPid = (int) ($parentPidRow[0]->pid ?? 0);
    expect($parentPgPid)->toBeGreaterThan(0);

    // Migrate only the authorized disposable target.
    Artisan::call('migrate', ['--force' => true]);

    $idempotencyKey = '660e8400-e29b-41d4-a716-446655440060';
    $probe = [
        'duration_ms' => 30000,
        'video_codec' => 'h264',
        'audio_codec' => null,
    ];
    $scenes = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 30000],
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

    // Committed fixture must be visible outside any outer transaction.
    expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->exists())->toBeFalse();

    $runDir = sys_get_temp_dir().'/issue60-c1-'.uniqid('', true);
    mkdir($runDir, 0777, true);

    $childScript = base_path('tests/Support/Issue60C1Child.php');
    $owner = new Process([PHP_BINARY, $childScript, (string) $asset->id, $idempotencyKey, 'owner', $runDir]);
    $contender = new Process([PHP_BINARY, $childScript, (string) $asset->id, $idempotencyKey, 'contender', $runDir]);
    $owner->setTimeout(60.0);
    $contender->setTimeout(60.0);

    try {
        $owner->start();
        $contender->start();

        // Bounded barrier: both children must record pre-read absence.
        $deadline = microtime(true) + 25.0;
        while (! file_exists($runDir.'/owner_ready') || ! file_exists($runDir.'/contender_ready')) {
            if (microtime(true) > $deadline) {
                break;
            }
            usleep(20000);
        }

        expect(file_exists($runDir.'/owner_ready'))->toBeTrue('owner child must reach barrier');
        expect(file_exists($runDir.'/contender_ready'))->toBeTrue('contender child must reach barrier');

        $ownerPreread = json_decode((string) file_get_contents($runDir.'/owner_preread.json'), true);
        $contenderPreread = json_decode((string) file_get_contents($runDir.'/contender_preread.json'), true);
        expect($ownerPreread['exists'])->toBeFalse('owner must observe absence before insert');
        expect($contenderPreread['exists'])->toBeFalse('contender must observe absence before insert');

        // Distinct independent PostgreSQL backends (and distinct from parent).
        expect((int) $ownerPreread['pg_pid'])->toBeGreaterThan(0);
        expect((int) $contenderPreread['pg_pid'])->toBeGreaterThan(0);
        expect((int) $ownerPreread['pg_pid'])->not->toBe((int) $contenderPreread['pg_pid']);
        expect((int) $ownerPreread['pg_pid'])->not->toBe($parentPgPid);
        expect((int) $contenderPreread['pg_pid'])->not->toBe($parentPgPid);

        // Release both production callers at once toward the real unique race.
        file_put_contents($runDir.'/release', 'go');

        $owner->wait();
        $contender->wait();

        $ownerResult = json_decode((string) file_get_contents($runDir.'/owner_result.json'), true);
        $contenderResult = json_decode((string) file_get_contents($runDir.'/contender_result.json'), true);

        // Required C1 outcome on the unchanged merged implementation.
        expect($ownerResult['ok'])->toBeTrue('owner production call must succeed');
        expect($contenderResult['ok'])->toBeTrue('contender production call must reuse instead of failing');

        expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->count())->toBe(1);

        $calls = file_exists($runDir.'/worker_calls.log')
            ? array_values(array_filter(explode(PHP_EOL, (string) file_get_contents($runDir.'/worker_calls.log'))))
            : [];
        expect(count($calls))->toBe(1, 'exactly one total worker invocation is required');

        $clip = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        expect($clip->status)->toBe(MediaClipAnalysis::STATUS_COMPLETED);
        expect($clip->candidates)->not->toBeEmpty();
    } finally {
        // Bounded teardown: stop owned children, clean only this run fixture.
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

        try {
            MediaClipAnalysis::where('media_asset_id', $asset->id)->delete();
        } catch (\Throwable) {
        }
        try {
            MediaSceneAnalysis::where('media_asset_id', $asset->id)->delete();
        } catch (\Throwable) {
        }
        try {
            MediaAsset::where('id', $asset->id)->delete();
        } catch (\Throwable) {
        }
        try {
            $asset->project()->delete();
        } catch (\Throwable) {
        }

        foreach (glob($runDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($runDir);
    }
});
