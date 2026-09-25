<?php

namespace Tests\Unit\Contracts;

use App\Contracts\ClipRankingProvider;
use App\Contracts\MediaProcessingContract;
use App\Providers\AppServiceProvider;
use App\Services\FakeRankingProvider;
use App\Services\ProcessMediaAction;
use App\Services\WorkerRankingProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Interface (frozen RED coverage)
|--------------------------------------------------------------------------
*/

it('clip ranking provider interface exists', function () {
    $this->assertTrue(
        interface_exists('App\Contracts\ClipRankingProvider'),
        'App\Contracts\ClipRankingProvider interface does not exist'
    );
});

it('clip ranking provider interface has rank method', function () {
    $this->assertTrue(interface_exists('App\Contracts\ClipRankingProvider'));

    $reflection = new \ReflectionClass('App\Contracts\ClipRankingProvider');
    $this->assertTrue($reflection->hasMethod('rank'), 'rank method missing');
    $method = $reflection->getMethod('rank');
    $this->assertTrue($method->isPublic(), 'rank method must be public');
    $this->assertTrue($method->isAbstract(), 'rank method must be abstract');
});

it('clip ranking provider interface has get_model_identity method', function () {
    $this->assertTrue(interface_exists('App\Contracts\ClipRankingProvider'));

    $reflection = new \ReflectionClass('App\Contracts\ClipRankingProvider');
    $this->assertTrue($reflection->hasMethod('getModelIdentity'), 'getModelIdentity method missing');
    $method = $reflection->getMethod('getModelIdentity');
    $this->assertTrue($method->isPublic(), 'getModelIdentity method must be public');
    $this->assertTrue($method->isAbstract(), 'getModelIdentity method must be abstract');
});

/*
|--------------------------------------------------------------------------
| Container Binding (single authoritative binding, one per context)
|--------------------------------------------------------------------------
*/

it('container resolves the fake provider in the testing context', function () {
    (new AppServiceProvider($this->app))->register();

    // Exactly one ClipRankingProvider binding: the CI/testing context binds
    // the deterministic PHP fake.
    expect(array_keys($this->app->getBindings()))->toContain(ClipRankingProvider::class);
    expect(app(ClipRankingProvider::class))->toBeInstanceOf(FakeRankingProvider::class);
});

it('container resolves the real delegation adapter under production configuration', function () {
    // Simulate the production context, then re-run the single register()
    // binding: production/default binds the thin adapter that delegates
    // through the single ProcessMediaAction::rankClips path.
    $this->app->detectEnvironment(fn () => 'production');
    (new AppServiceProvider($this->app))->register();

    expect(app(ClipRankingProvider::class))->toBeInstanceOf(WorkerRankingProvider::class);
});

it('rankClips does not resolve the provider from the container', function () {
    // The frozen process-double architecture: ProcessMediaAction::rankClips
    // must never resolve App\Contracts\ClipRankingProvider from the
    // container. Point the container binding at a provider whose rank() would
    // poison the result; rankClips invoked through a process double must
    // succeed without touching it.
    $this->app->bind(ClipRankingProvider::class, fn () => throw new \RuntimeException('rankClips must not resolve the provider from the container'));

    $workerResponse = [
        'status' => 'success',
        'ranking' => [
            'algorithm' => 'cross_encoder_reranker',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
                'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
                'model_revision' => 'main',
                'provider_name' => 'cross_encoder_ranking_provider',
                'transcript_used' => true,
                'normalization' => 'sigmoid',
                'score_scale' => 1.0,
                'tie_break' => 'm4_rank_then_chronological',
            ],
            'recommendations' => [
                ['m4_candidate_index' => 0, 'semantic_score' => 0.872341, 'combined_rank' => 1],
                ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 2],
            ],
        ],
    ];

    $action = new class([$workerResponse]) extends ProcessMediaAction
    {
        private array $workerResponses;

        public function __construct(array $workerResponses)
        {
            $this->workerResponses = $workerResponses;
        }

        protected function createProcess(array $command): Process
        {
            $workerResponse = $this->workerResponses[0];

            return new class($workerResponse) extends Process
            {
                public function __construct(private array $workerResponse)
                {
                    parent::__construct(['echo', 'ok']);
                }

                public function setTimeout(?float $timeout): static
                {
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

                public function getErrorOutput(): string
                {
                    return '';
                }

                public function getExitCode(): int
                {
                    return 0;
                }
            };
        }
    };

    $contract = new MediaProcessingContract;
    $contract->action = 'rank_clips';
    $contract->durationMs = 20000;
    $contract->candidates = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'transcript_text' => 'engaging content'],
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'transcript_text' => ''],
    ];
    $contract->configuration = [
        'prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
    ];

    $result = $action->rankClips($contract);

    expect($result['status'])->toBe('success');
    expect($result['ranking']['recommendations'])->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| Real Delegation Adapter (recording process double)
|--------------------------------------------------------------------------
*/

it('real adapter projects the rank_clips request and delegates through the rankClips path', function () {
    $candidates = [
        ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'transcript_text' => 'engaging content'],
        ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'transcript_text' => ''],
    ];
    $prototypeQuery = 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.';

    $workerResponse = [
        'status' => 'success',
        'ranking' => [
            'algorithm' => 'cross_encoder_reranker',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'prototype_query' => $prototypeQuery,
                'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
                'model_revision' => 'main',
                'provider_name' => 'cross_encoder_ranking_provider',
                'transcript_used' => true,
                'normalization' => 'sigmoid',
                'score_scale' => 1.0,
                'tie_break' => 'm4_rank_then_chronological',
            ],
            'recommendations' => [
                ['m4_candidate_index' => 0, 'semantic_score' => 0.872341, 'combined_rank' => 1],
                ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 2],
            ],
        ],
    ];

    // Recording process double: captures the projected rank_clips request
    // (stdin transport) that rankClips sends through createProcess/setInput.
    $action = new class([$workerResponse]) extends ProcessMediaAction
    {
        public array $projectedRequests = [];

        private array $workerResponses;

        public function __construct(array $workerResponses)
        {
            $this->workerResponses = $workerResponses;
        }

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

                public function setTimeout(?float $timeout): static
                {
                    return $this;
                }

                public function setInput(mixed $input): static
                {
                    // Symfony's Process constructor itself calls setInput(null);
                    // only the non-null stdin input is a projected request.
                    if ($input !== null) {
                        $this->action->projectedRequests[] = json_decode((string) $input, true);
                    }

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

                public function getErrorOutput(): string
                {
                    return '';
                }

                public function getExitCode(): int
                {
                    return 0;
                }
            };
        }
    };

    $provider = new WorkerRankingProvider($action);

    $result = $provider->rank($candidates, $prototypeQuery);

    // Validated recommendations + provenance, per the provider contract.
    expect($result)->toBe([
        'recommendations' => [
            ['m4_candidate_index' => 0, 'semantic_score' => 0.872341, 'combined_rank' => 1],
            ['m4_candidate_index' => 1, 'semantic_score' => 0.654321, 'combined_rank' => 2],
        ],
        'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
        'model_revision' => 'main',
        'provider_name' => 'cross_encoder_ranking_provider',
        'transcript_used' => true,
    ]);

    // The projected request traveled through the single rankClips path as a
    // metadata-only rank_clips request built from the given candidates and
    // the fixed prototype query.
    expect($action->projectedRequests)->toHaveCount(1);
    $projected = $action->projectedRequests[0];
    expect($projected['version'])->toBe('1.0.0');
    expect($projected['action'])->toBe('rank_clips');
    expect($projected['media']['duration_ms'])->toBe(20000);
    expect($projected['candidates'])->toBe($candidates);
    expect($projected['configuration']['prototype_query'])->toBe($prototypeQuery);
});

it('real adapter exposes the fixed cross-encoder identity', function () {
    // getModelIdentity is static-identity only: a plain action instance
    // suffices and no worker invocation is needed.
    $provider = new WorkerRankingProvider(new ProcessMediaAction);

    expect($provider->getModelIdentity())->toBe([
        'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2',
        'model_revision' => 'main',
        'provider_name' => 'cross_encoder_ranking_provider',
    ]);
});
