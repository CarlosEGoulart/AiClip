<?php

namespace Tests\Feature\Models;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationProjection;
use App\Services\ClipRecommendationValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ClipAnalysisFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Fixtures built through production M4 validation
|--------------------------------------------------------------------------
|
| Every M4 row here is produced by MediaClipAnalysis::markCompleted with the
| existing hand-derived scene_timing_baseline v1.0.0 golden, so the M5 tests
| bind a real authoritative M4 row and never a hand-written abbreviation.
|
*/

/**
 * A completed M4 analysis for the asset, from the shared golden fixture.
 */
function recommendationM4Analysis(MediaAsset $asset, ?array $candidates = null): MediaClipAnalysis
{
    $contract = ClipAnalysisFixture::contract();
    $analysis = ClipAnalysisFixture::response()['analysis'];

    $row = MediaClipAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipAnalysis::STATUS_PENDING,
    ]);
    $row->markAnalyzing();
    $row->markCompleted(
        $analysis['algorithm'],
        $analysis['algorithm_version'],
        $analysis['parameters'],
        $candidates ?? $analysis['candidates'],
        [
            'duration_ms' => 40000,
            'scenes' => $contract->scenes,
            'configuration' => $contract->configuration,
            'transcript_segments' => $contract->transcriptSegments,
        ],
        ['timeout_seconds' => 30, 'lock_wait_seconds' => 35],
    );

    return $row;
}

/**
 * A ranking row bound to the M4 authority, inside the claim's states.
 */
function recommendationRow(MediaAsset $asset, MediaClipAnalysis $m4, string $status = MediaClipRecommendation::STATUS_RANKING): MediaClipRecommendation
{
    $row = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
    $row->markRanking();

    expect((string) $row->fresh()->status)->toBe($status);

    return $row->fresh();
}

/**
 * A legitimately empty completed M4 analysis: no scene is eligible, so the
 * production M4 validator accepts a zero-candidate completion.
 */
function recommendationEmptyM4Analysis(MediaAsset $asset): MediaClipAnalysis
{
    $configuration = [
        'min_duration_ms' => 5000, 'target_duration_ms' => 30000,
        'max_duration_ms' => 60000, 'max_candidates' => 20,
        'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
    ];

    $row = MediaClipAnalysis::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipAnalysis::STATUS_PENDING,
    ]);
    $row->markAnalyzing();
    $row->markCompleted('scene_timing_baseline', '1.0.0', [
        'configuration' => $configuration,
        'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
        'transcript_used' => false,
        'candidate_policy' => 'whole_scene_non_overlapping',
        'timing_policy' => 'original_media_ms',
        'transcript_policy' => 'optional_strict_unshifted',
        'boundary_policy' => 'strict_interior_speech_cut',
        'score_scale' => 1000000, 'rounding' => 'half_up',
        'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
    ], [], [
        'duration_ms' => 30000, 'scenes' => [], 'configuration' => $configuration,
    ], ['timeout_seconds' => 30, 'lock_wait_seconds' => 35]);

    return $row;
}

/**
 * The M4 candidates as the M5 snapshot records them.
 *
 * @return list<array<string, mixed>>
 */
function recommendationM4Candidates(MediaClipAnalysis $m4): array
{
    return array_map(static fn (array $candidate): array => [
        'index' => (int) $candidate['index'],
        'start_ms' => (int) $candidate['start_ms'],
        'end_ms' => (int) $candidate['end_ms'],
        'rank' => (int) $candidate['rank'],
        'score' => $candidate['score'],
        'criteria' => $candidate['criteria'],
        'source_scene_indexes' => $candidate['source_scene_indexes'],
    ], $m4->candidates);
}

/**
 * A validated local unavailable outcome for the authority and reason.
 */
function recommendationLocalOutcome(
    MediaClipAnalysis $m4,
    string $reason,
    string $transcriptState = 'no_audio',
    int $durationMs = 40000,
): array {
    $configuration = ClipRankingProfile::configuration();
    $candidates = recommendationM4Candidates($m4);

    return MediaClipRecommendation::localCompletion(
        $configuration,
        (int) $m4->id,
        $candidates,
        $durationMs,
        $transcriptState,
        ClipRecommendationProjection::emptyTextHashes(
            array_map(static fn (array $candidate): int => $candidate['index'], $candidates)
        ),
        ClipRecommendationValidator::localUnavailableRecommendations($candidates, $reason),
        ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
    );
}

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Casts and relationships
|--------------------------------------------------------------------------
*/

it('recommendation model has correct casts and relationships', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $model = MediaClipRecommendation::find($recommendation->id);

    // Only the JSON documents and the primary key are cast; every other column
    // is a native string or integer and must not be silently reinterpreted.
    expect($model->getCasts())->toBe([
        'id' => 'int',
        'parameters' => 'array',
        'recommendations' => 'array',
        'input_snapshot' => 'array',
        'execution_parameters' => 'array',
    ]);
    expect($model->getCasts())->not->toHaveKey('status')
        ->and($model->getCasts())->not->toHaveKey('algorithm')
        ->and($model->getCasts())->not->toHaveKey('algorithm_version')
        ->and($model->getCasts())->not->toHaveKey('error')
        ->and($model->getCasts())->not->toHaveKey('m4_analysis_id');

    expect($model->mediaAsset()->exists())->toBeTrue();
    expect($model->mediaAsset->id)->toBe($asset->id);

    // The authoritative M4 analysis is reachable and belongs to the same asset.
    expect($model->m4Analysis()->exists())->toBeTrue();
    expect($model->m4Analysis->id)->toBe($m4->id);
    expect($model->m4Analysis->algorithm)->toBe('scene_timing_baseline');
    expect($model->m4Analysis->algorithm_version)->toBe('1.0.0');
});

it('recommendation model enforces unique media_asset_id', function () {
    $asset = MediaAsset::factory()->create();

    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $this->expectException(QueryException::class);
    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
});

/*
|--------------------------------------------------------------------------
| Lifecycle transitions
|--------------------------------------------------------------------------
*/

it('recommendation lifecycle transitions work correctly', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    // pending -> ranking
    $recommendation->markRanking();
    expect($recommendation->fresh()->status)->toBe(MediaClipRecommendation::STATUS_RANKING);

    // ranking -> completed, recorded only through the guarded boundary
    $configuration = ClipRankingProfile::configuration();
    $candidates = recommendationM4Candidates($m4);

    $recommendation->fresh()->markCompleted(MediaClipRecommendation::OUTCOME_RANKED, [
        'algorithm' => $configuration['algorithm'],
        'algorithm_version' => $configuration['algorithm_version'],
        'parameters' => ClipRankingProfile::parameters($configuration, false, false),
        'recommendations' => ClipRecommendationValidator::localUnavailableRecommendations(
            $candidates,
            'no_candidate_text'
        ),
        'input_snapshot' => [
            'm4_analysis_id' => (int) $m4->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => $candidates,
            'duration_ms' => 40000,
            'transcript_state' => 'no_candidate_text',
            'projection_version' => $configuration['projection_version'],
            'text_hashes' => ClipRecommendationProjection::emptyTextHashes(
                array_map(static fn (array $candidate): int => $candidate['index'], $candidates)
            ),
            'request_sha256' => null,
        ],
        'execution_parameters' => ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
    ]);

    $completed = $recommendation->fresh();
    expect($completed->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($completed->outcome)->toBe(MediaClipRecommendation::OUTCOME_RANKED);
    expect($completed->algorithm)->toBe('transcript_semantic_recommendation');
    expect($completed->algorithm_version)->toBe('1.0.0');
    expect($completed->recommendations)->toHaveCount(2);
    expect($completed->error)->toBeNull();

    // completed is terminal: every guarded and unguarded transition is refused
    // or leaves the row unchanged.
    expect(fn () => $completed->markCompleted(
        MediaClipRecommendation::OUTCOME_RANKED,
        recommendationLocalOutcome($m4, 'no_candidate_text')
    ))->toThrow(ProcessMediaException::class);

    expect(fn () => $completed->markUnavailable('no_audio', recommendationLocalOutcome($m4, 'no_audio')))
        ->toThrow(ProcessMediaException::class);

    $recommendation->update(['status' => MediaClipRecommendation::STATUS_RANKING]);
    expect($recommendation->fresh()->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
});

it('failed retry clears only M5 state and allows a new attempt', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);
    $m4Before = $m4->fresh()->getAttributes();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
    $recommendation->markRanking();
    $recommendation->markFailed(MediaClipRecommendation::ERROR_UPSTREAM_NOT_READY);
    // A failed attempt retains no result at all.
    $recommendation->update([
        'parameters' => ['stale' => true],
        'input_snapshot' => ['stale' => true],
        'execution_parameters' => ['stale' => true],
        'updated_at' => now()->subDay(),
    ]);
    $failedAt = $recommendation->fresh()->updated_at;

    // failed -> ranking (retry)
    $recommendation->fresh()->markRanking();
    $retried = $recommendation->fresh();

    expect($retried->status)->toBe(MediaClipRecommendation::STATUS_RANKING);
    expect($retried->error)->toBeNull();
    expect($retried->recommendations)->toBeNull();
    expect($retried->outcome)->toBeNull();
    expect($retried->reason)->toBeNull();

    // Only M5 state is cleared: the upstream M4 row is untouched, including
    // its persisted snapshot and timestamps.
    expect($m4->fresh()->getAttributes())->toBe($m4Before);
});

/*
|--------------------------------------------------------------------------
| Local unavailable outcomes
|--------------------------------------------------------------------------
*/

it('records a local unavailable outcome without claiming inference or a worker', function (string $reason, string $state) {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);
    $row = recommendationRow($asset, $m4);

    $completion = recommendationLocalOutcome($m4, $reason, $state);

    $row->markUnavailable($reason, $completion);
    $fresh = $row->fresh();

    $candidates = recommendationM4Candidates($m4);

    expect($fresh->status)->toBe(MediaClipRecommendation::STATUS_UNAVAILABLE);
    expect($fresh->outcome)->toBe(MediaClipRecommendation::OUTCOME_UNAVAILABLE);
    expect($fresh->reason)->toBe($reason);
    expect($fresh->error)->toBeNull();

    // K exact references, both semantic fields null, the outcome reason repeated.
    expect($fresh->recommendations)->toBe(ClipRecommendationValidator::localUnavailableRecommendations($candidates, $reason));
    expect($fresh->recommendations)->toHaveCount(count($candidates));
    foreach ($fresh->recommendations as $reference) {
        expect($reference['semantic_score'])->toBeNull();
        expect($reference['semantic_rank'])->toBeNull();
        expect($reference['reason'])->toBe($reason);
    }

    // Same keys and profile values as worker parameters, but no inference and
    // no transcript use, and no worker request digest.
    expect(array_keys($fresh->parameters))->toBe(ClipRankingProfile::parameterKeys());
    expect($fresh->parameters['inference_performed'])->toBeFalse();
    expect($fresh->parameters['transcript_used'])->toBeFalse();
    expect($fresh->parameters['provider_name'])->toBe('fake_ranking_provider');
    expect($fresh->input_snapshot['request_sha256'])->toBeNull();
    expect($fresh->input_snapshot['transcript_state'])->toBe($state);

    // Empty-string digests only: no candidate text is invented.
    $empty = ClipRecommendationProjection::digest('');
    foreach ($fresh->input_snapshot['text_hashes'] as $hash) {
        expect($hash['sha256'])->toBe($empty);
    }
})->with([
    'no_audio' => ['no_audio', 'no_audio'],
    'extraction_failed' => ['extraction_failed', 'extraction_failed'],
    'transcription_failed' => ['transcription_failed', 'transcription_failed'],
    'missing' => ['missing', 'missing'],
    'completed_empty' => ['completed_empty', 'completed_empty'],
    'no_candidate_text' => ['no_candidate_text', 'no_candidate_text'],
]);

it('records a local zero-candidate completion that claims no inference', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationEmptyM4Analysis($asset);
    $row = recommendationRow($asset, $m4);

    $configuration = ClipRankingProfile::configuration();

    $row->markCompleted(MediaClipRecommendation::OUTCOME_NO_CANDIDATES, [
        'algorithm' => $configuration['algorithm'],
        'algorithm_version' => $configuration['algorithm_version'],
        'parameters' => ClipRankingProfile::parameters($configuration, false, false),
        'recommendations' => ClipRecommendationValidator::noCandidateRecommendations(),
        'input_snapshot' => [
            'm4_analysis_id' => (int) $m4->id,
            'm4_algorithm' => 'scene_timing_baseline',
            'm4_algorithm_version' => '1.0.0',
            'm4_candidates' => [],
            'duration_ms' => 40000,
            'transcript_state' => 'completed_empty',
            'projection_version' => $configuration['projection_version'],
            'text_hashes' => [],
            'request_sha256' => null,
        ],
        'execution_parameters' => ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
    ]);

    $fresh = $row->fresh();

    expect($fresh->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($fresh->outcome)->toBe(MediaClipRecommendation::OUTCOME_NO_CANDIDATES);
    expect($fresh->recommendations)->toBe([]);
    expect($fresh->parameters['inference_performed'])->toBeFalse();
    expect($fresh->parameters['transcript_used'])->toBeFalse();
    expect($fresh->input_snapshot['request_sha256'])->toBeNull();
    expect($fresh->reason)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Terminal reuse and version conflicts
|--------------------------------------------------------------------------
*/

it('reuses a terminal row for an identical selection and ignores a timeout change', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);
    $row = recommendationRow($asset, $m4);
    $row->markUnavailable('no_audio', recommendationLocalOutcome($m4, 'no_audio'));
    $terminal = $row->fresh();
    $completedAt = $terminal->updated_at;

    $configuration = ClipRankingProfile::configuration();

    expect($terminal->isTerminal())->toBeTrue();
    expect($terminal->matchesSelection((int) $m4->id, $configuration))->toBeTrue();

    // An operational timeout change alone never invalidates a terminal result.
    config(['media.clip_ranking_timeout_seconds' => 120]);
    expect($terminal->matchesSelection((int) $m4->id, ClipRankingProfile::configuration()))->toBeTrue();

    // A refused transition leaves every recorded field and both timestamps
    // exactly as they were.
    $attributes = $terminal->getAttributes();
    expect(fn () => $terminal->markUnavailable('no_audio', recommendationLocalOutcome($m4, 'no_audio')))
        ->toThrow(ProcessMediaException::class);
    expect($terminal->fresh()->getAttributes())->toBe($attributes)
        ->and($terminal->fresh()->updated_at->equalTo($completedAt))->toBeTrue();
});

it('reports a changed semantic selection or M4 authority as a conflict', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);
    $row = recommendationRow($asset, $m4);
    $row->markUnavailable('no_audio', recommendationLocalOutcome($m4, 'no_audio'));
    $terminal = $row->fresh();

    $configuration = ClipRankingProfile::configuration();
    $attributes = $terminal->getAttributes();

    // Changed M4 authority.
    expect($terminal->matchesSelection((int) $m4->id + 1, $configuration))->toBeFalse();

    // Changed provider selection, and therefore a different model profile.
    config(['media.clip_ranking_provider' => 'cross_encoder']);
    expect($terminal->matchesSelection((int) $m4->id, ClipRankingProfile::configuration()))->toBeFalse();

    // Changed query, projection or scoring configuration of the same selection.
    foreach (['query_version' => '2.0.0', 'projection_version' => '2.0.0', 'normalization' => 'sigmoid'] as $key => $value) {
        $mutated = $configuration;
        $mutated[$key] = $value;
        expect($terminal->matchesSelection((int) $m4->id, $mutated))->toBeFalse();
    }

    // A conflict never mutates the terminal row.
    expect($terminal->fresh()->getAttributes())->toBe($attributes);
});

/*
|--------------------------------------------------------------------------
| Isolation and cascades
|--------------------------------------------------------------------------
*/

it('asset deletion cascades recommendation', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);

    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
    ]);

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeTrue();

    $asset->delete();

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeFalse();
});

it('deleting the authoritative M4 analysis cascades the bound recommendation', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);

    MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeTrue();

    $m4->delete();

    expect(MediaClipRecommendation::where('media_asset_id', $asset->id)->exists())->toBeFalse();
});

it('separate assets have isolated recommendations', function () {
    $asset1 = MediaAsset::factory()->create();
    $asset2 = MediaAsset::factory()->create();

    MediaClipRecommendation::create([
        'media_asset_id' => $asset1->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
    ]);
    MediaClipRecommendation::create([
        'media_asset_id' => $asset2->id,
        'status' => MediaClipRecommendation::STATUS_COMPLETED,
    ]);

    expect(MediaClipRecommendation::where('media_asset_id', $asset1->id)->count())->toBe(1);
    expect(MediaClipRecommendation::where('media_asset_id', $asset2->id)->count())->toBe(1);
});

it('pending fields have no completed result defaults', function () {
    $asset = MediaAsset::factory()->create();

    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);

    $fresh = $recommendation->fresh();
    expect($fresh->m4_analysis_id)->toBeNull();
    expect($fresh->outcome)->toBeNull();
    expect($fresh->reason)->toBeNull();
    expect($fresh->algorithm)->toBeNull();
    expect($fresh->algorithm_version)->toBeNull();
    expect($fresh->parameters)->toBeNull();
    expect($fresh->recommendations)->toBeNull();
    expect($fresh->input_snapshot)->toBeNull();
    expect($fresh->execution_parameters)->toBeNull();
    expect($fresh->error)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Fresh authority at the completion boundary
|--------------------------------------------------------------------------
*/

it('refuses to record a completion whose snapshot is not the fresh authority', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);
    $row = recommendationRow($asset, $m4);

    $completion = recommendationLocalOutcome($m4, 'no_audio');
    // Forge a non-empty text hash that the authoritative transcript cannot
    // produce, so an unscored candidate would look eligible.
    $completion['input_snapshot']['text_hashes'][0]['sha256'] = str_repeat('f', 64);
    $before = $row->getAttributes();

    expect(fn () => $row->markUnavailable('no_audio', $completion))
        ->toThrow(ProcessMediaException::class);

    expect($row->fresh()->getAttributes())->toBe($before)
        ->and($row->fresh()->recommendations)->toBeNull()
        ->and($row->fresh()->status)->toBe(MediaClipRecommendation::STATUS_RANKING);
});

it('refuses to record a completion bound to another M4 authority', function () {
    $asset = MediaAsset::factory()->create();
    $m4 = recommendationM4Analysis($asset);
    $other = recommendationM4Analysis(MediaAsset::factory()->create());
    $row = recommendationRow($asset, $m4);

    $completion = recommendationLocalOutcome($other, 'no_audio');
    $completion['input_snapshot']['m4_analysis_id'] = (int) $other->id;
    $before = $row->getAttributes();

    expect(fn () => $row->markUnavailable('no_audio', $completion))
        ->toThrow(ProcessMediaException::class);

    expect($row->fresh()->getAttributes())->toBe($before);
});
