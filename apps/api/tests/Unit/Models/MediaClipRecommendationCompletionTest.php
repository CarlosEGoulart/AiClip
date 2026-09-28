<?php

namespace Tests\Unit\Models;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaClipRecommendation;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationValidator;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers — the durable completion payload, hand-derived from the spec
|--------------------------------------------------------------------------
*/

const COMPLETION_QUERY = 'Engaging, self-contained short-form video clip highlight with a clear narrative or punchline.';

function completionConfiguration(string $provider = 'fake'): array
{
    $profile = $provider === 'cross_encoder'
        ? [
            'model_id' => 'cross-encoder/ms-marco-MiniLM-L6-v2',
            'model_revision' => '233902d25c440f23af6f7d6e94d2946bac0bee0a',
            'runtime_profile' => 'minilm_cpu_v1',
            'normalization' => 'stable_sigmoid_half_up_6',
            'max_tokens' => 512,
            'batch_size' => 8,
            'truncation' => 'right_longest_first_512',
        ]
        : [
            'model_id' => 'fake-ranking-v1',
            'model_revision' => '1.0.0',
            'runtime_profile' => 'fake_v1',
            'normalization' => 'fixture_units_6',
            'max_tokens' => 0,
            'batch_size' => 0,
            'truncation' => 'none',
        ];

    return [
        'provider' => $provider,
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'projection_version' => '1.0.0',
        'query_version' => '1.0.0',
        'prototype_query' => COMPLETION_QUERY,
    ] + $profile;
}

function completionParameters(string $provider = 'fake'): array
{
    $parameters = completionConfiguration($provider);
    unset($parameters['algorithm'], $parameters['algorithm_version']);
    $parameters['provider_name'] = $provider === 'cross_encoder'
        ? 'cross_encoder_ranking_provider'
        : 'fake_ranking_provider';
    $parameters['inference_performed'] = $provider === 'cross_encoder';
    $parameters['transcript_used'] = true;

    return $parameters;
}

function m4Candidate(int $index, int $startMs, int $endMs, int $rank, float $score): array
{
    return [
        'index' => $index,
        'start_ms' => $startMs,
        'end_ms' => $endMs,
        'rank' => $rank,
        'score' => $score,
        'criteria' => ['duration_fit' => 1.0, 'speech_coverage' => 0.0, 'boundary_alignment' => 0.0],
        'source_scene_indexes' => [$index],
    ];
}

function completionSnapshot(array $overrides = []): array
{
    return array_replace([
        'm4_analysis_id' => 7,
        'm4_algorithm' => 'scene_timing_baseline',
        'm4_algorithm_version' => '1.0.0',
        'm4_candidates' => [m4Candidate(0, 0, 10000, 1, 1.0), m4Candidate(1, 10000, 20000, 2, 0.5)],
        'duration_ms' => 40000,
        'transcript_state' => 'completed_valid',
        'projection_version' => '1.0.0',
        'text_hashes' => [
            ['index' => 0, 'sha256' => hash('sha256', 'first candidate window text')],
            ['index' => 1, 'sha256' => hash('sha256', '')],
        ],
        'request_sha256' => str_repeat('a', 64),
    ], $overrides);
}

function workerCompletion(array $overrides = []): array
{
    return array_replace([
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'parameters' => completionParameters(),
        'recommendations' => [
            [
                'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                'm4_score' => 1.0, 'semantic_score' => 0.880797, 'semantic_rank' => 1, 'reason' => null,
            ],
            [
                'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
                'reason' => 'no_candidate_text',
            ],
        ],
        'input_snapshot' => completionSnapshot(),
        'execution_parameters' => ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
    ], $overrides);
}

/**
 * A ranking row whose fresh authority is supplied through the documented
 * completion seam, so the boundary is exercised without any database.
 *
 * The production implementation re-reads the completed M4 row and re-projects
 * the authoritative transcript; the double returns exactly those freshly
 * derived values, which is what makes a forged snapshot observable here.
 */
class DoubleCheckedRecommendation extends MediaClipRecommendation
{
    public static ?array $authority = null;

    public static int $authorityReads = 0;

    /** @var list<string> */
    public static array $authorityStates = [];

    protected function freshAuthority(string $recordedState): ?array
    {
        self::$authorityReads++;
        self::$authorityStates[] = $recordedState;

        return self::$authority;
    }
}

function authoritativeState(): array
{
    return [
        'm4_candidates' => [m4Candidate(0, 0, 10000, 1, 1.0), m4Candidate(1, 10000, 20000, 2, 0.5)],
        'text_hashes' => [
            ['index' => 0, 'sha256' => hash('sha256', 'first candidate window text')],
            ['index' => 1, 'sha256' => hash('sha256', '')],
        ],
    ];
}

/**
 * An unsaved ranking row bound to the fresh authority above. No database is
 * touched: the completion boundary validates before any write, so rejection is
 * observable without persistence.
 */
function rankingRow(string $status = MediaClipRecommendation::STATUS_RANKING): DoubleCheckedRecommendation
{
    DoubleCheckedRecommendation::$authority = authoritativeState();
    DoubleCheckedRecommendation::$authorityReads = 0;
    DoubleCheckedRecommendation::$authorityStates = [];

    $row = new DoubleCheckedRecommendation;
    $row->status = $status;
    $row->m4_analysis_id = 7;

    return $row;
}

/**
 * A ranking row with no fresh authority at all: the boundary must refuse to
 * bind any snapshot to an authority it cannot re-derive.
 */
function unauthoritativeRow(string $status = MediaClipRecommendation::STATUS_RANKING): MediaClipRecommendation
{
    DoubleCheckedRecommendation::$authority = null;

    $row = new DoubleCheckedRecommendation;
    $row->status = $status;
    $row->m4_analysis_id = 7;

    return $row;
}

function assertCompletionRejected(string $mutation, array $completion, string $status = MediaClipRecommendation::STATUS_RANKING): void
{
    $row = rankingRow($status);
    $before = $row->getAttributes();

    $failure = null;
    try {
        $row->markCompleted(MediaClipRecommendation::OUTCOME_RANKED, $completion);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))
        ->toBe(ProcessMediaException::class, "Completion boundary must reject the {$mutation} mutation the service validator rejects (no bypass).");

    // No partial result and no partial status write on rejection.
    expect($row->getAttributes())->toBe($before)
        ->and($row->recommendations)->toBeNull()
        ->and($row->input_snapshot)->toBeNull()
        ->and($row->outcome)->toBeNull()
        ->and($row->error)->toBeNull();
}

/*
|--------------------------------------------------------------------------
| Shared invariant core at the model boundary
|--------------------------------------------------------------------------
*/

it('publishes the five statuses and the terminal set of the specification', function () {
    expect(MediaClipRecommendation::VALID_STATUSES)->toBe([
        'pending', 'ranking', 'completed', 'unavailable', 'failed',
    ])
        ->and(MediaClipRecommendation::TERMINAL_STATUSES)->toBe(['completed', 'unavailable'])
        ->and(MediaClipRecommendation::OUTCOME_RANKED)->toBe('ranked')
        ->and(MediaClipRecommendation::OUTCOME_NO_CANDIDATES)->toBe('no_candidates')
        ->and(MediaClipRecommendation::ERROR_VERSION_CONFLICT)->toBe('recommendation_version_conflict');
});

it('keeps completed and unavailable rows terminal', function () {
    foreach (['completed', 'unavailable'] as $status) {
        expect(rankingRow($status)->isTerminal())->toBeTrue();
    }

    foreach (['pending', 'ranking', 'failed'] as $status) {
        expect(rankingRow($status)->isTerminal())->toBeFalse();
    }
});

it('rejects any completion of an already terminal row', function (string $status) {
    $row = rankingRow($status);
    $before = $row->getAttributes();

    $failure = null;
    try {
        $row->markCompleted(MediaClipRecommendation::OUTCOME_RANKED, workerCompletion());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class)
        ->and($row->getAttributes())->toBe($before);
})->with(['completed', 'unavailable']);

it('rejects the mutations the shared validator rejects, with no bypass', function (string $mutation, array $completion) {
    assertCompletionRejected($mutation, $completion);
})->with(function () {
    $mutations = [
        'equal scores with M4 ranks descending' => ['ranking.order', [
            'recommendations' => [
                [
                    'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.5, 'semantic_score' => 0.5, 'semantic_rank' => 1, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => 0.5, 'semantic_rank' => 2, 'reason' => null,
                ],
            ],
        ]],
        'duplicate recommendation index' => ['ranking.duplicate', [
            'recommendations' => [
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => 0.5, 'semantic_rank' => 1, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => 0.4, 'semantic_rank' => 2, 'reason' => null,
                ],
            ],
        ]],
        'altered M4 score' => ['ranking.m4_score', [
            'recommendations' => [
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 0.25, 'semantic_score' => 0.5, 'semantic_rank' => 1, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
                    'reason' => 'no_candidate_text',
                ],
            ],
        ]],
        'altered candidate boundary' => ['ranking.boundary', [
            'recommendations' => [
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 9999, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => 0.5, 'semantic_rank' => 1, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
                    'reason' => 'no_candidate_text',
                ],
            ],
        ]],
        'numeric-string semantic_score' => ['ranking.score_type', [
            'recommendations' => [
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => '0.5', 'semantic_rank' => 1, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
                    'reason' => 'no_candidate_text',
                ],
            ],
        ]],
        'score exceeding 6 decimals' => ['ranking.precision', [
            'recommendations' => [
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => 0.8807971, 'semantic_rank' => 1, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
                    'reason' => 'no_candidate_text',
                ],
            ],
        ]],
        'semantic rank permutation out of position order' => ['ranking.rank', [
            'recommendations' => [
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => 0.880797, 'semantic_rank' => 2, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.5, 'semantic_score' => null, 'semantic_rank' => null,
                    'reason' => 'no_candidate_text',
                ],
            ],
        ]],
        'K mismatch' => ['ranking.count', [
            'recommendations' => [[
                'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                'm4_score' => 1.0, 'semantic_score' => 0.880797, 'semantic_rank' => 1, 'reason' => null,
            ]],
        ]],
        'misleading provider identity' => ['provenance.provider_name', [
            'parameters' => array_replace(completionParameters(), ['provider_name' => 'cross_encoder_ranking_provider']),
        ]],
        'misleading inference flag' => ['provenance.inference', [
            'parameters' => array_replace(completionParameters(), ['inference_performed' => true]),
        ]],
        'mutated model revision' => ['provenance.model_revision', [
            'parameters' => array_replace(completionParameters(), ['model_revision' => 'main']),
        ]],
        'raw text in the snapshot' => ['snapshot.text', [
            'input_snapshot' => completionSnapshot(['transcript_text' => 'PRIVATE_SENTINEL']),
        ]],
        'forged eligibility hash' => ['snapshot.hashes', [
            'input_snapshot' => completionSnapshot(['text_hashes' => [
                ['index' => 0, 'sha256' => hash('sha256', 'first candidate window text')],
                ['index' => 1, 'sha256' => hash('sha256', '')],
            ]]),
            'recommendations' => [
                [
                    'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
                    'm4_score' => 1.0, 'semantic_score' => 0.880797, 'semantic_rank' => 1, 'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
                    'm4_score' => 0.5, 'semantic_score' => 0.5, 'semantic_rank' => 2, 'reason' => null,
                ],
            ],
        ]],
        'mismatched M4 authority' => ['snapshot.m4', [
            'input_snapshot' => completionSnapshot(['m4_analysis_id' => 8]),
        ]],
        'mutated M4 algorithm' => ['snapshot.m4_algorithm', [
            'input_snapshot' => completionSnapshot(['m4_algorithm' => 'other_algorithm']),
        ]],
        'unknown transcript state' => ['snapshot.state', [
            'input_snapshot' => completionSnapshot(['transcript_state' => 'completed_but_whatever']),
        ]],
        'digest not lowercase hex' => ['snapshot.digest', [
            'input_snapshot' => completionSnapshot(['request_sha256' => 'PRIVATE_SENTINEL']),
        ]],
        'invalid lock wait' => ['execution.lock_wait', [
            'execution_parameters' => ['timeout_seconds' => 60, 'lock_wait_seconds' => 66],
        ]],
        'extra completion key' => ['payload.extra', [
            'raw_logits' => [0.1, 0.2],
        ]],
    ];

    foreach ($mutations as $name => [$label, $overrides]) {
        yield $name => [$label, workerCompletion($overrides)];
    }
});

it('rejects a completion that is not in a legal status transition', function (string $status) {
    $row = rankingRow($status);
    $before = $row->getAttributes();

    expect(fn () => $row->markCompleted(MediaClipRecommendation::OUTCOME_RANKED, workerCompletion()))
        ->toThrow(ProcessMediaException::class)
        ->and($row->getAttributes())->toBe($before);
})->with(['pending', 'failed']);

/*
|--------------------------------------------------------------------------
| Local outcome payloads
|--------------------------------------------------------------------------
*/

it('builds a local zero-candidate completion that claims no inference', function () {
    $completion = MediaClipRecommendation::localCompletion(
        ClipRankingProfile::configuration(),
        7,
        [],
        40000,
        'completed_empty',
        [],
        [],
        ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
    );

    expect($completion['input_snapshot'])->toBe([
        'm4_analysis_id' => 7,
        'm4_algorithm' => 'scene_timing_baseline',
        'm4_algorithm_version' => '1.0.0',
        'm4_candidates' => [],
        'duration_ms' => 40000,
        'transcript_state' => 'completed_empty',
        'projection_version' => '1.0.0',
        'text_hashes' => [],
        'request_sha256' => null,
    ])
        ->and($completion['parameters']['inference_performed'])->toBeFalse()
        ->and($completion['parameters']['transcript_used'])->toBeFalse()
        ->and($completion['recommendations'])->toBe([]);
});

it('rejects a local completion that still claims a worker digest', function () {
    $completion = MediaClipRecommendation::localCompletion(
        ClipRankingProfile::configuration(),
        7,
        [m4Candidate(0, 0, 10000, 1, 1.0)],
        40000,
        'no_candidate_text',
        [['index' => 0, 'sha256' => hash('sha256', '')]],
        [[
            'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'm4_rank' => 1,
            'm4_score' => 1.0, 'semantic_score' => null, 'semantic_rank' => null,
            'reason' => 'no_candidate_text',
        ]],
        ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
    );

    $completion['input_snapshot']['request_sha256'] = str_repeat('b', 64);

    $row = rankingRow();
    $before = $row->getAttributes();

    expect(fn () => $row->markUnavailable('no_candidate_text', $completion))
        ->toThrow(ProcessMediaException::class)
        ->and($row->getAttributes())->toBe($before);
});

/*
|--------------------------------------------------------------------------
| Terminal reuse and version conflicts
|--------------------------------------------------------------------------
*/

it('reuses a terminal row for an identical selection and ignores a timeout change', function () {
    config(['media.clip_ranking_provider' => 'fake']);
    $configuration = ClipRankingProfile::configuration();

    $row = rankingRow(MediaClipRecommendation::STATUS_COMPLETED);
    $row->algorithm = $configuration['algorithm'];
    $row->algorithm_version = $configuration['algorithm_version'];
    $row->parameters = ClipRankingProfile::parameters($configuration, false, true);

    expect($row->matchesSelection(7, $configuration))->toBeTrue();

    // An operational timeout change alone never invalidates a terminal result.
    config(['media.clip_ranking_timeout_seconds' => 120]);
    expect($row->matchesSelection(7, ClipRankingProfile::configuration()))->toBeTrue();
});

it('reports a changed semantic selection or M4 authority as a conflict', function () {
    config(['media.clip_ranking_provider' => 'fake']);
    $configuration = ClipRankingProfile::configuration();

    $row = rankingRow(MediaClipRecommendation::STATUS_COMPLETED);
    $row->algorithm = $configuration['algorithm'];
    $row->algorithm_version = $configuration['algorithm_version'];
    $row->parameters = ClipRankingProfile::parameters($configuration, false, true);

    // Different M4 authority.
    expect($row->matchesSelection(8, $configuration))->toBeFalse();

    // Different provider selection, and therefore a different model profile.
    config(['media.clip_ranking_provider' => 'cross_encoder']);
    expect($row->matchesSelection(7, ClipRankingProfile::configuration()))->toBeFalse();

    // Different query or projection version of the same selection.
    config(['media.clip_ranking_provider' => 'fake']);
    $mutated = $configuration;
    $mutated['query_version'] = '2.0.0';
    expect($row->matchesSelection(7, $mutated))->toBeFalse();

    $mutated = $configuration;
    $mutated['projection_version'] = '2.0.0';
    expect($row->matchesSelection(7, $mutated))->toBeFalse();

    $mutated = $configuration;
    $mutated['normalization'] = 'sigmoid';
    expect($row->matchesSelection(7, $mutated))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Fresh authority re-derivation at the completion boundary
|--------------------------------------------------------------------------
*/

it('rejects a forged non-empty text hash that the fresh transcript does not produce', function () {
    // Candidate 1 has no usable text in the authoritative transcript, so its
    // recorded digest must be SHA256(''). A caller that forges a non-empty
    // digest to make the candidate look eligible must be refused.
    $forged = workerCompletion();
    $forged['input_snapshot']['text_hashes'][1]['sha256'] = str_repeat('c', 64);
    $forged['recommendations'][1] = [
        'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'm4_rank' => 2,
        'm4_score' => 0.5, 'semantic_score' => 0.7, 'semantic_rank' => 2, 'reason' => null,
    ];

    // Control: the structural service boundary cannot see the fresh transcript,
    // so the forged payload is structurally valid there. The model boundary is
    // strictly stronger, which is exactly what this test proves.
    expect(fn () => ClipRecommendationValidator::validateCompletion($forged))
        ->not->toThrow(ProcessMediaException::class);

    assertCompletionRejected('snapshot.forged_text_hash', $forged);
});

it('rejects a completion whose text hashes are re-ordered rather than re-derived', function () {
    $forged = workerCompletion();
    $forged['input_snapshot']['text_hashes'] = [
        ['index' => 1, 'sha256' => hash('sha256', '')],
        ['index' => 0, 'sha256' => hash('sha256', 'first candidate window text')],
    ];

    assertCompletionRejected('snapshot.reordered_text_hashes', $forged);
});

it('rejects a completion whose M4 candidate list is not the fresh authority', function () {
    $forged = workerCompletion();
    $forged['input_snapshot']['m4_candidates'][1]['score'] = 0.75;
    $forged['recommendations'][1]['m4_score'] = 0.75;

    assertCompletionRejected('snapshot.forged_m4_candidate', $forged);
});

it('refuses to bind any snapshot when no fresh authority can be re-derived', function () {
    foreach ([MediaClipRecommendation::OUTCOME_RANKED, MediaClipRecommendation::OUTCOME_NO_CANDIDATES] as $ignored) {
        $completion = workerCompletion();
        $row = unauthoritativeRow();
        $before = $row->getAttributes();

        expect(fn () => $row->markCompleted($ignored, $completion))
            ->toThrow(ProcessMediaException::class)
            ->and($row->getAttributes())->toBe($before)
            ->and($row->recommendations)->toBeNull()
            ->and($row->input_snapshot)->toBeNull();
    }
});

it('reads the fresh authority before validating and before any write', function () {
    $row = rankingRow();
    $before = $row->getAttributes();

    expect(DoubleCheckedRecommendation::$authorityReads)->toBe(0);

    try {
        $row->markCompleted(MediaClipRecommendation::OUTCOME_RANKED, workerCompletion());
    } catch (ProcessMediaException) {
        // A successful validation of an unsaved row cannot write, so the
        // authority read is still the only observable side effect.
    }

    expect(DoubleCheckedRecommendation::$authorityReads)->toBe(1)
        ->and($row->getAttributes())->toBe($before);
});
