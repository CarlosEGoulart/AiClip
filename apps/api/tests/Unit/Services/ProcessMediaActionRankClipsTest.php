<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationValidator;
use App\Services\ProcessMediaAction;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Support\CanonicalJson;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers — fixtures hand-derived from the specification
|--------------------------------------------------------------------------
*/

/**
 * The exact rank_clips request of the specification, for the forced fake
 * selection of this suite.
 */
function rankClipsRequestPayload(): array
{
    return [
        'version' => '1.0.0',
        'action' => 'rank_clips',
        'media' => ['duration_ms' => 40000],
        'candidates' => [
            [
                'index' => 0,
                'start_ms' => 0,
                'end_ms' => 10000,
                'm4_rank' => 1,
                'm4_score' => 1.0,
                'transcript_text' => 'first candidate window text',
            ],
            [
                'index' => 1,
                'start_ms' => 10000,
                'end_ms' => 20000,
                'm4_rank' => 2,
                'm4_score' => 0.5,
                'transcript_text' => '',
            ],
        ],
        'configuration' => ClipRankingProfile::configuration(),
    ];
}

function createRankClipsContract(array $overrides = []): MediaProcessingContract
{
    $data = array_replace_recursive(rankClipsRequestPayload(), $overrides);

    return MediaProcessingContract::fromArray($data);
}

/**
 * Build a rank_clips contract object directly, bypassing the eager
 * `fromArray()` preflight, so the pre-process `validate()` boundary itself
 * can be exercised.
 */
function unvalidatedRankClipsContract(array $overrides = []): MediaProcessingContract
{
    $data = array_replace_recursive(rankClipsRequestPayload(), $overrides);

    $contract = new MediaProcessingContract;
    $contract->action = 'rank_clips';
    $contract->durationMs = $data['media']['duration_ms'];
    $contract->candidates = $data['candidates'];
    $contract->configuration = $data['configuration'];

    return $contract;
}

/**
 * A contract-valid worker success for the exact request bytes.
 */
function rankClipsSuccessPayload(array $request): array
{
    $configuration = $request['configuration'];
    $parameters = $configuration;
    unset($parameters['algorithm'], $parameters['algorithm_version']);
    $parameters['provider_name'] = 'fake_ranking_provider';
    $parameters['inference_performed'] = false;
    $parameters['transcript_used'] = true;

    return [
        'status' => 'success',
        'ranking' => [
            'algorithm' => 'transcript_semantic_recommendation',
            'algorithm_version' => '1.0.0',
            'parameters' => $parameters,
            'request_sha256' => CanonicalJson::sha256($request),
            'recommendations' => [
                [
                    'm4_candidate_index' => 0,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'm4_rank' => 1,
                    'm4_score' => 1.0,
                    'semantic_score' => 0.9,
                    'semantic_rank' => 1,
                    'reason' => null,
                ],
                [
                    'm4_candidate_index' => 1,
                    'start_ms' => 10000,
                    'end_ms' => 20000,
                    'm4_rank' => 2,
                    'm4_score' => 0.5,
                    'semantic_score' => null,
                    'semantic_rank' => null,
                    'reason' => 'no_candidate_text',
                ],
            ],
        ],
    ];
}

function createMockableAction(array $commandOutputs): ProcessMediaAction
{
    return new class($commandOutputs) extends ProcessMediaAction
    {
        public array $outputSequence;

        public int $callIndex = 0;

        public function __construct(array $outputSequence)
        {
            $this->outputSequence = $outputSequence;
        }

        protected function createProcess(array $command): Process
        {
            $data = $this->outputSequence[$this->callIndex++] ?? $this->outputSequence[0];

            return new class($data) extends Process
            {
                public function __construct(private array $processData)
                {
                    parent::__construct(['echo', 'ok']);
                }

                public function setTimeout(?float $timeout): static
                {
                    return $this;
                }

                public function run(?callable $callback = null, array $env = []): int
                {
                    if ($callback !== null && isset($this->processData['chunks'])) {
                        foreach ($this->processData['chunks'] as $chunk) {
                            $callback($chunk['type'], $chunk['data']);
                        }
                    }

                    return $this->processData['exitCode'] ?? 0;
                }

                public function isSuccessful(): bool
                {
                    return ($this->processData['exitCode'] ?? 0) === 0;
                }

                public function getOutput(): string
                {
                    return $this->processData['stdout'] ?? '';
                }

                public function getErrorOutput(): string
                {
                    return $this->processData['stderr'] ?? '';
                }

                public function getExitCode(): ?int
                {
                    return $this->processData['exitCode'] ?? 0;
                }
            };
        }
    };
}

/*
|--------------------------------------------------------------------------
| Rank Clips Success
|--------------------------------------------------------------------------
*/

it('rank_clips invokes worker and returns result', function () {
    $request = rankClipsRequestPayload();
    $contract = createRankClipsContract();
    $response = rankClipsSuccessPayload($request);

    $action = createMockableAction([
        ['exitCode' => 0, 'stdout' => json_encode($response), 'stderr' => ''],
    ]);

    $result = $action->rankClips($contract);

    expect($result['status'])->toBe('success')
        ->and($result['ranking'])->toBeArray()
        ->and($result['ranking']['algorithm'])->toBe('transcript_semantic_recommendation')
        ->and($result['ranking']['request_sha256'])->toBe($response['ranking']['request_sha256'])
        ->and($result['ranking']['recommendations'])->toHaveCount(2);
});

it('rank_clips sends the exact request bytes and binds the response digest', function () {
    $request = rankClipsRequestPayload();
    $contract = createRankClipsContract();
    $response = rankClipsSuccessPayload($request);

    $process = new class($response) extends Process
    {
        public ?string $sentInput = null;

        public function __construct(private array $workerResponse)
        {
            parent::__construct(['echo', 'ok']);
        }

        public function setInput(mixed $input): static
        {
            $this->sentInput = is_string($input) ? $input : null;

            return $this;
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
            return (string) json_encode($this->workerResponse);
        }

        public function getExitCode(): ?int
        {
            return 0;
        }
    };

    $action = new class($process) extends ProcessMediaAction
    {
        public function __construct(private Process $process) {}

        protected function createProcess(array $command): Process
        {
            return $this->process;
        }
    };

    $action->rankClips($contract);

    // The digest Python echoes is the SHA256 of the exact stdin bytes, stdin
    // is the only transport for this transcript bearing action, and no asset,
    // project, storage or criteria metadata crosses the boundary.
    $decoded = json_decode((string) $process->sentInput, true);

    $canonicalRequest = CanonicalJson::encode($request);

    expect($process->sentInput)->toBe($canonicalRequest)
        ->and(hash('sha256', (string) $process->sentInput))->toBe($response['ranking']['request_sha256'])
        ->and(array_keys($decoded))->toBe(['action', 'candidates', 'configuration', 'media', 'version'])
        ->and(array_keys($decoded['media']))->toBe(['duration_ms'])
        ->and(array_keys($decoded['candidates'][0]))->toBe([
            'end_ms', 'index', 'm4_rank', 'm4_score', 'start_ms', 'transcript_text',
        ])
        ->and($decoded)->not->toHaveKey('media_asset_id')
        ->and($decoded)->not->toHaveKey('project_id')
        ->and($decoded)->not->toHaveKey('storage')
        ->and($decoded)->not->toHaveKey('scenes')
        ->and($decoded)->not->toHaveKey('transcript_segments');
});

/*
|--------------------------------------------------------------------------
| Rank Clips Failure
|--------------------------------------------------------------------------
*/

it('throws ProcessMediaException on worker failure', function () {
    $contract = createRankClipsContract();

    $action = createMockableAction([
        [
            'exitCode' => 1,
            'stdout' => json_encode([
                'status' => 'error',
                'code' => 'ranking_failed',
                'error' => 'Ranking failed',
                'stderr' => '',
            ]),
            'stderr' => '',
        ],
    ]);

    $this->expectException(ProcessMediaException::class);
    $action->rankClips($contract);
});

it('discards private stderr and worker diagnostics on failure', function () {
    $contract = createRankClipsContract();

    $action = createMockableAction([
        [
            'exitCode' => 1,
            'stdout' => json_encode([
                'status' => 'error',
                'code' => 'ranking_failed',
                'error' => 'Ranking failed',
                'stderr' => 'Detailed error output here',
            ]),
            'stderr' => 'Detailed error output here',
        ],
    ]);

    try {
        $action->rankClips($contract);
    } catch (ProcessMediaException $e) {
        expect($e->stderr)->toBe('')
            ->and($e->getMessage())->toBe('Ranking failed')
            ->and($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Expected ProcessMediaException was not thrown');
});

it('rejects oversized ranking output before decoding without leaking diagnostics', function () {
    // A contract-valid success plus legal JSON trailing whitespace: the size
    // bound, rather than a JSON or fixture error, must reject this.
    $response = rankClipsSuccessPayload(rankClipsRequestPayload());
    expect(ClipRecommendationValidator::result(
        $response,
        rankClipsRequestPayload(),
        $response['ranking']['request_sha256'],
    ))->toBe($response);

    $action = createMockableAction([[
        'exitCode' => 0,
        'stdout' => json_encode($response).str_repeat(' ', 1024 * 1024 + 1),
        'stderr' => 'PRIVATE_DIAGNOSTIC_SENTINEL',
    ]]);

    try {
        $action->rankClips(createRankClipsContract());
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Ranking failed')
            ->and($e->stderr)->toBe('')
            ->and($e->getPrevious())->toBeNull();

        return;
    }

    $this->fail('Oversized worker output must reject the entire attempt');
});

it('bounds streaming ranking output and terminates the owned child', function (string $stream) {
    $process = new class($stream) extends Process
    {
        public bool $stopped = false;

        public bool $drained = false;

        public function __construct(private string $stream)
        {
            parent::__construct(['recording-worker']);
        }

        public function run(?callable $callback = null, array $env = []): int
        {
            for ($i = 0; $i < 17; $i++) {
                if ($callback !== null) {
                    $callback($this->stream, str_repeat('x', 65536));
                }
            }
            $this->drained = true;

            return 1;
        }

        public function stop(float $timeout = 10, ?int $signal = null): ?int
        {
            $this->stopped = true;

            return 1;
        }

        public function isSuccessful(): bool
        {
            return false;
        }

        public function getOutput(): string
        {
            return '';
        }

        public function getErrorOutput(): string
        {
            return '';
        }

        public function getExitCode(): ?int
        {
            return 1;
        }
    };
    $action = new class($process) extends ProcessMediaAction
    {
        public function __construct(private Process $process) {}

        protected function createProcess(array $command): Process
        {
            return $this->process;
        }
    };

    expect(fn () => $action->rankClips(createRankClipsContract()))
        ->toThrow(ProcessMediaException::class, 'Ranking failed');
    expect($process->stopped)->toBeTrue();
    expect($process->drained)->toBeFalse();
})->with(['stdout' => [Process::OUT], 'stderr' => [Process::ERR]]);

it('sanitizes transport level failures without chaining sensitive exceptions', function (string $output, bool $throws, string $expected) {
    $action = new class($output, $throws ? new \RuntimeException('PRIVATE_SENTINEL') : null) extends ProcessMediaAction
    {
        public function __construct(private string $output, private ?\Throwable $failure) {}

        protected function createProcess(array $command): Process
        {
            return new class($this->output, $this->failure) extends Process
            {
                public function __construct(private string $output, private ?\Throwable $failure)
                {
                    parent::__construct(['recording-worker']);
                }

                public function run(?callable $callback = null, array $env = []): int
                {
                    if ($this->failure !== null) {
                        throw $this->failure;
                    }

                    return 0;
                }

                public function isSuccessful(): bool
                {
                    return true;
                }

                public function getOutput(): string
                {
                    return $this->output;
                }

                public function getExitCode(): ?int
                {
                    return 0;
                }
            };
        }
    };

    $failure = null;
    try {
        $action->rankClips(createRankClipsContract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe($expected)
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->stderr)->toBe('');
})->with([
    'malformed JSON' => ['PRIVATE_SENTINEL', false, 'Ranking validation failed'],
    'trailing output' => ['{"status":"success"} PRIVATE_SENTINEL', false, 'Ranking validation failed'],
    'nonfinite' => ['{"status":"success","ranking":{"score":NaN}}', false, 'Ranking validation failed'],
    'overflow' => ['{"status":"success","ranking":{"score":1e9999}}', false, 'Ranking validation failed'],
    'unexpected runtime abort' => ['', true, 'clip_ranking_aborted'],
]);

/*
|--------------------------------------------------------------------------
| Rank Clips Preflight Validation
|--------------------------------------------------------------------------
*/

it('throws ProcessMediaException when contract validation fails', function () {
    $contract = unvalidatedRankClipsContract(['media' => ['duration_ms' => 0]]);

    $action = createMockableAction([]);

    $this->expectException(ProcessMediaException::class);
    $this->expectExceptionMessage('Invalid ranking contract');
    $action->rankClips($contract);
});

it('does not call createProcess when contract is invalid', function () {
    $contract = unvalidatedRankClipsContract(['media' => ['duration_ms' => 0]]);

    $action = new class extends ProcessMediaAction
    {
        public bool $processCreated = false;

        protected function createProcess(array $command): Process
        {
            $this->processCreated = true;
            throw new \RuntimeException('createProcess must not be called for invalid contract');
        }
    };

    try {
        $action->rankClips($contract);
    } catch (ProcessMediaException) {
        // Expected
    }

    expect($action->processCreated)->toBeFalse();
});

it('does not call createProcess for a request without usable candidate text', function () {
    $contract = unvalidatedRankClipsContract([
        'candidates' => [
            ['transcript_text' => ''],
            ['index' => 1, 'transcript_text' => '   '],
        ],
    ]);

    $action = new class extends ProcessMediaAction
    {
        public bool $processCreated = false;

        protected function createProcess(array $command): Process
        {
            $this->processCreated = true;
            throw new \RuntimeException('createProcess must not be called for an unusable request');
        }
    };

    try {
        $action->rankClips($contract);
    } catch (ProcessMediaException) {
        // Expected
    }

    expect($action->processCreated)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Trusted Configuration Before Process Creation
|--------------------------------------------------------------------------
*/

it('uses configured clip_ranking_timeout_seconds', function () {
    config(['media.clip_ranking_timeout_seconds' => 45]);

    $contract = createRankClipsContract();
    $workerResponse = rankClipsSuccessPayload(rankClipsRequestPayload());

    $action = new class([$workerResponse]) extends ProcessMediaAction
    {
        public ?float $capturedTimeout = null;

        public function __construct(private array $workerResponses) {}

        protected function createProcess(array $command): Process
        {
            $action = $this;
            $workerResponse = $this->workerResponses[0];

            return new class($action, $workerResponse) extends Process
            {
                public function __construct(private object $action, private array $workerResponse)
                {
                    parent::__construct(['echo', 'ok']);
                }

                public function run(?callable $callback = null, array $env = []): int
                {
                    // Recorded at run() time, after rankClips applied the
                    // configured setTimeout.
                    $this->action->capturedTimeout = $this->getTimeout();

                    return 0;
                }

                public function isSuccessful(): bool
                {
                    return true;
                }

                public function getOutput(): string
                {
                    return (string) json_encode($this->workerResponse);
                }

                public function getErrorOutput(): string
                {
                    return '';
                }

                public function getExitCode(): ?int
                {
                    return 0;
                }
            };
        }
    };

    $action->rankClips($contract);

    expect($action->capturedTimeout)->toBe(45.0);
});

it('rejects invalid timeout configuration before creating a process', function (mixed $timeout) {
    config(['media.clip_ranking_timeout_seconds' => $timeout]);

    $action = new class extends ProcessMediaAction
    {
        public bool $processCreated = false;

        protected function createProcess(array $command): Process
        {
            $this->processCreated = true;
            throw new \RuntimeException('createProcess must not be called for invalid configuration');
        }
    };

    $failure = null;
    try {
        $action->rankClips(createRankClipsContract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe('invalid_configuration')
        ->and($failure->stderr)->toBe('')
        ->and($action->processCreated)->toBeFalse();
})->with([
    'zero' => [0],
    'negative' => [-1],
    'above maximum' => [200],
    'float' => [1.5],
    'garbage' => ['abc'],
]);

it('rejects an unset or unknown provider selection before creating a process', function (mixed $selection) {
    config(['media.clip_ranking_provider' => $selection]);

    $action = new class extends ProcessMediaAction
    {
        public bool $processCreated = false;

        protected function createProcess(array $command): Process
        {
            $this->processCreated = true;
            throw new \RuntimeException('createProcess must not be called for an unknown selection');
        }
    };

    $failure = null;
    try {
        $action->rankClips(createRankClipsContract());
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    $this->assertInstanceOf(ProcessMediaException::class, $failure);
    expect($failure->getMessage())->toBe('invalid_configuration')
        ->and($action->processCreated)->toBeFalse();
})->with([
    'unset' => [null],
    'unknown' => ['random'],
    'real provider identity instead of selector' => ['cross_encoder_ranking_provider'],
]);

/*
|--------------------------------------------------------------------------
| Legacy Actions Still Work (Controls)
|--------------------------------------------------------------------------
*/

it('probe action still works', function () {
    $contract = MediaProcessingContract::fromArray([
        'version' => '1.0.0',
        'media_asset_id' => 1,
        'project_id' => 1,
        'storage' => ['disk' => 'media', 'key' => 'test.mp4', 'mime_type' => 'video/mp4'],
        'idempotency_key' => '550e8400-e29b-41d4-a716-446655440000',
        'created_at' => '2026-09-16T10:00:00Z',
    ]);

    $action = createMockableAction([
        [
            'exitCode' => 0,
            'stdout' => json_encode([
                'status' => 'success',
                'probe' => ['duration_ms' => 120000, 'width' => 1920, 'height' => 1080],
            ]),
            'stderr' => '',
        ],
    ]);

    $result = $action->probe($contract);

    expect($result['status'])->toBe('success')
        ->and($result['probe']['duration_ms'])->toBe(120000);
});

it('analyze_clips action still works', function () {
    $contract = MediaProcessingContract::fromArray([
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 40000],
        'scenes' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000],
        ],
        'configuration' => [
            'min_duration_ms' => 5000,
            'target_duration_ms' => 10000,
            'max_duration_ms' => 20000,
            'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
        ],
    ]);

    $workerResponse = [
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'configuration' => $contract->configuration,
                'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
                'transcript_used' => false,
                'candidate_policy' => 'whole_scene_non_overlapping',
                'timing_policy' => 'original_media_ms',
                'transcript_policy' => 'optional_strict_unshifted',
                'boundary_policy' => 'strict_interior_speech_cut',
                'score_scale' => 1000000,
                'rounding' => 'half_up',
                'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
            ],
            'candidates' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 1, 'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0, 'boundary_alignment' => 0], 'source_scene_indexes' => [0]],
                ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 1, 'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0, 'boundary_alignment' => 0], 'source_scene_indexes' => [1]],
            ],
        ],
    ];

    $action = createMockableAction([
        [
            'exitCode' => 0,
            'stdout' => json_encode($workerResponse),
            'stderr' => '',
        ],
    ]);

    $result = $action->analyzeClips($contract);

    expect($result['status'])->toBe('success')
        ->and($result['analysis']['algorithm'])->toBe('scene_timing_baseline');
});
