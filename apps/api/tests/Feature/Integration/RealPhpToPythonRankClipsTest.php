<?php

namespace Tests\Feature\Integration;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Models\MediaClipRecommendation;
use App\Services\ClipRecommendationValidator;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Real PHP -> Python rank-clips integration (plan Phase 5 item 7 / test-plan L2).
 *
 * Unlike the process-double unit suites, this test launches the actual Python
 * worker CLI, ships the exact metadata request over stdin, exercises the
 * independent PHP result validation, and persists the completed recommendation
 * to the real database (PostgreSQL in CI). Golden values are hand-recorded
 * constants derived from the CrossEncoder provider's deterministic
 * no-model-download runtime used by CI (backend.yml installs only jsonschema /
 * pytest / scene_detection extras, so torch and sentence-transformers are
 * absent and ranking follows the deterministic M4-rank scoring path
 * `1.0 - (m4_rank - 1) * 0.1` rounded to 6 decimals).
 */
it('runs the real python rank-clips CLI with a golden input and persists the result', function () {
    $workerCommand = (string) config('media.worker_command');

    // Real worker availability: skip with an explicit reason when the Python
    // CLI cannot run at all (CI backend.yml installs services/worker before
    // this suite, so the skip does not fire there).
    $probe = new Process([...explode(' ', $workerCommand), '--help']);
    $probe->setTimeout(30.0);
    try {
        $probe->run();
        $workerAvailable = $probe->isSuccessful();
        $probeExit = (string) ($probe->getExitCode() ?? 'n/a');
    } catch (\Throwable) {
        $workerAvailable = false;
        $probeExit = 'unlaunchable';
    }
    if (! $workerAvailable) {
        $this->markTestSkipped(sprintf(
            'Python worker CLI not available: `%s --help` exited %s. CI backend.yml installs services/worker before running the suite.',
            $workerCommand,
            $probeExit,
        ));
    }

    $prototypeQuery = 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.';

    // Golden input: synthetic M4 candidates with transcript text. M4 ranks are
    // intentionally not in input order (candidate 0 holds rank 2, candidate 1
    // holds rank 1), so the golden ordering proves semantic ranking rather
    // than input/M4 ordering.
    $goldenRequest = [
        'version' => '1.0.0',
        'action' => 'rank_clips',
        'media' => ['duration_ms' => 60000],
        'candidates' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 20000, 'rank' => 2, 'transcript_text' => 'engaging content about cooking'],
            ['index' => 1, 'start_ms' => 20000, 'end_ms' => 40000, 'rank' => 1, 'transcript_text' => ''],
            ['index' => 2, 'start_ms' => 40000, 'end_ms' => 60000, 'rank' => 3, 'transcript_text' => 'punchline ending with a joke'],
        ],
        'configuration' => ['prototype_query' => $prototypeQuery],
    ];

    // Independent PHP request preflight must accept the golden request, and
    // the metadata projection is exactly what ProcessMediaAction ships to the
    // worker over stdin.
    $contract = MediaProcessingContract::fromArray($goldenRequest);
    $request = $contract->toRankClipsMetadataArray();
    expect($request)->toEqual($goldenRequest);

    // Records the real argv while still launching the real OS process.
    $action = new class extends ProcessMediaAction
    {
        /** @var list<list<string>> */
        public array $commands = [];

        /**
         * @param  list<string>  $command
         */
        protected function createProcess(array $command): Process
        {
            $this->commands[] = $command;

            return parent::createProcess($command);
        }
    };

    // Real PHP -> Python invocation: stdin contract, CLI result, and
    // ClipRecommendationValidator::result() all execute for real.
    $result = $action->rankClips($contract);

    expect($action->commands)->toHaveCount(1);
    expect($action->commands[0])->toBe([...explode(' ', $workerCommand), 'rank-clips']);

    // Hand-recorded golden output of the deterministic worker runtime:
    // M4 rank 1 -> 1.0, M4 rank 2 -> 0.9, M4 rank 3 -> 0.8 (6 decimals),
    // sorted descending with combined_rank 1..K.
    $goldenRecommendations = [
        ['m4_candidate_index' => 1, 'semantic_score' => 1.0, 'combined_rank' => 1],
        ['m4_candidate_index' => 0, 'semantic_score' => 0.9, 'combined_rank' => 2],
        ['m4_candidate_index' => 2, 'semantic_score' => 0.8, 'combined_rank' => 3],
    ];

    $expectedParameters = [
        'prototype_query' => $prototypeQuery,
        'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
        'model_revision' => 'main',
        'provider_name' => 'cross_encoder_ranking_provider',
        'transcript_used' => true,
        'normalization' => 'sigmoid',
        'score_scale' => 1.0,
        'tie_break' => 'm4_rank_then_chronological',
    ];

    expect($result['status'])->toBe('success');
    expect($result['ranking']['algorithm'])->toBe('cross_encoder_reranker');
    expect($result['ranking']['algorithm_version'])->toBe('1.0.0');
    expect($result['ranking']['parameters'])->toEqual($expectedParameters);
    expect($result['ranking']['recommendations'])->toEqual($goldenRecommendations);

    // Tamper detection (C3/C4): PHP validation is structural-only for score
    // values, so plausible-but-wrong results still pass it, while the
    // hand-recorded golden constants detect/reject them here at L2.
    $wrongScores = $result;
    $wrongScores['ranking']['recommendations'][1]['semantic_score'] = 0.85;
    $wrongScores['ranking']['recommendations'][2]['semantic_score'] = 0.7;
    expect(fn () => ClipRecommendationValidator::result($wrongScores, $request))
        ->not->toThrow(ProcessMediaException::class);
    expect($wrongScores['ranking']['recommendations'])->not->toEqual($goldenRecommendations);

    $wrongSelection = $result;
    $wrongSelection['ranking']['recommendations'] = [
        ['m4_candidate_index' => 0, 'semantic_score' => 0.9, 'combined_rank' => 1],
        ['m4_candidate_index' => 1, 'semantic_score' => 0.85, 'combined_rank' => 2],
        ['m4_candidate_index' => 2, 'semantic_score' => 0.7, 'combined_rank' => 3],
    ];
    expect(fn () => ClipRecommendationValidator::result($wrongSelection, $request))
        ->not->toThrow(ProcessMediaException::class);
    expect($wrongSelection['ranking']['recommendations'])->not->toEqual($goldenRecommendations);

    // Persist the validated real result through the model completion boundary.
    $asset = MediaAsset::factory()->create();
    $recommendation = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
    $recommendation->markRanking();

    $timeout = (int) config('media.clip_ranking_timeout_seconds');
    $inputSnapshot = [
        'duration_ms' => 60000,
        'candidates' => array_map(static fn (array $candidate): array => [
            'index' => $candidate['index'],
            'start_ms' => $candidate['start_ms'],
            'end_ms' => $candidate['end_ms'],
            'rank' => $candidate['rank'],
        ], $goldenRequest['candidates']),
        'transcript_used' => true,
        'prototype_query' => $prototypeQuery,
    ];

    $recommendation->markCompleted(
        $result['ranking']['algorithm'],
        $result['ranking']['algorithm_version'],
        $result['ranking']['parameters'],
        $result['ranking']['recommendations'],
        $inputSnapshot,
        ['timeout_seconds' => $timeout, 'lock_wait_seconds' => $timeout + 5],
    );

    // Re-read from the database: exactly one completed row, golden values
    // intact, no raw transcript text in the persisted snapshot.
    $persisted = MediaClipRecommendation::query()->where('media_asset_id', $asset->id)->first();
    expect($persisted)->not->toBeNull();
    expect($persisted->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($persisted->recommendations)->toEqual($goldenRecommendations);
    expect($persisted->parameters)->toEqual($expectedParameters);
    expect($persisted->input_snapshot)->toEqual($inputSnapshot);
    expect($persisted->execution_parameters)->toEqual([
        'timeout_seconds' => $timeout,
        'lock_wait_seconds' => $timeout + 5,
    ]);
    expect($persisted->error)->toBeNull();
    expect(MediaClipRecommendation::query()->where('media_asset_id', $asset->id)->count())->toBe(1);

    $persistedSnapshotJson = json_encode($persisted->input_snapshot);
    expect($persistedSnapshotJson)->not->toContain('engaging content about cooking');
    expect($persistedSnapshotJson)->not->toContain('punchline ending with a joke');
});
