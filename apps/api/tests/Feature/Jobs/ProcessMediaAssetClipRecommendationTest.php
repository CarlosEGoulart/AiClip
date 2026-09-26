<?php

namespace Tests\Feature\Jobs;

use App\Exceptions\ProcessMediaException;
use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipRecommendation;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationProjection;
use App\Services\ProcessMediaAction;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Support\M5RecommendationFixture as Fixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| M5 stage through the production job entry point
|--------------------------------------------------------------------------
|
| Protocol migrated to the replacement specification: the exact rank_clips
| request key set, the pinned versioned configuration, the K-entry
| recommendation shape and the local outcomes are all asserted here. Upstream
| rows come from the shared fixture helpers and are never hand-written.
|
*/

uses(TestCase::class, RefreshDatabase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Run one job attempt with the recording action bound in the container.
 */
function m5Run(MediaAsset $asset, ProcessMediaAction $action, int $attempt = 1, ?array &$releases = null): void
{
    $queue = Mockery::mock(Job::class);
    $queue->shouldReceive('attempts')->andReturn($attempt);
    $queue->shouldReceive('release')->andReturnUsing(function ($delay) use (&$releases): void {
        if ($releases !== null) {
            $releases[] = $delay;
        }
    });

    app()->instance(ProcessMediaAction::class, $action);

    (new ProcessMediaAsset($asset, $asset->idempotency_key, $action))->setJob($queue)->handle();
}

/*
|--------------------------------------------------------------------------
| Worker results
|--------------------------------------------------------------------------
*/

it('invokes ranking once with the exact request and records a completed recommendation', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    Fixture::transcript($asset);
    $m4Before = $m4->fresh()->getRawOriginal();

    $action = Fixture::recordingAction();
    m5Run($asset, $action);

    // Exactly one bounded worker invocation.
    expect($action->rankCalls)->toBe(1);

    // The exact request key set, with no criteria, scene, transcript metadata,
    // provider identity, storage or asset identity crossing the boundary.
    $request = $action->rankRequests[0];
    expect(array_keys($request))->toBe(['version', 'action', 'media', 'candidates', 'configuration']);
    expect($request['version'])->toBe('1.0.0');
    expect($request['action'])->toBe('rank_clips');
    expect($request['media'])->toBe(['duration_ms' => 40000]);
    expect($request)->not->toHaveKey('media_asset_id')
        ->and($request)->not->toHaveKey('project_id')
        ->and($request)->not->toHaveKey('storage')
        ->and($request)->not->toHaveKey('transcript_segments')
        ->and($request)->not->toHaveKey('scenes');

    // Only the minimal per-candidate projection crosses, and the M4 numeric
    // score is preserved exactly.
    expect($request['candidates'])->toBe([
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1, 'm4_score' => 0.75, 'transcript_text' => 'first window text'],
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2, 'm4_score' => 0.75, 'transcript_text' => 'second window text'],
    ]);

    // The configuration is the exact pinned selected profile.
    expect($request['configuration'])->toBe(ClipRankingProfile::configuration('fake'));
    expect($request['configuration']['provider'])->toBe('fake');
    expect($request['configuration']['model_id'])->toBe('fake-ranking-v1');
    expect($request['configuration']['algorithm'])->toBe('transcript_semantic_recommendation');

    $row = Fixture::row($asset);
    expect($row)->not->toBeNull();
    expect($row->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($row->outcome)->toBe(MediaClipRecommendation::OUTCOME_RANKED);
    expect($row->error)->toBeNull();
    expect($row->m4_analysis_id)->toBe($m4->id);
    expect($row->algorithm)->toBe('transcript_semantic_recommendation');
    expect($row->algorithm_version)->toBe('1.0.0');

    // K exact references, contiguous semantic ranks, truthful fake provenance.
    expect($row->recommendations)->toHaveCount(2);
    expect($row->recommendations[0]['m4_candidate_index'])->toBe(0);
    expect($row->recommendations[0]['semantic_score'])->toEqual(1.0);
    expect($row->recommendations[0]['semantic_rank'])->toBe(1);
    expect($row->recommendations[0]['reason'])->toBeNull();
    expect($row->recommendations[1]['m4_candidate_index'])->toBe(1);
    expect($row->recommendations[1]['semantic_score'])->toEqual(0.9);
    expect($row->recommendations[1]['semantic_rank'])->toBe(2);

    expect($row->parameters['provider_name'])->toBe('fake_ranking_provider');
    expect($row->parameters['inference_performed'])->toBeFalse();
    expect($row->parameters['transcript_used'])->toBeTrue();
    expect(json_encode($row->parameters))->not->toContain('MiniLM');

    // The snapshot binds the M4 authority, the classification, the projection
    // version, the locally constructed hashes and the exact request digest.
    expect(array_keys($row->input_snapshot))->toBe([
        'm4_analysis_id', 'm4_algorithm', 'm4_algorithm_version', 'm4_candidates',
        'duration_ms', 'transcript_state', 'projection_version', 'text_hashes', 'request_sha256',
    ]);
    expect($row->input_snapshot['m4_analysis_id'])->toBe($m4->id);
    expect($row->input_snapshot['m4_algorithm'])->toBe('scene_timing_baseline');
    expect($row->input_snapshot['m4_algorithm_version'])->toBe('1.0.0');
    expect($row->input_snapshot['duration_ms'])->toBe(40000);
    expect($row->input_snapshot['transcript_state'])->toBe('completed_valid');
    expect($row->input_snapshot['projection_version'])->toBe('1.0.0');
    expect($row->input_snapshot['m4_candidates'])->toBe($m4->fresh()->candidates);
    expect($row->input_snapshot['text_hashes'])->toBe([
        ['index' => 0, 'sha256' => ClipRecommendationProjection::digest('first window text')],
        ['index' => 1, 'sha256' => ClipRecommendationProjection::digest('second window text')],
    ]);
    // The recorded digest binds the snapshot to the exact bytes that were sent.
    expect($row->input_snapshot['request_sha256'])
        ->toBe(hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)));

    // Raw transcript text is never persisted in the new snapshot.
    expect(json_encode($row->input_snapshot))->not->toContain('window text');
    expect(json_encode($row->input_snapshot))->not->toContain(
        config('media.clip_ranking.prototype_query')
    );

    // The M4 row is byte-for-byte unchanged.
    expect($m4->fresh()->getRawOriginal())->toBe($m4Before);

    // All four stages resolved, so normal completion is allowed.
    expect($asset->fresh()->processing_status)->toBe('completed');
});

it('records a mixed candidate set as K entries with the unusable index unscored', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    // The second window no longer overlaps candidate 1, so only candidate 0
    // carries usable text.
    Fixture::transcript($asset, 'completed', [
        ['start_ms' => 500, 'end_ms' => 9500, 'text' => 'first window text'],
        ['start_ms' => 25000, 'end_ms' => 30000, 'text' => 'unrelated window text'],
    ]);

    $action = Fixture::recordingAction([
        'status' => 'success',
        'ranking' => [
            'algorithm' => 'transcript_semantic_recommendation',
            'algorithm_version' => '1.0.0',
            'parameters' => ClipRankingProfile::parameters(ClipRankingProfile::configuration('fake'), false, true),
            'request_sha256' => str_repeat('a', 64),
            'recommendations' => [
                ['m4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 0.75, 'semantic_score' => 1.0, 'semantic_rank' => 1, 'reason' => null],
                ['m4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.75, 'semantic_score' => null, 'semantic_rank' => null,
                    'reason' => 'no_candidate_text'],
            ],
        ],
    ]);

    m5Run($asset, $action);

    expect($action->rankCalls)->toBe(1);
    expect($action->rankRequests[0]['candidates'][1]['transcript_text'])->toBe('');

    $row = Fixture::row($asset);
    expect($row->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    // Always K entries, never fewer.
    expect($row->recommendations)->toHaveCount(2);
    expect($row->recommendations[1]['semantic_score'])->toBeNull();
    expect($row->recommendations[1]['semantic_rank'])->toBeNull();
    expect($row->recommendations[1]['reason'])->toBe('no_candidate_text');
    expect($row->input_snapshot['text_hashes'][1]['sha256'])
        ->toBe(ClipRecommendationProjection::digest(''));
    expect($row->m4_analysis_id)->toBe($m4->id);
});

/*
|--------------------------------------------------------------------------
| Local outcomes
|--------------------------------------------------------------------------
*/

it('completes locally with no candidates and never invokes the worker', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedEmptyM4($asset);
    $m4Before = $m4->fresh()->getRawOriginal();
    Fixture::transcript($asset);

    $action = Fixture::recordingAction();
    m5Run($asset, $action);

    expect($action->rankCalls)->toBe(0);

    $row = Fixture::row($asset);
    expect($row->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($row->outcome)->toBe(MediaClipRecommendation::OUTCOME_NO_CANDIDATES);
    expect($row->recommendations)->toBe([]);
    expect($row->parameters['inference_performed'])->toBeFalse();
    expect($row->parameters['transcript_used'])->toBeFalse();
    expect($row->input_snapshot['request_sha256'])->toBeNull();
    expect($row->input_snapshot['m4_candidates'])->toBe([]);
    expect($row->input_snapshot['text_hashes'])->toBe([]);
    expect($m4->fresh()->getRawOriginal())->toBe($m4Before);
    expect($asset->fresh()->processing_status)->toBe('completed');
});

it('records a local unavailable outcome for each terminal upstream reason', function (string $state, string $reason) {
    $asset = Fixture::probedAsset($reason === 'no_audio' ? null : 'aac');
    $m4 = Fixture::completedM4($asset);
    $m4Before = $m4->fresh()->getRawOriginal();

    Fixture::transcript($asset, $state);
    $action = Fixture::recordingAction();
    m5Run($asset, $action);

    // No local outcome ever reaches the worker.
    expect($action->rankCalls)->toBe(0);

    $row = Fixture::row($asset);
    expect($row->status)->toBe(MediaClipRecommendation::STATUS_UNAVAILABLE);
    expect($row->outcome)->toBe(MediaClipRecommendation::OUTCOME_UNAVAILABLE);
    expect($row->reason)->toBe($reason);
    expect($row->error)->toBeNull();
    expect($row->m4_analysis_id)->toBe($m4->id);

    // K exact references, null semantic fields, the outcome reason repeated.
    expect($row->recommendations)->toHaveCount(2);
    foreach ($row->recommendations as $reference) {
        expect($reference['semantic_score'])->toBeNull();
        expect($reference['semantic_rank'])->toBeNull();
        expect($reference['reason'])->toBe($reason);
        expect($reference['m4_score'])->toBe(0.75);
    }

    // Same keys and profile values as worker parameters, but no inference and
    // no transcript use, and no worker request digest.
    expect(array_keys($row->parameters))->toBe(ClipRankingProfile::parameterKeys());
    expect($row->parameters['inference_performed'])->toBeFalse();
    expect($row->parameters['transcript_used'])->toBeFalse();
    expect($row->input_snapshot['request_sha256'])->toBeNull();
    expect($row->input_snapshot['transcript_state'])->toBe($reason);

    // Empty-string digests only: no candidate text is invented.
    foreach ($row->input_snapshot['text_hashes'] as $hash) {
        expect($hash['sha256'])->toBe(ClipRecommendationProjection::digest(''));
    }

    // No stale transcript text is ever persisted.
    expect(json_encode([$row->recommendations, $row->input_snapshot]))->not->toContain('window text');

    // The M4 row is byte-for-byte unchanged.
    expect($m4->fresh()->getRawOriginal())->toBe($m4Before);
})->with([
    'no_audio' => ['completed', 'no_audio'],
    'completed_empty' => ['empty', 'completed_empty'],
    'transcription_failed' => ['failed', 'transcription_failed'],
]);

it('records a local unavailable outcome when the authoritative audio extraction failed', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    $m4Before = $m4->fresh()->getRawOriginal();

    // The transcript is stale: it references an archived derivative, so the
    // authoritative audio extraction boundary is really reached and fails.
    Fixture::transcript($asset, 'completed', null, true);

    $action = Fixture::recordingAction(null, true);
    m5Run($asset, $action);

    expect($action->rankCalls)->toBe(0);

    $row = Fixture::row($asset);
    expect($row->status)->toBe(MediaClipRecommendation::STATUS_UNAVAILABLE);
    expect($row->reason)->toBe('extraction_failed');
    expect($row->recommendations)->toHaveCount(2);
    expect($row->recommendations[0]['reason'])->toBe('extraction_failed');
    expect($row->recommendations[0]['semantic_score'])->toBeNull();
    expect($row->parameters['inference_performed'])->toBeFalse();
    expect($row->parameters['transcript_used'])->toBeFalse();
    expect($row->input_snapshot['request_sha256'])->toBeNull();

    // No stale transcript text is invented for the discarded transcript.
    foreach ($row->input_snapshot['text_hashes'] as $hash) {
        expect($hash['sha256'])->toBe(ClipRecommendationProjection::digest(''));
    }
    expect(json_encode([$row->recommendations, $row->input_snapshot]))->not->toContain('window text');

    // The extraction failure is preserved and the M4 row is byte-for-byte intact.
    expect($asset->fresh()->processing_status)->toBe('failed');
    expect($m4->fresh()->getRawOriginal())->toBe($m4Before);
});

it('records no_candidate_text when a completed transcript overlaps no window', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);

    Fixture::transcript($asset, 'completed', Fixture::disjointSegments());

    $action = Fixture::recordingAction();
    m5Run($asset, $action);

    expect($action->rankCalls)->toBe(0);

    $row = Fixture::row($asset);
    expect($row->status)->toBe(MediaClipRecommendation::STATUS_UNAVAILABLE);
    expect($row->reason)->toBe('no_candidate_text');
    // The recorded classification is the authoritative one, not the reason.
    expect($row->input_snapshot['transcript_state'])->toBe('completed_valid');
    expect($row->recommendations)->toHaveCount(2);
    expect($row->recommendations[0]['reason'])->toBe('no_candidate_text');
    foreach ($row->input_snapshot['text_hashes'] as $hash) {
        expect($hash['sha256'])->toBe(ClipRecommendationProjection::digest(''));
    }
    expect($m4->id)->toBe($row->m4_analysis_id);
});

it('fails invalid_input without invoking the worker for a malformed completed transcript', function () {
    $asset = Fixture::probedAsset();
    Fixture::completedM4($asset);
    // A completed row whose segments are not a chronological list.
    Fixture::transcript($asset);
    $asset->transcript()->first()->update(['segments' => [['start_ms' => 9000, 'end_ms' => 1000, 'text' => 'backwards']]]);

    $action = Fixture::recordingAction();

    expect(fn () => m5Run($asset, $action))->toThrow(ProcessMediaException::class);

    expect($action->rankCalls)->toBe(0);
    expect(Fixture::row($asset)?->recommendations)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Not ready
|--------------------------------------------------------------------------
*/

it('never commits a ranking for an upstream stage that is still in flight', function (string $state) {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    $m4Before = $m4->fresh()->getRawOriginal();
    Fixture::transcript($asset, $state);

    $action = Fixture::recordingAction();
    try {
        m5Run($asset, $action);
    } catch (ProcessMediaException) {
        // The intervening upstream transcription failure is not this assertion.
    }

    // The current job retries an in-flight transcript before M5 can classify
    // it, so the staging double refuses to invent a readiness the production
    // job never had. What matters here is that no ranking ran and the upstream
    // M4 authority is untouched.
    expect($action->rankCalls)->toBe(0);
    expect($m4->fresh()->getRawOriginal())->toBe($m4Before);
})->with(['pending', 'transcribing']);

/*
|--------------------------------------------------------------------------
| Terminal reuse and version conflicts
|--------------------------------------------------------------------------
*/

it('reuses a terminal recommendation with zero worker calls and unchanged timestamps', function () {
    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    Fixture::transcript($asset);

    $first = Fixture::recordingAction();
    m5Run($asset, $first);
    expect($first->rankCalls)->toBe(1);

    $terminal = Fixture::row($asset);
    $attributes = $terminal->getAttributes();
    $createdAt = $terminal->created_at;
    $updatedAt = $terminal->updated_at;

    // A later transcript change and an operational timeout change alone must
    // not invalidate the terminal result.
    $asset->transcript()->first()->update(['segments' => [
        ['start_ms' => 500, 'end_ms' => 9500, 'text' => 'completely different first window'],
        ['start_ms' => 10500, 'end_ms' => 19500, 'text' => 'completely different second window'],
    ]]);
    config(['media.clip_ranking_timeout_seconds' => 120]);

    // Pipeline rerun fixture: the asset is queued again from the probed state.
    // A query-level write is required because the in-memory model is unchanged.
    MediaAsset::whereKey($asset->id)->update(['processing_status' => MediaAsset::PROCESSING_PROBED]);

    $second = Fixture::recordingAction();
    m5Run($asset, $second);

    expect($second->rankCalls)->toBe(0);
    expect(Fixture::row($asset)->getAttributes())->toBe($attributes);
    expect(Fixture::row($asset)->created_at->equalTo($createdAt))->toBeTrue();
    expect(Fixture::row($asset)->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('signals a version conflict on a changed semantic selection without mutating the terminal row', function () {
    $asset = Fixture::probedAsset();
    Fixture::completedM4($asset);
    Fixture::transcript($asset);

    m5Run($asset, Fixture::recordingAction());
    $terminal = Fixture::row($asset);
    $attributes = $terminal->getAttributes();

    // A different provider selection is a different semantic selection.
    config(['media.clip_ranking_provider' => 'cross_encoder']);

    // Pipeline rerun fixture: the asset is queued again from the probed state.
    MediaAsset::whereKey($asset->id)->update(['processing_status' => MediaAsset::PROCESSING_PROBED]);

    $action = Fixture::recordingAction();
    $signal = null;
    try {
        m5Run($asset, $action);
    } catch (ProcessMediaException $exception) {
        $signal = $exception->getMessage();
    }

    expect($signal)->toBe('recommendation_version_conflict');
    expect($action->rankCalls)->toBe(0);
    expect(Fixture::row($asset)->getAttributes())->toBe($attributes);

    // The safe failure path may fail only a nonterminal asset, and the
    // terminal M5 row is never touched.
    $job = (new ProcessMediaAsset($asset, $asset->idempotency_key, $action))
        ->setJob(Mockery::mock(Job::class));
    $job->failed(new ProcessMediaException('recommendation_version_conflict', 1, ''));
    expect($asset->fresh()->processing_status)->toBe('failed');
    expect(Fixture::row($asset)->getAttributes())->toBe($attributes);

    // A terminal asset is never downgraded by the same failure.
    MediaAsset::whereKey($asset->id)->update(['processing_status' => MediaAsset::PROCESSING_COMPLETED]);
    $job->failed(new ProcessMediaException('recommendation_version_conflict', 1, ''));
    expect($asset->fresh()->processing_status)->toBe('completed');
    expect(Fixture::row($asset)->getAttributes())->toBe($attributes);
});

/*
|--------------------------------------------------------------------------
| Trusted configuration
|--------------------------------------------------------------------------
*/

it('fails invalid_configuration on a new attempt for an unknown or unset selection', function (mixed $selection) {
    config(['media.clip_ranking_provider' => $selection]);

    $asset = Fixture::probedAsset();
    $m4 = Fixture::completedM4($asset);
    $m4Before = $m4->fresh()->getRawOriginal();
    Fixture::transcript($asset);

    $action = Fixture::recordingAction();
    $signal = null;
    try {
        m5Run($asset, $action);
    } catch (ProcessMediaException $exception) {
        $signal = $exception->getMessage();
    }

    // Fails closed before any process is created and never falls back.
    expect($signal)->toBe('invalid_configuration');
    expect($action->rankCalls)->toBe(0);
    expect(Fixture::row($asset))->toBeNull();
    expect($m4->fresh()->getRawOriginal())->toBe($m4Before);
    expect($asset->fresh()->processing_status)->not->toBe('completed');
})->with([
    'unset' => [null],
    'unknown' => ['not_a_provider'],
    'empty' => [''],
]);

/*
|--------------------------------------------------------------------------
| M4 authority
|--------------------------------------------------------------------------
*/

it('fails upstream_m4_unavailable when the M4 analysis never resolved', function () {
    $asset = Fixture::probedAsset();
    Fixture::transcript($asset);

    $action = Fixture::recordingAction();
    m5Run($asset, $action);

    expect($action->rankCalls)->toBe(0);

    $row = Fixture::row($asset);
    expect($row->status)->toBe(MediaClipRecommendation::STATUS_FAILED);
    expect($row->error)->toBe(MediaClipRecommendation::ERROR_UPSTREAM_M4_UNAVAILABLE);
    expect($row->recommendations)->toBeNull();
    expect($row->outcome)->toBeNull();
    expect($row->reason)->toBeNull();
    expect($row->input_snapshot)->toBeNull();
});
