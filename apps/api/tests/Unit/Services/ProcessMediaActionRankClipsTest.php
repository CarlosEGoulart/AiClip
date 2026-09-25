<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Services\ProcessMediaAction;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function createRankClipsContract(array $overrides = []): MediaProcessingContract
{
    $data = array_merge([
        'version' => '1.0.0',
        'action' => 'rank_clips',
        'media' => ['duration_ms' => 40000],
        'candidates' => [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'transcript_text' => 'engaging content'],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'transcript_text' => ''],
        ],
        'configuration' => [
            'prototype_query' => 'Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline.',
        ],
    ], $overrides);

    // Create contract directly since fromArray doesn't support rank_clips yet (RED phase)
    $contract = new MediaProcessingContract;
    $contract->version = $data['version'];
    $contract->action = $data['action'];
    $contract->durationMs = $data['media']['duration_ms'];
    $contract->candidates = $data['candidates'];
    $contract->configuration = $data['configuration'];
    // rank_clips is metadata-only, no legacy envelope fields needed
    $contract->mediaAssetId = 0;
    $contract->projectId = 0;
    $contract->storage = ['disk' => '', 'key' => '', 'mime_type' => ''];
    $contract->idempotencyKey = '00000000-0000-0000-0000-000000000000';
    $contract->createdAt = now()->toIso8601String();

    return $contract;
}

function createMockableAction(array $commandOutputs): ProcessMediaAction
{
    $outputSequence = $commandOutputs;

    return new class($outputSequence) extends ProcessMediaAction
    {
        private array $outputSequence;

        private int $callIndex = 0;

        public function __construct(array $outputSequence)
        {
            $this->outputSequence = $outputSequence;
        }

        protected function createProcess(array $command): Process
        {
            $index = $this->callIndex++;
            $data = $this->outputSequence[$index] ?? $this->outputSequence[0];

            return new class($data) extends Process
            {
                private array $processData;

                public function __construct(array $data)
                {
                    $this->processData = $data;
                    parent::__construct(['echo', 'ok']);
                }

                public function setTimeout(?float $timeout): static
                {
                    return $this;
                }

                public function run(?callable $callback = null, array $env = []): int
                {
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

                public function getExitCode(): int
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
    $contract = createRankClipsContract();

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

    $action = createMockableAction([
        [
            'exitCode' => 0,
            'stdout' => json_encode($workerResponse),
            'stderr' => '',
        ],
    ]);

    $result = $action->rankClips($contract);

    expect($result['status'])->toBe('success');
    expect($result['ranking'])->toBeArray();
    expect($result['ranking']['algorithm'])->toBe('cross_encoder_reranker');
    expect($result['ranking']['recommendations'])->toHaveCount(2);
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

it('captures stderr on failure', function () {
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
        expect($e->stderr)->toBe('Detailed error output here');

        return;
    }

    $this->fail('Expected ProcessMediaException was not thrown');
});

/*
|--------------------------------------------------------------------------
| Rank Clips Preflight Validation
|--------------------------------------------------------------------------
*/

it('throws ProcessMediaException when contract validation fails', function () {
    $contract = createRankClipsContract(['media' => ['duration_ms' => 0]]);

    $action = createMockableAction([]);

    $this->expectException(ProcessMediaException::class);
    $this->expectExceptionMessage('Invalid ranking contract');
    $action->rankClips($contract);
});

it('does not call createProcess when contract is invalid', function () {
    $contract = createRankClipsContract(['media' => ['duration_ms' => 0]]);

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

/*
|--------------------------------------------------------------------------
| Timeout Configuration
|--------------------------------------------------------------------------
*/

it('uses configured clip_ranking_timeout_seconds', function () {
    config(['media.clip_ranking_timeout_seconds' => 45]);

    $contract = createRankClipsContract();

    // Contract-valid success response: exact fixed provenance per the M5 spec
    // and exactly K=2 recommendation entries matching the input candidates, so
    // strict validation passes and the timeout assertion below executes.
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

        public ?float $capturedTimeout = null;

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

                public function run(?callable $callback = null, array $env = []): int
                {
                    // Record the applied timeout at the point run() executes,
                    // after rankClips has applied the configured setTimeout.
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

                public function getExitCode(): int
                {
                    return 0;
                }
            };
        }
    };

    $action->rankClips($contract);

    expect($action->capturedTimeout)->toBe(45.0);
});

it('rejects invalid timeout configuration', function () {
    config(['media.clip_ranking_timeout_seconds' => 200]); // > 120

    $contract = createRankClipsContract();

    $action = createMockableAction([]);

    $this->expectException(ProcessMediaException::class);
    $this->expectExceptionMessage('Invalid clip ranking timeout configuration');
    $action->rankClips($contract);
});

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

    expect($result['status'])->toBe('success');
    expect($result['probe']['duration_ms'])->toBe(120000);
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
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
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

    expect($result['status'])->toBe('success');
    expect($result['analysis']['algorithm'])->toBe('scene_timing_baseline');
});
