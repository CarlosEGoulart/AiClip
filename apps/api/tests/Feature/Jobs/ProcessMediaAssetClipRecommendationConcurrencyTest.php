<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
