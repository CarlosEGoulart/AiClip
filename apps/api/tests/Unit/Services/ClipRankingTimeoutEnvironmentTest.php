<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Services\ClipRankingProfile;
use App\Services\ProcessMediaAction;
use Illuminate\Support\Arr;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Support\CanonicalJson;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Environment-level Timeout Configuration Tests
|--------------------------------------------------------------------------
|
| These tests exercise the actual config/media.php loading with isolated
| MEDIA_CLIP_RANKING_TIMEOUT_SECONDS environment variable manipulation.
| They test the full chain: env -> config/media.php -> ClipRankingProfile ->
| ProcessMediaAction::rankClips with Process double observing timeout at run().
|
*/

const ENV_KEY = 'MEDIA_CLIP_RANKING_TIMEOUT_SECONDS';

/**
 * Build a minimal valid rank_clips contract for action tests.
 */
function minimalRankClipsContract(): MediaProcessingContract
{
    return MediaProcessingContract::fromArray([
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
                'transcript_text' => 'second candidate window text',
            ],
        ],
        'configuration' => ClipRankingProfile::configuration('fake'),
    ]);
}

/**
 * A contract-valid worker success response for the fake profile.
 * Both candidates have non-empty transcript_text, so both get scored.
 */
function fakeRankClipsSuccessPayload(array $request): array
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
                    'semantic_score' => 0.8,
                    'semantic_rank' => 2,
                    'reason' => null,
                ],
            ],
        ],
    ];
}

/**
 * Simple mutable holder for capturing values from closures.
 */
final class CapturedValue
{
    public mixed $value = null;
}

/**
 * Create a ProcessMediaAction with a recording Process double that captures
 * the timeout applied at run() time.
 */
function createActionWithTimeoutCapture(array $workerResponse): array
{
    $holder = new CapturedValue;

    $action = new class($workerResponse, $holder) extends ProcessMediaAction
    {
        public function __construct(
            private array $workerResponse,
            private CapturedValue $holder
        ) {}

        protected function createProcess(array $command): Process
        {
            $workerResponse = $this->workerResponse;
            $holder = $this->holder;

            return new class($workerResponse, $holder) extends Process
            {
                public function __construct(
                    private array $workerResponse,
                    private CapturedValue $holder
                ) {
                    parent::__construct(['echo', 'ok']);
                }

                public function run(?callable $callback = null, array $env = []): int
                {
                    // Record timeout at run() time, after rankClips applied setTimeout
                    $this->holder->value = $this->getTimeout();

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

    return [$action, $holder];
}

/**
 * Create a ProcessMediaAction that tracks createProcess invocations.
 */
function createActionWithProcessTracking(): array
{
    $holder = new CapturedValue;
    $holder->value = false;

    $action = new class($holder) extends ProcessMediaAction
    {
        public function __construct(private CapturedValue $holder) {}

        protected function createProcess(array $command): Process
        {
            $this->holder->value = true;

            return new class extends Process
            {
                public function __construct()
                {
                    parent::__construct(['echo', 'ok']);
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
                    return '{}';
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

    return [$action, $holder];
}

/**
 * Isolate MEDIA_CLIP_RANKING_TIMEOUT_SECONDS for a single test.
 * Snapshots ONLY this env key independently in getenv, $_ENV, $_SERVER.
 * Snapshots original tested config presence/value for clip_ranking_timeout_seconds.
 * Requires config/media.php directly and assigns only the loaded timeout into Laravel config.
 * Restores EXACT independent stores and original config presence/value on success AND throw.
 */
function withIsolatedTimeoutEnv(?string $value, callable $test): void
{
    // Snapshot the env key independently in each store
    $originalGetenv = getenv(ENV_KEY);
    $originalHasEnv = array_key_exists(ENV_KEY, $_ENV);
    $originalEnvValue = $originalHasEnv ? $_ENV[ENV_KEY] : null;
    $originalHasServer = array_key_exists(ENV_KEY, $_SERVER);
    $originalServerValue = $originalHasServer ? $_SERVER[ENV_KEY] : null;

    // Snapshot original config presence/value for the specific key
    $originalConfigHas = config()->offsetExists('media.clip_ranking_timeout_seconds');
    $originalConfigValue = $originalConfigHas ? config('media.clip_ranking_timeout_seconds') : null;

    try {
        if ($value === null) {
            // Unset the env key in all stores
            putenv(ENV_KEY);
            unset($_ENV[ENV_KEY]);
            unset($_SERVER[ENV_KEY]);
        } else {
            // Set the env key in all stores
            $_ENV[ENV_KEY] = $value;
            $_SERVER[ENV_KEY] = $value;
            putenv(ENV_KEY.'='.$value);
        }

        // Require config/media.php directly and assign ONLY the loaded timeout into Laravel config
        $mediaConfig = require __DIR__.'/../../../config/media.php';
        config(['media.clip_ranking_timeout_seconds' => $mediaConfig['clip_ranking_timeout_seconds']]);

        $test();
    } finally {
        // Restore EXACT independent stores
        if ($originalGetenv === false) {
            putenv(ENV_KEY);
        } else {
            putenv(ENV_KEY.'='.$originalGetenv);
        }

        if ($originalHasEnv) {
            $_ENV[ENV_KEY] = $originalEnvValue;
        } else {
            unset($_ENV[ENV_KEY]);
        }

        if ($originalHasServer) {
            $_SERVER[ENV_KEY] = $originalServerValue;
        } else {
            unset($_SERVER[ENV_KEY]);
        }

        // Restore original config presence/value for the specific key
        if ($originalConfigHas) {
            config(['media.clip_ranking_timeout_seconds' => $originalConfigValue]);
        } else {
            // Properly remove the nested key (not set null) to restore true absence
            // Get current media config, remove the timeout key, and set media config back
            // This preserves other media config keys while removing the timeout key
            $mediaConfig = config('media', []);
            if (array_key_exists('clip_ranking_timeout_seconds', $mediaConfig)) {
                unset($mediaConfig['clip_ranking_timeout_seconds']);
                config(['media' => $mediaConfig]);
            }
        }
    }
}

/**
 * Isolate MEDIA_CLIP_RANKING_TIMEOUT_SECONDS and reload config/media.php
 * through the actual config file path.
 */
function withRealConfigLoad(?string $value, callable $test): void
{
    withIsolatedTimeoutEnv($value, function () use ($test) {
        $test();
    });
}

it('absent env publishes raw string 60 and profile validates to int 60 with lock wait 65', function () {
    withRealConfigLoad(null, function () {
        // config/media.php loads the raw default string '60'
        expect(config('media.clip_ranking_timeout_seconds'))->toBe('60')
            ->and(is_string(config('media.clip_ranking_timeout_seconds')))->toBeTrue();

        // Profile validates and converts to int
        expect(ClipRankingProfile::timeoutSeconds())->toBe(60)
            ->and(ClipRankingProfile::lockWaitSeconds())->toBe(65);
    });
});

it('explicit env 45 publishes raw string 45 and profile validates to int 45 with lock wait 50', function () {
    withRealConfigLoad('45', function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe('45')
            ->and(is_string(config('media.clip_ranking_timeout_seconds')))->toBeTrue();

        expect(ClipRankingProfile::timeoutSeconds())->toBe(45)
            ->and(ClipRankingProfile::lockWaitSeconds())->toBe(50);
    });
});

it('explicit env 1.5 publishes raw string 1.5 and profile rejects with invalid_configuration', function () {
    withRealConfigLoad('1.5', function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe('1.5')
            ->and(is_string(config('media.clip_ranking_timeout_seconds')))->toBeTrue();

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($failure->stderr)->toBe('')
            ->and($failure->getPrevious())->toBeNull();
    });
});

it('explicit env 1.5 with valid contract: action throws invalid_configuration, zero createProcess, empty stderr, no chained cause', function () {
    withRealConfigLoad('1.5', function () {
        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($failure->stderr)->toBe('')
            ->and($failure->getPrevious())->toBeNull()
            ->and($holder->value)->toBeFalse();
    });
});

it('explicit env 045 (leading zero) publishes raw string 045 and profile rejects', function () {
    withRealConfigLoad('045', function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe('045');

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit env 45 with LF publishes raw string 45\n and profile rejects', function () {
    withRealConfigLoad("45\n", function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe("45\n");

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit env 45 with CR publishes raw string 45\r and profile rejects', function () {
    withRealConfigLoad("45\r", function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe("45\r");

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit env 45 with CRLF publishes raw string 45\r\n and profile rejects', function () {
    withRealConfigLoad("45\r\n", function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe("45\r\n");

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit env 45 with leading space publishes raw string " 45" and profile rejects', function () {
    withRealConfigLoad(' 45', function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe(' 45');

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit env 45 with trailing space publishes raw string "45 " and profile rejects', function () {
    withRealConfigLoad('45 ', function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe('45 ');

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit env 45 with tab publishes raw string "\t45" and profile rejects', function () {
    withRealConfigLoad("\t45", function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe("\t45");

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit empty string publishes raw empty string and profile rejects', function () {
    // Note: PHP env('KEY') returns null when env var is not set, but returns
    // empty string '' when env var is set to empty. This test simulates
    // an explicitly set empty value in the environment.
    withRealConfigLoad('', function () {
        // env('KEY', '60') returns '' when KEY is set to empty string
        expect(config('media.clip_ranking_timeout_seconds'))->toBe('');

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit env "null" string publishes raw null (Laravel env() normalizes "null" string) and profile rejects', function () {
    withRealConfigLoad('null', function () {
        // Laravel env() returns null for string "null" (case-insensitive)
        expect(config('media.clip_ranking_timeout_seconds'))->toBeNull();

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit null config value (explicit config null) publishes null and profile rejects with invalid_configuration', function () {
    withRealConfigLoad('45', function () {
        // First establish a valid config, then override to explicit null
        config(['media.clip_ranking_timeout_seconds' => null]);

        $failure = null;
        try {
            ClipRankingProfile::timeoutSeconds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration');
    });
});

it('explicit null config value: action throws invalid_configuration before createProcess with valid contract', function () {
    withRealConfigLoad('45', function () {
        config(['media.clip_ranking_timeout_seconds' => null]);

        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($failure->stderr)->toBe('')
            ->and($failure->getPrevious())->toBeNull()
            ->and($holder->value)->toBeFalse();
    });
});

it('absent env: action process double observes timeout 60 at run()', function () {
    withRealConfigLoad(null, function () {
        $contract = minimalRankClipsContract();
        $workerResponse = fakeRankClipsSuccessPayload([
            'version' => '1.0.0',
            'action' => 'rank_clips',
            'media' => ['duration_ms' => 40000],
            'candidates' => $contract->candidates,
            'configuration' => $contract->configuration,
        ]);

        [$action, $holder] = createActionWithTimeoutCapture($workerResponse);

        $action->rankClips($contract);

        expect($holder->value)->toBe(60.0);
    });
});

it('explicit env 45: action process double observes timeout 45 at run()', function () {
    withRealConfigLoad('45', function () {
        $contract = minimalRankClipsContract();
        $workerResponse = fakeRankClipsSuccessPayload([
            'version' => '1.0.0',
            'action' => 'rank_clips',
            'media' => ['duration_ms' => 40000],
            'candidates' => $contract->candidates,
            'configuration' => $contract->configuration,
        ]);

        [$action, $holder] = createActionWithTimeoutCapture($workerResponse);

        $action->rankClips($contract);

        expect($holder->value)->toBe(45.0);
    });
});

it('explicit env 1.5: action throws invalid_configuration before createProcess with valid contract', function () {
    withRealConfigLoad('1.5', function () {
        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($failure->stderr)->toBe('')
            ->and($failure->getPrevious())->toBeNull()
            ->and($holder->value)->toBeFalse();
    });
});

it('explicit env leading zero 045: action throws invalid_configuration before createProcess', function () {
    withRealConfigLoad('045', function () {
        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($holder->value)->toBeFalse();
    });
});

it('explicit env with trailing LF 45\n: action throws invalid_configuration before createProcess', function () {
    withRealConfigLoad("45\n", function () {
        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($holder->value)->toBeFalse();
    });
});

it('explicit env with trailing CR 45\r: action throws invalid_configuration before createProcess', function () {
    withRealConfigLoad("45\r", function () {
        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($holder->value)->toBeFalse();
    });
});

it('explicit env with trailing CRLF 45\r\n: action throws invalid_configuration before createProcess', function () {
    withRealConfigLoad("45\r\n", function () {
        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ProcessMediaException::class)
            ->and($failure->getMessage())->toBe('invalid_configuration')
            ->and($holder->value)->toBeFalse();
    });
});

/*
|--------------------------------------------------------------------------
| Helper cleanup verification tests (synthetic values only)
|--------------------------------------------------------------------------
|
| These tests verify that withIsolatedTimeoutEnv correctly restores
| the exact original state in all four stores (getenv, $_ENV, $_SERVER, config)
| for various preexisting conditions, including when the callback throws.
|
| Each test snapshots the PRE-TEST ambient state in its own outer try/finally
| to prevent cross-test environment leakage.
|
*/

/**
 * Restore the timeout key's pre-test ambient state, including on failure.
 */
function withAmbientEnvironmentRestored(callable $test): void
{
    // Snapshot pre-test ambient state
    $ambientGetenv = getenv(ENV_KEY);
    $ambientHasEnv = array_key_exists(ENV_KEY, $_ENV);
    $ambientEnvValue = $ambientHasEnv ? $_ENV[ENV_KEY] : null;
    $ambientHasServer = array_key_exists(ENV_KEY, $_SERVER);
    $ambientServerValue = $ambientHasServer ? $_SERVER[ENV_KEY] : null;
    $ambientConfigHas = config()->offsetExists('media.clip_ranking_timeout_seconds');
    $ambientConfigValue = $ambientConfigHas ? config('media.clip_ranking_timeout_seconds') : null;

    try {
        $test();
    } finally {
        // Restore pre-test ambient state exactly
        if ($ambientGetenv === false) {
            putenv(ENV_KEY);
        } else {
            putenv(ENV_KEY.'='.$ambientGetenv);
        }

        if ($ambientHasEnv) {
            $_ENV[ENV_KEY] = $ambientEnvValue;
        } else {
            unset($_ENV[ENV_KEY]);
        }

        if ($ambientHasServer) {
            $_SERVER[ENV_KEY] = $ambientServerValue;
        } else {
            unset($_SERVER[ENV_KEY]);
        }

        if ($ambientConfigHas) {
            config(['media.clip_ranking_timeout_seconds' => $ambientConfigValue]);
        } else {
            $mediaConfig = config('media', []);
            Arr::forget($mediaConfig, 'clip_ranking_timeout_seconds');
            config(['media' => $mediaConfig]);
        }
    }
}

it('helper restores preexisting empty string in all stores and config', function () {
    withAmbientEnvironmentRestored(function () {
        // Arrange: set up preexisting empty string state
        $_ENV[ENV_KEY] = '';
        $_SERVER[ENV_KEY] = '';
        putenv(ENV_KEY.'=');
        config(['media.clip_ranking_timeout_seconds' => '']);

        $snapshots = [];

        // Act: run with isolated null (unset), then capture restored state
        withIsolatedTimeoutEnv(null, function () use (&$snapshots) {
            // Inside isolation: should see absent env
            $snapshots['inside_getenv'] = getenv(ENV_KEY);
            $snapshots['inside_env'] = $_ENV[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_server'] = $_SERVER[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_config'] = config('media.clip_ranking_timeout_seconds');
        });

        // Assert: after isolation, original empty string restored everywhere
        expect($snapshots['inside_getenv'])->toBeFalse()
            ->and($snapshots['inside_env'])->toBe('NOT_SET')
            ->and($snapshots['inside_server'])->toBe('NOT_SET')
            ->and($snapshots['inside_config'])->toBe('60'); // default from config file

        // Outside: original empty string restored
        expect(getenv(ENV_KEY))->toBe('')
            ->and($_ENV[ENV_KEY])->toBe('')
            ->and($_SERVER[ENV_KEY])->toBe('')
            ->and(config('media.clip_ranking_timeout_seconds'))->toBe('');
    });
});

it('helper restores preexisting "false" text in all stores and config', function () {
    withAmbientEnvironmentRestored(function () {
        // Arrange: set up preexisting "false" text state
        $_ENV[ENV_KEY] = 'false';
        $_SERVER[ENV_KEY] = 'false';
        putenv(ENV_KEY.'=false');
        config(['media.clip_ranking_timeout_seconds' => 'false']);

        $snapshots = [];

        // Act: run with isolated null (unset), then capture restored state
        withIsolatedTimeoutEnv(null, function () use (&$snapshots) {
            $snapshots['inside_getenv'] = getenv(ENV_KEY);
            $snapshots['inside_env'] = $_ENV[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_server'] = $_SERVER[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_config'] = config('media.clip_ranking_timeout_seconds');
        });

        // Assert: after isolation, original "false" text restored everywhere
        expect(getenv(ENV_KEY))->toBe('false')
            ->and($_ENV[ENV_KEY])->toBe('false')
            ->and($_SERVER[ENV_KEY])->toBe('false')
            ->and(config('media.clip_ranking_timeout_seconds'))->toBe('false');
    });
});

it('helper restores preexisting "null" text in all stores and config', function () {
    withAmbientEnvironmentRestored(function () {
        // Arrange: set up preexisting "null" text state
        $_ENV[ENV_KEY] = 'null';
        $_SERVER[ENV_KEY] = 'null';
        putenv(ENV_KEY.'=null');
        config(['media.clip_ranking_timeout_seconds' => 'null']);

        $snapshots = [];

        // Act: run with isolated null (unset), then capture restored state
        withIsolatedTimeoutEnv(null, function () use (&$snapshots) {
            $snapshots['inside_getenv'] = getenv(ENV_KEY);
            $snapshots['inside_env'] = $_ENV[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_server'] = $_SERVER[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_config'] = config('media.clip_ranking_timeout_seconds');
        });

        // Assert: after isolation, original "null" text restored everywhere
        expect(getenv(ENV_KEY))->toBe('null')
            ->and($_ENV[ENV_KEY])->toBe('null')
            ->and($_SERVER[ENV_KEY])->toBe('null')
            ->and(config('media.clip_ranking_timeout_seconds'))->toBe('null');
    });
});

it('helper restores preexisting absent state in all stores and config', function () {
    withAmbientEnvironmentRestored(function () {
        // Arrange: ensure key is absent in all stores
        putenv(ENV_KEY);
        unset($_ENV[ENV_KEY]);
        unset($_SERVER[ENV_KEY]);
        // Properly remove from config (not set null)
        $mediaConfig = config('media', []);
        Arr::forget($mediaConfig, 'clip_ranking_timeout_seconds');
        config(['media' => $mediaConfig]);

        $snapshots = [];

        // Act: run with isolated '45', then capture restored state
        withIsolatedTimeoutEnv('45', function () use (&$snapshots) {
            $snapshots['inside_getenv'] = getenv(ENV_KEY);
            $snapshots['inside_env'] = $_ENV[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_server'] = $_SERVER[ENV_KEY] ?? 'NOT_SET';
            $snapshots['inside_config'] = config('media.clip_ranking_timeout_seconds');
        });

        // Assert: after isolation, absent state restored everywhere
        expect(getenv(ENV_KEY))->toBeFalse()
            ->and(array_key_exists(ENV_KEY, $_ENV))->toBeFalse()
            ->and(array_key_exists(ENV_KEY, $_SERVER))->toBeFalse()
            ->and(config()->offsetExists('media.clip_ranking_timeout_seconds'))->toBeFalse();
    });
});

it('both environment cleanup layers preserve absent or explicit null config across repeated throws', function (bool $present) {
    withAmbientEnvironmentRestored(function () use ($present) {
        putenv(ENV_KEY.'=false');
        $_ENV[ENV_KEY] = null;
        unset($_SERVER[ENV_KEY]);
        $media = config('media');
        Arr::forget($media, 'clip_ranking_timeout_seconds');
        if ($present) {
            $media['clip_ranking_timeout_seconds'] = null;
        }
        config(['media' => $media]);

        for ($repeat = 0; $repeat < 2; $repeat++) {
            foreach (['isolated', 'ambient'] as $layer) {
                $callback = function () {
                    config(['media.clip_ranking_timeout_seconds' => '45']);
                    putenv(ENV_KEY.'=45');
                    $_ENV[ENV_KEY] = '45';
                    $_SERVER[ENV_KEY] = '45';
                    throw new \RuntimeException('cleanup sentinel');
                };
                expect(fn () => $layer === 'isolated'
                    ? withIsolatedTimeoutEnv('45', $callback)
                    : withAmbientEnvironmentRestored($callback))
                    ->toThrow(\RuntimeException::class, 'cleanup sentinel');

                expect(getenv(ENV_KEY))->toBe('false')
                    ->and(array_key_exists(ENV_KEY, $_ENV))->toBeTrue()
                    ->and($_ENV[ENV_KEY])->toBeNull()
                    ->and(array_key_exists(ENV_KEY, $_SERVER))->toBeFalse()
                    ->and(config()->offsetExists('media.clip_ranking_timeout_seconds'))->toBe($present)
                    ->and(config('media'))->toBe($media);
            }
        }
    });
})->with(['absent' => false, 'explicit null' => true]);

it('helper restores exact original state even when callback throws', function () {
    withAmbientEnvironmentRestored(function () {
        // Arrange: set up preexisting '30' state
        $_ENV[ENV_KEY] = '30';
        $_SERVER[ENV_KEY] = '30';
        putenv(ENV_KEY.'=30');
        config(['media.clip_ranking_timeout_seconds' => '30']);

        // Act: run with isolated '45' and throw inside callback
        $thrown = false;
        try {
            withIsolatedTimeoutEnv('45', function () {
                // Inside isolation should see '45'
                expect(getenv(ENV_KEY))->toBe('45')
                    ->and($_ENV[ENV_KEY])->toBe('45')
                    ->and($_SERVER[ENV_KEY])->toBe('45')
                    ->and(config('media.clip_ranking_timeout_seconds'))->toBe('45');

                throw new \RuntimeException('test exception');
            });
        } catch (\RuntimeException $e) {
            $thrown = true;
        }

        // Assert: exception propagated and original '30' restored everywhere
        expect($thrown)->toBeTrue()
            ->and(getenv(ENV_KEY))->toBe('30')
            ->and($_ENV[ENV_KEY])->toBe('30')
            ->and($_SERVER[ENV_KEY])->toBe('30')
            ->and(config('media.clip_ranking_timeout_seconds'))->toBe('30');
    });
});

it('helper restores differing values across getenv, $_ENV, $_SERVER, and config on throw', function () {
    withAmbientEnvironmentRestored(function () {
        // Arrange: set up DIFFERENT values in each store
        // getenv=31, $_ENV=32, $_SERVER absent, config=33
        putenv(ENV_KEY.'=31');
        $_ENV[ENV_KEY] = '32';
        // $_SERVER[ENV_KEY] intentionally absent
        unset($_SERVER[ENV_KEY]);
        config(['media.clip_ranking_timeout_seconds' => '33']);

        // Act: run with isolated '45' and throw inside callback
        $thrown = false;
        try {
            withIsolatedTimeoutEnv('45', function () {
                expect(getenv(ENV_KEY))->toBe('45')
                    ->and($_ENV[ENV_KEY])->toBe('45')
                    ->and($_SERVER[ENV_KEY])->toBe('45')
                    ->and(config('media.clip_ranking_timeout_seconds'))->toBe('45');

                throw new \RuntimeException('test exception');
            });
        } catch (\RuntimeException $e) {
            $thrown = true;
        }

        // Assert: exception propagated and DIFFERENT original values restored
        expect($thrown)->toBeTrue()
            ->and(getenv(ENV_KEY))->toBe('31')
            ->and($_ENV[ENV_KEY])->toBe('32')
            ->and(array_key_exists(ENV_KEY, $_SERVER))->toBeFalse()
            ->and(config('media.clip_ranking_timeout_seconds'))->toBe('33');
    });
});

/*
|--------------------------------------------------------------------------
| Positive control: valid timeout invokes createProcess
|--------------------------------------------------------------------------
*/

it('explicit env 45: action invokes createProcess (boolean tracker true) with valid contract', function () {
    withRealConfigLoad('45', function () {
        $contract = minimalRankClipsContract();
        [$action, $holder] = createActionWithProcessTracking();

        $failure = null;
        try {
            $action->rankClips($contract);
        } catch (\Throwable $exception) {
            // Expected: sanitized error from mock response validation failure
            // (mock returns empty JSON, validator rejects)
            $failure = $exception;
        }

        // Positive control: boolean tracker true = createProcess WAS called
        // Even though validation fails on mock response, createProcess was invoked
        expect($holder->value)->toBeTrue();
    });
});
