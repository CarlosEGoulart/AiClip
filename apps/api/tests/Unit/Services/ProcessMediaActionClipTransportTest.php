<?php

namespace Tests\Unit\Services;

use App\Contracts\MediaProcessingContract;
use App\Services\ProcessMediaAction;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// No database traits, models, queries, migrations, or real child processes.
uses(TestCase::class);

it('transports clip timing availability through actual process stdin', function (?array $segments) {
    config(['media.worker_command' => 'python -m aiclip_worker.cli']);
    config(['media.clip_analysis_timeout_seconds' => 30]);
    config(['logging.default' => 'null']);

    $configuration = [
        'min_duration_ms' => 5000,
        'target_duration_ms' => 30000,
        'max_duration_ms' => 60000,
        'max_candidates' => 20,
        'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
    ];
    $contract = new MediaProcessingContract;
    $contract->action = 'analyze_clips';
    $contract->mediaAssetId = 123;
    $contract->projectId = 456;
    $contract->storage = ['disk' => 'media', 'key' => 'PRIVATE_STORAGE_SENTINEL', 'mime_type' => 'video/mp4'];
    $contract->idempotencyKey = 'PRIVATE_IDEMPOTENCY_SENTINEL';
    $contract->createdAt = '2026-09-22T00:00:00Z';
    $contract->durationMs = 30000;
    $contract->scenes = [];
    $contract->transcriptSegments = $segments;
    $contract->configuration = $configuration;

    // Explicit valid empty-scene output: this test isolates request transport.
    $response = [
        'status' => 'success',
        'analysis' => [
            'algorithm' => 'scene_timing_baseline',
            'algorithm_version' => '1.0.0',
            'parameters' => [
                'configuration' => $configuration,
                'effective_weights' => $segments === null
                    ? ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0]
                    : $configuration['weights'],
                'transcript_used' => $segments !== null,
                'candidate_policy' => 'whole_scene_non_overlapping',
                'timing_policy' => 'original_media_ms',
                'transcript_policy' => 'optional_strict_unshifted',
                'boundary_policy' => 'strict_interior_speech_cut',
                'score_scale' => 1000000,
                'rounding' => 'half_up',
                'limits' => [
                    'max_scenes' => 10000,
                    'max_transcript_segments' => 50000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
            ],
            'candidates' => [],
        ],
    ];

    $process = new class(json_encode($response, JSON_THROW_ON_ERROR)) extends Process
    {
        public int $runs = 0;

        public function __construct(private string $output)
        {
            parent::__construct(['recording-worker']);
        }

        public function run(?callable $callback = null, array $env = []): int
        {
            $this->runs++;

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
    };

    $action = new class($process) extends ProcessMediaAction
    {
        public array $commands = [];

        public function __construct(private Process $process) {}

        protected function createProcess(array $command): Process
        {
            $this->commands[] = $command;

            return $this->process;
        }
    };

    // analyzeClips, serialization, setInput, and setTimeout are production methods.
    expect($action->analyzeClips($contract))->toBe($response);
    expect($process->runs)->toBe(1);
    expect($process->getTimeout())->toBe(30.0);
    expect($action->commands)->toBe([['python', '-m', 'aiclip_worker.cli', 'analyze-clips']]);

    $stdin = $process->getInput();
    expect($stdin)->toBeString();
    expect($stdin)->not->toContain('PRIVATE_STORAGE_SENTINEL', 'PRIVATE_IDEMPOTENCY_SENTINEL');
    $payload = json_decode($stdin, true, 512, JSON_THROW_ON_ERROR);
    foreach (['media_asset_id', 'project_id', 'storage', 'idempotency_key', 'created_at', 'text', 'language'] as $field) {
        $this->assertArrayNotHasKey($field, $payload);
    }

    $expected = [
        'version' => '1.0.0',
        'action' => 'analyze_clips',
        'media' => ['duration_ms' => 30000],
        'scenes' => [],
        'configuration' => $configuration,
    ];
    if ($segments === null) {
        $this->assertArrayNotHasKey(
            'transcript_segments',
            $payload,
            'Unavailable transcript timing must be omitted from actual worker stdin, not serialized as null.',
        );
    } else {
        $this->assertArrayHasKey('transcript_segments', $payload);
        expect($payload['transcript_segments'])->toBe($segments);
        $expected['transcript_segments'] = $segments;
    }
    $this->assertEquals($expected, $payload);
})->with([
    'unavailable transcript is omitted' => [null],
    'completed empty transcript stays present' => [[]],
    'available transcript carries timing only' => [[
        ['start_ms' => 0, 'end_ms' => 10000],
        ['start_ms' => 15000, 'end_ms' => 30000],
    ]],
]);
