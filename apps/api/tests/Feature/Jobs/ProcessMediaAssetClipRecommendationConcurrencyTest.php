<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PDOException;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\M5RecommendationFixture as Fixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Durable M5 arbitration invariants that need no second connection
|--------------------------------------------------------------------------
|
| Protocol migrated to the replacement specification. True multi-connection
| arbitration is mandatory and is not expressible on the in-memory SQLite
| target, so it lives in the authorized-disposable-PostgreSQL sibling
| ProcessMediaAssetClipRecommendationRecoveryTest instead of being skipped
| here. Nothing in this file is conditional or suppressed.
|
*/

uses(TestCase::class, RefreshDatabase::class);

/**
 * The conflict-safe first insert the claim performs.
 */
function m5CreateRow(MediaAsset $asset): void
{
    DB::table('media_clip_recommendations')->insertOrIgnore([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/*
|--------------------------------------------------------------------------
| One durable recommendation owner per asset
|--------------------------------------------------------------------------
*/

it('arbitrates first creation to exactly one durable row', function () {
    $asset = Fixture::probedAsset();
    Fixture::completedM4($asset);
    Fixture::transcript($asset);

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeFalse();

    // Repeated conflict-safe attempts never create a second owner.
    m5CreateRow($asset);
    m5CreateRow($asset);

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->count())->toBe(1);

    // A plain second insert for the same asset must fail.
    $this->expectException(QueryException::class);
    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
});

it('verifies recommendation row has unique media_asset_id constraint', function () {
    $asset1 = MediaAsset::factory()->create();
    $asset2 = MediaAsset::factory()->create();

    // Create recommendation for asset1
    MediaClipRecommendation::create([
        'media_asset_id' => $asset1->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    // Attempting to create another for same asset should fail
    $this->expectException(QueryException::class);
    MediaClipRecommendation::create([
        'media_asset_id' => $asset1->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    // But different asset should work
    MediaClipRecommendation::create([
        'media_asset_id' => $asset2->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
});

/*
|--------------------------------------------------------------------------
| Isolation of the authoritative M4 binding
|--------------------------------------------------------------------------
*/

it('never binds a recommendation to another asset M4 authority', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    $other = Fixture::completedM4(MediaAsset::factory()->create());
    $otherBefore = $other->fresh()->getRawOriginal();

    $row = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
    $row->markRanking();

    $before = $row->fresh()->getRawOriginal();

    // The other asset's completed M4 row is a complete, valid authority in its
    // own right, yet it is not this recommendation's authority.
    expect(fn () => $row->fresh()->markUnavailable(
        'no_audio',
        Fixture::localUnavailableOutcome($other, 'no_audio')
    ))->toThrow(ProcessMediaException::class);

    expect($row->fresh()->getRawOriginal())->toBe($before);
    expect($other->fresh()->getRawOriginal())->toBe($otherBefore);
});

/*
|--------------------------------------------------------------------------
| Terminal immutability
|--------------------------------------------------------------------------
*/

it('never downgrades a terminal row through a stale callback', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);

    $row = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
    $row->markRanking();
    $row->markUnavailable('no_audio', Fixture::localUnavailableOutcome($m4, 'no_audio'));

    $terminal = $row->fresh();
    $before = $terminal->getRawOriginal();

    expect($terminal->isTerminal())->toBeTrue();

    // A stale callback can neither mark ranking nor downgrade a terminal row.
    $terminal->markRanking();
    $terminal->markFailed(MediaClipRecommendation::ERROR_RANKING_FAILED);
    expect(fn () => $terminal->markCompleted(
        MediaClipRecommendation::OUTCOME_RANKED,
        Fixture::localUnavailableOutcome($m4, 'no_audio')
    ))->toThrow(ProcessMediaException::class);
    expect(fn () => $terminal->markUnavailable(
        'missing',
        Fixture::localUnavailableOutcome($m4, 'missing')
    ))->toThrow(ProcessMediaException::class);

    expect($row->fresh()->getRawOriginal())->toBe($before);
    expect(MediaClipAnalysis::where('media_asset_id', $asset->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Failed retry
|--------------------------------------------------------------------------
*/

it('lets a failed attempt retry with the current configuration and clears only M5 state', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    $m4Before = $m4->fresh()->getRawOriginal();

    $row = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
    $row->markRanking();
    $row->markFailed(MediaClipRecommendation::ERROR_RANKING_FAILED);

    $failed = $row->fresh();
    expect($failed->status)->toBe(MediaClipRecommendation::STATUS_FAILED);
    expect($failed->error)->toBe(MediaClipRecommendation::ERROR_RANKING_FAILED);
    expect($failed->recommendations)->toBeNull();
    expect($failed->outcome)->toBeNull();
    expect($failed->reason)->toBeNull();

    // A failed row may retry with the currently validated configuration,
    // because no successful result exists yet.
    $failed->markRanking();
    $retried = $row->fresh();
    expect($retried->status)->toBe(MediaClipRecommendation::STATUS_RANKING);
    expect($retried->error)->toBeNull();
    expect($retried->m4_analysis_id)->toBe($m4->id);

    // The upstream M4 row is byte-for-byte unchanged by the retry.
    expect($m4->fresh()->getRawOriginal())->toBe($m4Before);
});

/*
|--------------------------------------------------------------------------
| Bounded contention never finalizes the owner or the asset
|--------------------------------------------------------------------------
|
| Spec "Claim, fencing, and recovery" point 6: SQLSTATE 55P03 is busy only
| after rollback, so the contender makes no worker call and cannot finalize
| the owner or the asset. Both M5 claim helpers classify 55P03 as bounded
| contention and return normally, therefore the caller has to verify a
| persisted resolved outcome before the four-stage check may markCompleted().
|
| The in-memory SQLite target never raises 55P03, so the exact SQLSTATE the
| production classifier matches is raised at the claim's first durable write,
| which is where a real contender observes the row lock.
|
*/

/**
 * A QueryException carrying PostgreSQL SQLSTATE 55P03 (lock timeout).
 */
function m5LockTimeout(): QueryException
{
    $exception = new QueryException(
        'pgsql',
        'update media_clip_recommendations set m4_analysis_id = 1',
        [],
        new PDOException('simulated lock contention'),
    );

    // QueryException copies the previous code in its constructor, so the
    // effective `code` property is rewritten to the exact SQLSTATE the
    // production classifier matches. PHP 8.1+ reflection reaches it without
    // setAccessible, and the property is untyped because QueryException stores
    // string SQLSTATEs in it during normal operation.
    $code = new ReflectionProperty($exception::class, 'code');
    $code->setValue($exception, '55P03');

    if ((string) $exception->getCode() !== '55P03') {
        throw new RuntimeException('SETUP_BLOCKER: SQLSTATE 55P03 could not be synthesized');
    }

    return $exception;
}

/**
 * Make the next durable M5 write report bounded contention, exactly once.
 *
 * Returns a disarm callback, so a registered listener can never fire again
 * once the attempt has finished.
 */
function m5ArmContention(): Closure
{
    $armed = true;

    MediaClipRecommendation::saving(function () use (&$armed): void {
        if ($armed) {
            $armed = false;

            throw m5LockTimeout();
        }
    });

    return function () use (&$armed): void {
        $armed = false;
    };
}

it('does not finalize the asset when the worker claim is bounded contention', function () {
    // The lock_contender fixture shape: a durable pending row already exists,
    // so the losing contender's rollback restores `pending`, not absence.
    $asset = Fixture::probedAsset();
    Fixture::completedM4($asset);
    Fixture::transcript($asset);
    m5CreateRow($asset);

    $action = Fixture::recordingAction();
    $disarm = m5ArmContention();

    try {
        (new ProcessMediaAsset($asset, $asset->idempotency_key, $action))->handle();
    } finally {
        $disarm();
    }

    // A busy contender never finalizes the owner's asset...
    expect($asset->fresh()->processing_status)->not->toBe(MediaAsset::PROCESSING_COMPLETED);

    // ...never runs a worker...
    expect($action->rankCalls)->toBe(0);

    // ...and commits no ranking transition over the owner's pending row.
    expect(Fixture::row($asset)?->status)->toBe(MediaClipRecommendation::STATUS_PENDING);
});

it('does not finalize the asset when the local outcome claim is bounded contention', function () {
    $logs = new TestHandler;
    Log::swap(new Logger('m5-contention', [$logs]));

    // No audio stream: readiness classifies NO_AUDIO, so M5 must take the
    // local unavailable branch and never reach the worker claim.
    $asset = Fixture::probedAsset(null);
    Fixture::completedM4($asset);
    Fixture::transcript($asset, 'completed');
    m5CreateRow($asset);

    $action = Fixture::recordingAction();
    $disarm = m5ArmContention();

    try {
        (new ProcessMediaAsset($asset, $asset->idempotency_key, $action))->handle();
    } finally {
        $disarm();
    }

    // Branch proof: the no-audio audio path is what licenses the local outcome.
    $messages = array_map(
        static fn ($record): string => (string) $record['message'],
        $logs->getRecords(),
    );
    expect(implode(PHP_EOL, $messages))->toContain('no audio stream, skipping audio path');

    // A busy local commit persists nothing, so the asset stays unfinalized...
    expect($asset->fresh()->processing_status)->not->toBe(MediaAsset::PROCESSING_COMPLETED);

    // ...no worker was ever invoked...
    expect($action->rankCalls)->toBe(0);

    // ...and the owner's pending row is untouched by the rolled-back claim.
    expect(Fixture::row($asset)?->status)->toBe(MediaClipRecommendation::STATUS_PENDING);
});
