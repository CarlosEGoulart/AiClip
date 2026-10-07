<?php

namespace Tests\Feature\Integration;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationProjection;
use App\Services\ClipRecommendationReadiness;
use App\Services\ClipRecommendationValidator;
use App\Services\ProcessMediaAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;
use Tests\Support\M5RecommendationFixture;
use Tests\Support\CanonicalJson;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Mandatory fake subprocess integration
|--------------------------------------------------------------------------
|
| test-plan.md "Mandatory fake subprocess integration": the real
| ProcessMediaAction launches the actual Python worker CLI over an
| argument-list command with the request on stdin only, the explicitly
| selected Python FakeRankingProvider answers, the independent PHP validator
| re-checks the result against the captured request, and the validated result
| crosses the model completion boundary into the database.
|
| No PHP fake, no process double and no real-adapter fallback can satisfy this
| boundary, and there is no skip: a missing CLI, a missing dependency or a
| missing database is a blocker to report, never a skipped test.
|
| Golden values are hand-derived from spec.md and never read back from
| production code: fake score units are
| `max(0, 1000000 - (m4_rank - 1) * 100000)`, the 13 configuration keys and
| their pinned fake values, the six candidate keys, the fixed query and the
| canonical text hashes are asserted against literal specification values.
|
*/

/**
 * The exact pinned fake profile configuration of spec.md "Versioned scoring
 * configuration" and "Strict worker protocol", written as a literal so the
 * request is compared against the specification and not against the code
 * that produces it.
 *
 * @return array<string, mixed>
 */
function issue64FakeConfiguration(): array
{
    return [
        'provider' => 'fake',
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'projection_version' => '1.0.0',
        'query_version' => '1.0.0',
        'prototype_query' => 'Engaging, self-contained short-form video clip highlight with a clear narrative or punchline.',
        'model_id' => 'fake-ranking-v1',
        'model_revision' => '1.0.0',
        'runtime_profile' => 'fake_v1',
        'normalization' => 'fixture_units_6',
        'max_tokens' => 0,
        'batch_size' => 0,
        'truncation' => 'none',
    ];
}

/**
 * The exact 14-key parameters object of a fake worker result: the request
 * configuration minus algorithm/algorithm_version, plus the profile identity
 * and the two truthful flags.
 *
 * @return array<string, mixed>
 */
function issue64FakeParameters(): array
{
    $configuration = issue64FakeConfiguration();
    unset($configuration['algorithm'], $configuration['algorithm_version']);

    return $configuration + [
        'provider_name' => 'fake_ranking_provider',
        'inference_performed' => false,
        'transcript_used' => true,
    ];
}

/**
 * Run the mandatory real boundary for a completed asset, an authoritative M4
 * analysis and the given transcript windows.
 *
 * Nothing here substitutes the subprocess: the recorded action only spies on
 * the argv and the exact stdin bytes while still launching the real worker
 * CLI, and the returned value is whatever the real CLI and the independent PHP
 * validator produced.
 *
 * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments
 * @return array{
 *     action: ProcessMediaAction,
 *     asset: MediaAsset,
 *     m4: MediaClipAnalysis,
 *     duration_ms: int,
 *     request: array<string, mixed>,
 *     result: array<string, mixed>,
 *     stdin: string,
 *     digest: string
 * }
 */
function issue64RunRealRankClips(array $segments): array
{
    // Mandatory tests and CI explicitly configure the fake profile; provider
    // selection is never inferred from the environment at run time.
    config(['media.clip_ranking_provider' => ClipRankingProfile::SELECTOR_FAKE]);

    $asset = M5RecommendationFixture::probedAsset();
    $m4 = M5RecommendationFixture::completedM4($asset);
    M5RecommendationFixture::transcript($asset, 'completed', $segments);

    $durationMs = (int) $asset->fresh()->duration_ms;

    $m4Candidates = array_map(static fn (array $candidate): array => [
        'index' => (int) $candidate['index'],
        'start_ms' => (int) $candidate['start_ms'],
        'end_ms' => (int) $candidate['end_ms'],
        'rank' => (int) $candidate['rank'],
        'score' => $candidate['score'],
    ], $m4->fresh()->candidates);

    $transcript = $asset->transcript()->first();
    expect($transcript)->not->toBeNull();

    $validatedSegments = ClipRecommendationProjection::validateSegments($transcript->segments, $durationMs);
    $projection = ClipRecommendationProjection::project(
        array_map(static fn (array $candidate): array => [
            'index' => $candidate['index'],
            'start_ms' => $candidate['start_ms'],
            'end_ms' => $candidate['end_ms'],
        ], $m4Candidates),
        $validatedSegments,
    );

    // The production request builder is the transport under test: it emits the
    // six candidate keys and the 13 configuration keys the packaged schema and
    // the Python action both require.
    $request = MediaProcessingContract::rankClipsRequest($durationMs, $m4Candidates, $projection['texts']);
    $contract = MediaProcessingContract::fromArray($request);

    // Records the real argv and the exact stdin bytes while still launching
    // the real OS process; no branch is taken on worker availability.
    $action = new class extends ProcessMediaAction
    {
        /** @var list<list<string>> */
        public array $commands = [];

        public ?Process $workerProcess = null;

        /**
         * @param  list<string>  $command
         */
        protected function createProcess(array $command): Process
        {
            $this->commands[] = $command;

            $this->workerProcess = new class($command) extends Process
            {
                public ?string $stdin = null;

                /**
                 * @param  list<string>  $command
                 */
                public function __construct(array $command)
                {
                    parent::__construct($command);
                }

                public function setInput(mixed $input): static
                {
                    if (is_string($input)) {
                        $this->stdin = $input;
                    }

                    return parent::setInput($input);
                }
            };

            return $this->workerProcess;
        }
    };

    $result = $action->rankClips($contract);

    expect($action->workerProcess)->not->toBeNull();
    expect($action->workerProcess->stdin)->not->toBeNull();

    $stdin = (string) $action->workerProcess->stdin;

    return [
        'action' => $action,
        'asset' => $asset,
        'm4' => $m4,
        'duration_ms' => $durationMs,
        'request' => $request,
        'result' => $result,
        'stdin' => $stdin,
        'digest' => hash('sha256', $stdin),
    ];
}

/**
 * Persist the validated worker result through the model completion boundary,
 * exactly as the job does, and return the re-read row.
 *
 * @param  array<string, mixed>  $result
 * @param  array<string, mixed>  $request
 * @param  list<array{index: int, sha256: string}>  $textHashes
 */
function issue64PersistResult(
    MediaAsset $asset,
    MediaClipAnalysis $m4,
    array $result,
    array $request,
    string $digest,
    array $textHashes,
): MediaClipRecommendation {
    $row = MediaClipRecommendation::create([
        'media_asset_id' => $asset->id,
        'm4_analysis_id' => $m4->id,
        'status' => MediaClipRecommendation::STATUS_PENDING,
    ]);
    $row->markRanking();

    $timeout = ClipRankingProfile::timeoutSeconds();

    $row->markCompleted(MediaClipRecommendation::OUTCOME_RANKED, [
        'algorithm' => $result['ranking']['algorithm'],
        'algorithm_version' => $result['ranking']['algorithm_version'],
        'parameters' => $result['ranking']['parameters'],
        'recommendations' => $result['ranking']['recommendations'],
        'input_snapshot' => [
            'm4_analysis_id' => $m4->id,
            'm4_algorithm' => ClipRecommendationValidator::M4_ALGORITHM,
            'm4_algorithm_version' => ClipRecommendationValidator::M4_ALGORITHM_VERSION,
            'm4_candidates' => $m4->fresh()->candidates,
            'duration_ms' => (int) $asset->fresh()->duration_ms,
            'transcript_state' => ClipRecommendationReadiness::COMPLETED_VALID,
            'projection_version' => $request['configuration']['projection_version'],
            'text_hashes' => $textHashes,
            'request_sha256' => $digest,
        ],
        'execution_parameters' => [
            'timeout_seconds' => $timeout,
            'lock_wait_seconds' => $timeout + ClipRankingProfile::LOCK_WAIT_OFFSET_SECONDS,
        ],
    ]);

    return MediaClipRecommendation::query()
        ->where('media_asset_id', $asset->id)
        ->firstOrFail();
}

it('runs the real python rank-clips CLI with the exact fake profile and persists the validated result', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    $scenario = issue64RunRealRankClips(M5RecommendationFixture::segments());

    $request = $scenario['request'];
    $result = $scenario['result'];

    // ------------------------------------------------------------------
    // Exact request: six candidate keys and the 13 configuration keys
    // ------------------------------------------------------------------
    expect(array_keys($request))->toBe(['version', 'action', 'media', 'candidates', 'configuration']);
    expect($request['version'])->toBe('1.0.0');
    expect($request['action'])->toBe('rank_clips');
    expect(array_keys($request['media']))->toBe(['duration_ms']);
    expect($request['media']['duration_ms'])->toBe($scenario['duration_ms']);

    expect(array_keys($request['configuration']))->toBe([
        'provider', 'algorithm', 'algorithm_version', 'projection_version', 'query_version',
        'prototype_query', 'model_id', 'model_revision', 'runtime_profile', 'normalization',
        'max_tokens', 'batch_size', 'truncation',
    ]);
    expect($request['configuration'])->toHaveCount(13);
    expect($request['configuration'])->toBe(issue64FakeConfiguration());

    expect($request['candidates'])->toHaveCount(2);
    foreach ($request['candidates'] as $candidate) {
        expect(array_keys($candidate))
            ->toBe(['index', 'start_ms', 'end_ms', 'm4_rank', 'm4_score', 'transcript_text'])
            ->and($candidate)->toHaveCount(6);
    }
    expect($request['candidates'][0])->toBe([
        'index' => 0,
        'start_ms' => 0,
        'end_ms' => 10000,
        'm4_rank' => 1,
        'm4_score' => 0.75,
        'transcript_text' => 'first window text',
    ]);
    expect($request['candidates'][1])->toBe([
        'index' => 1,
        'start_ms' => 10000,
        'end_ms' => 20000,
        'm4_rank' => 2,
        'm4_score' => 0.75,
        'transcript_text' => 'second window text',
    ]);

    // ------------------------------------------------------------------
    // Real argv, stdin-only transport, captured timeout
    // ------------------------------------------------------------------
    $workerCommand = (string) config('media.worker_command');
    expect($scenario['action']->commands)->toHaveCount(1);
    expect($scenario['action']->commands[0])->toBe([...explode(' ', $workerCommand), 'rank-clips']);
    expect(implode(' ', $scenario['action']->commands[0]))->not->toContain('first window text');
    expect($scenario['action']->workerProcess->getTimeout())
        ->toBe((float) ClipRankingProfile::timeoutSeconds());

    $stdin = $scenario['stdin'];
    $decodedStdin = json_decode($stdin, true, 512, JSON_THROW_ON_ERROR);
    expect($decodedStdin)->toEqual($request);

    // ------------------------------------------------------------------
    // Exact stdin digest bound to the returned ranking
    // ------------------------------------------------------------------
    expect($result['ranking']['request_sha256'])->toBe($scenario['digest']);
    expect($scenario['digest'])->toBe(CanonicalJson::sha256($request));

    // ------------------------------------------------------------------
    // Exact fake identity end to end, selector distinct from provider name
    // ------------------------------------------------------------------
    expect($result['status'])->toBe('success');
    expect(array_keys($result))->toBe(['status', 'ranking']);
    expect(array_keys($result['ranking']))
        ->toBe(['algorithm', 'algorithm_version', 'parameters', 'request_sha256', 'recommendations']);
    expect($result['ranking']['algorithm'])->toBe('transcript_semantic_recommendation');
    expect($result['ranking']['algorithm_version'])->toBe('1.0.0');

    $parameters = $result['ranking']['parameters'];
    // Parameter key order is not contractual (spec uses canonical JSON with sorted keys).
    // Verify semantic content: all expected keys present with correct values.
    expect($parameters)->toHaveCount(14);
    expect(array_keys($parameters))->toHaveCount(14);
    $expectedParams = issue64FakeParameters();
    foreach ($expectedParams as $key => $value) {
        expect($parameters)->toHaveKey($key);
        expect($parameters[$key])->toBe($value);
    }
    expect($parameters['provider'])->toBe('fake');
    expect($parameters['provider_name'])->toBe('fake_ranking_provider');
    expect($parameters['provider'])->not->toBe($parameters['provider_name']);
    expect($parameters['model_id'])->toBe('fake-ranking-v1');
    expect($parameters['model_revision'])->toBe('1.0.0');
    expect($parameters['runtime_profile'])->toBe('fake_v1');
    expect($parameters['normalization'])->toBe('fixture_units_6');
    expect($parameters['inference_performed'])->toBeFalse();
    expect($parameters['transcript_used'])->toBeTrue();

    // ------------------------------------------------------------------
    // Exact fake score units and reference order
    // ------------------------------------------------------------------
    // M4 rank 1 -> 1000000 units -> 1.0; M4 rank 2 -> 900000 units -> 0.9,
    // ordered by descending quantized units, so the reference order follows
    // the ranking rule and never the input order alone.
    expect($result['ranking']['recommendations'])->toBe([
        [
            'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000,
            'm4_rank' => 1, 'm4_score' => 0.75,
            'semantic_score' => 1.0, 'semantic_rank' => 1, 'reason' => null,
        ],
        [
            'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000,
            'm4_rank' => 2, 'm4_score' => 0.75,
            'semantic_score' => 0.9, 'semantic_rank' => 2, 'reason' => null,
        ],
    ]);
    expect((1000000.0 - (1 - 1) * 100000) / 1000000)->toBe(1.0);
    expect((1000000.0 - (2 - 1) * 100000) / 1000000)->toBe(0.9);

    // ------------------------------------------------------------------
    // No real-model identity anywhere in the worker output
    // ------------------------------------------------------------------
    $output = strtolower(json_encode($result, JSON_THROW_ON_ERROR));
    foreach ([
        'cross-encoder',
        'cross_encoder',
        'ms-marco',
        'minilm',
        'stable_sigmoid',
        'right_longest_first_512',
        '233902d25c440f23af6f7d6e94d2946bac0bee0a',
    ] as $forbidden) {
        expect($output)->not->toContain($forbidden);
    }

    // ------------------------------------------------------------------
    // Independent PHP validation really runs on this exact result
    // ------------------------------------------------------------------
    expect(ClipRecommendationValidator::result($result, $request, $scenario['digest']))->toBe($result);

    $tamperedDigest = $result;
    $tamperedDigest['ranking']['request_sha256'] = str_repeat('0', 64);
    expect(fn () => ClipRecommendationValidator::result($tamperedDigest, $request, $scenario['digest']))
        ->toThrow(ProcessMediaException::class, 'Ranking validation failed');

    $misleadingInference = $result;
    $misleadingInference['ranking']['parameters']['inference_performed'] = true;
    expect(fn () => ClipRecommendationValidator::result($misleadingInference, $request, $scenario['digest']))
        ->toThrow(ProcessMediaException::class, 'Ranking validation failed');

    $realModelIdentity = $result;
    $realModelIdentity['ranking']['parameters']['model_id'] = 'cross-encoder/ms-marco-MiniLM-L6-v2';
    expect(fn () => ClipRecommendationValidator::result($realModelIdentity, $request, $scenario['digest']))
        ->toThrow(ProcessMediaException::class, 'Ranking validation failed');

    $mutatedReference = $result;
    $mutatedReference['ranking']['recommendations'][1]['start_ms'] = 12000;
    expect(fn () => ClipRecommendationValidator::result($mutatedReference, $request, $scenario['digest']))
        ->toThrow(ProcessMediaException::class, 'Ranking validation failed');

    // The documented trust boundary: a structurally valid, in-range, correctly
    // ordered alternative score is accepted by Laravel, and only the golden
    // constants above detect it. Laravel does not claim to catch every
    // compromised in-range fabrication.
    $plausibleScore = $result;
    $plausibleScore['ranking']['recommendations'][1]['semantic_score'] = 0.85;
    expect(fn () => ClipRecommendationValidator::result($plausibleScore, $request, $scenario['digest']))
        ->not->toThrow(ProcessMediaException::class);
    expect($plausibleScore['ranking']['recommendations'])
        ->not->toBe($result['ranking']['recommendations']);

    // Rejected results stay sanitized: fixed category, no chained cause, no
    // stderr and no raw transcript or prompt in the exception itself.
    try {
        ClipRecommendationValidator::result($realModelIdentity, $request, $scenario['digest']);
        $this->fail('The tampered identity must be rejected');
    } catch (ProcessMediaException $exception) {
        expect($exception->getMessage())->toBe('Ranking validation failed')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->stderr)->toBe('');

        $exceptionText = $exception->getMessage().' '.$exception->stderr.' '.$exception->getTraceAsString();
        expect($exceptionText)->not->toContain('first window text')
            ->and($exceptionText)->not->toContain('second window text')
            ->and($exceptionText)->not->toContain(issue64FakeConfiguration()['prototype_query']);
    }

    // ------------------------------------------------------------------
    // Canonical text hashes and request digest in the durable snapshot
    // ------------------------------------------------------------------
    $textHashes = [
        ['index' => 0, 'sha256' => hash('sha256', 'first window text')],
        ['index' => 1, 'sha256' => hash('sha256', 'second window text')],
    ];

    $persisted = issue64PersistResult(
        $scenario['asset'],
        $scenario['m4'],
        $result,
        $request,
        $scenario['digest'],
        $textHashes,
    );

    expect($persisted->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($persisted->outcome)->toBe(MediaClipRecommendation::OUTCOME_RANKED);
    expect($persisted->reason)->toBeNull();
    expect($persisted->error)->toBeNull();
    expect($persisted->m4_analysis_id)->toBe($scenario['m4']->id);
    expect($persisted->algorithm)->toBe('transcript_semantic_recommendation');
    expect($persisted->algorithm_version)->toBe('1.0.0');
    // Parameter key order is not contractual (spec uses canonical JSON with sorted keys).
    // Verify semantic content: all expected keys present with correct values.
    $expectedParams = issue64FakeParameters();
    expect($persisted->parameters)->toHaveCount(count($expectedParams));
    foreach ($expectedParams as $key => $value) {
        expect($persisted->parameters)->toHaveKey($key);
        expect($persisted->parameters[$key])->toBe($value);
    }
    // Semantic comparison: check structure and numeric values with tolerance
    // for JSONB integer conversion (PHP may cast float 1.0 to int 1).
    $expected = $result['ranking']['recommendations'];
    $actual = $persisted->recommendations;
    expect($actual)->toHaveCount(count($expected));
    foreach ($expected as $i => $expRec) {
        $actualRec = $actual[$i] ?? null;
        expect($actualRec)->not->toBeNull();
        expect($actualRec['m4_candidate_index'])->toBe($expRec['m4_candidate_index']);
        expect($actualRec['start_ms'])->toBe($expRec['start_ms']);
        expect($actualRec['end_ms'])->toBe($expRec['end_ms']);
        expect($actualRec['m4_rank'])->toBe($expRec['m4_rank']);
        expect($actualRec['m4_score'])->toBe($expRec['m4_score']);
        // Semantic score: compare with tolerance for JSONB integer->float cast
        // PHP JSONB may cast float 1.0 to int 1, so we use close comparison.
        expect((float) $actualRec['semantic_score'])->toBeGreaterThanOrEqual((float) $expRec['semantic_score'] - 0.001);
        expect((float) $actualRec['semantic_score'])->toBeLessThanOrEqual((float) $expRec['semantic_score'] + 0.001);
        // Semantic rank with tolerance
        expect((int) $actualRec['semantic_rank'])->toBe((int) $expRec['semantic_rank']);
        expect($actualRec['reason'])->toBe($expRec['reason']);
    }

    expect(array_keys($persisted->input_snapshot))->toBe([
        'm4_analysis_id', 'm4_algorithm', 'm4_algorithm_version', 'm4_candidates',
        'duration_ms', 'transcript_state', 'projection_version', 'text_hashes', 'request_sha256',
    ]);
    expect($persisted->input_snapshot['text_hashes'])->toBe($textHashes);
    expect($persisted->input_snapshot['request_sha256'])->toBe($scenario['digest']);
    expect($persisted->input_snapshot['transcript_state'])->toBe(ClipRecommendationReadiness::COMPLETED_VALID);
    expect($persisted->input_snapshot['m4_candidates'])->toEqual($scenario['m4']->fresh()->candidates);

    $timeout = ClipRankingProfile::timeoutSeconds();
    expect($persisted->execution_parameters)->toBe([
        'timeout_seconds' => $timeout,
        'lock_wait_seconds' => $timeout + ClipRankingProfile::LOCK_WAIT_OFFSET_SECONDS,
    ]);
    expect(MediaClipRecommendation::query()->where('media_asset_id', $scenario['asset']->id)->count())->toBe(1);

    // ------------------------------------------------------------------
    // Privacy: no raw transcript in persistence or logs
    // ------------------------------------------------------------------
    $persistedJson = json_encode($persisted->getAttributes(), JSON_THROW_ON_ERROR);
    expect($persistedJson)->not->toContain('first window text')
        ->and($persistedJson)->not->toContain('second window text');

    $logText = implode("\n", $logged);
    expect($logText)->not->toContain('first window text')
        ->and($logText)->not->toContain('second window text')
        ->and($logText)->not->toContain(issue64FakeConfiguration()['prototype_query']);
});

it('keeps a mixed candidate set at K entries with the unscored candidate null and reason no_candidate_text', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context, JSON_THROW_ON_ERROR);
    });

    // Only the first window overlaps a candidate; the second segment overlaps
    // no candidate window at all, so candidate 1 carries no usable text.
    $scenario = issue64RunRealRankClips([
        ['start_ms' => 500, 'end_ms' => 9500, 'text' => 'first window text'],
        ['start_ms' => 25000, 'end_ms' => 30000, 'text' => 'unrelated window text'],
    ]);

    $request = $scenario['request'];
    $result = $scenario['result'];

    expect($request['candidates'])->toHaveCount(2);
    expect($request['candidates'][0]['transcript_text'])->toBe('first window text');
    expect($request['candidates'][1]['transcript_text'])->toBe('');

    expect($result['status'])->toBe('success');
    expect($result['ranking']['request_sha256'])->toBe($scenario['digest']);
    // Parameter key order is not contractual (spec uses canonical JSON with sorted keys).
    // Verify semantic content: all expected keys present with correct values.
    $expectedParams = issue64FakeParameters();
    expect($result['ranking']['parameters'])->toHaveCount(count($expectedParams));
    foreach ($expectedParams as $key => $value) {
        expect($result['ranking']['parameters'])->toHaveKey($key);
        expect($result['ranking']['parameters'][$key])->toBe($value);
    }

    // K entries, never fewer: one scored reference first, then the unscored
    // reference with both semantic fields null and the fixed reason.
    expect($result['ranking']['recommendations'])->toBe([
        [
            'm4_candidate_index' => 0, 'start_ms' => 0, 'end_ms' => 10000,
            'm4_rank' => 1, 'm4_score' => 0.75,
            'semantic_score' => 1.0, 'semantic_rank' => 1, 'reason' => null,
        ],
        [
            'm4_candidate_index' => 1, 'start_ms' => 10000, 'end_ms' => 20000,
            'm4_rank' => 2, 'm4_score' => 0.75,
            'semantic_score' => null, 'semantic_rank' => null,
            'reason' => 'no_candidate_text',
        ],
    ]);

    $textHashes = [
        ['index' => 0, 'sha256' => hash('sha256', 'first window text')],
        ['index' => 1, 'sha256' => hash('sha256', '')],
    ];

    $persisted = issue64PersistResult(
        $scenario['asset'],
        $scenario['m4'],
        $result,
        $request,
        $scenario['digest'],
        $textHashes,
    );

    expect($persisted->status)->toBe(MediaClipRecommendation::STATUS_COMPLETED);
    expect($persisted->outcome)->toBe(MediaClipRecommendation::OUTCOME_RANKED);
    expect($persisted->recommendations)->toHaveCount(2);
    expect($persisted->recommendations[1]['semantic_score'])->toBeNull();
    expect($persisted->recommendations[1]['semantic_rank'])->toBeNull();
    expect($persisted->recommendations[1]['reason'])->toBe('no_candidate_text');
    expect($persisted->input_snapshot['text_hashes'])->toBe($textHashes);
    expect($persisted->input_snapshot['request_sha256'])->toBe($scenario['digest']);
    expect($persisted->parameters['inference_performed'])->toBeFalse();

    $persistedJson = json_encode($persisted->getAttributes(), JSON_THROW_ON_ERROR);
    expect($persistedJson)->not->toContain('first window text')
        ->and($persistedJson)->not->toContain('unrelated window text');

    $logText = implode("\n", $logged);
    expect($logText)->not->toContain('first window text')
        ->and($logText)->not->toContain('unrelated window text')
        ->and($logText)->not->toContain(issue64FakeConfiguration()['prototype_query']);
});

it('ships a packaged rank_clips schema that pins the same 13 configuration keys and 6 candidate keys', function () {
    // The three-way agreement check: the PHP request builder, the packaged
    // media_processing_v1.json schema the Python action validates against, and
    // the Python action's own request key set must name the same keys in the
    // same order. The subprocess tests above prove the runtime half of this
    // agreement, because a divergent key set is rejected as invalid_contract.
    $schemaPath = base_path('../../services/worker/contracts/media_processing_v1.json');
    expect(is_file($schemaPath))->toBeTrue();

    $schema = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
    $definition = $schema['definitions']['rank_clips_request'];

    expect($definition['additionalProperties'])->toBeFalse();
    expect($definition['required'])->toBe(['version', 'action', 'media', 'candidates', 'configuration']);
    expect($definition['properties']['version']['const'])->toBe('1.0.0');
    expect($definition['properties']['action']['const'])->toBe('rank_clips');

    $configuration = $definition['properties']['configuration'];
    expect($configuration['additionalProperties'])->toBeFalse();
    expect($configuration['required'])->toBe(ClipRankingProfile::CONFIGURATION_KEYS);
    expect(array_keys($configuration['properties']))->toBe(ClipRankingProfile::CONFIGURATION_KEYS);
    expect($configuration['required'])->toHaveCount(13);
    expect($configuration['properties']['algorithm']['const'])->toBe('transcript_semantic_recommendation');
    expect($configuration['properties']['prototype_query']['const'])
        ->toBe(issue64FakeConfiguration()['prototype_query']);

    $candidates = $definition['properties']['candidates'];
    // candidates is an array: additionalProperties closure lives on items.
    expect($candidates['type'])->toBe('array')
        ->and($candidates['minItems'])->toBe(1)
        ->and($candidates['maxItems'])->toBe(1000);
    expect($candidates['items']['additionalProperties'])->toBeFalse();
    expect($candidates['items']['required'])
        ->toBe(['index', 'start_ms', 'end_ms', 'm4_rank', 'm4_score', 'transcript_text'])
        ->and($candidates['items']['required'])->toHaveCount(6);

    // The PHP builder emits exactly those two key sets.
    config(['media.clip_ranking_provider' => ClipRankingProfile::SELECTOR_FAKE]);
    $request = MediaProcessingContract::rankClipsRequest(40000, [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 0.75],
    ], ['first window text']);

    expect(array_keys($request['configuration']))->toBe($configuration['required']);
    expect(array_keys($request['candidates'][0]))->toBe($candidates['items']['required']);
});
