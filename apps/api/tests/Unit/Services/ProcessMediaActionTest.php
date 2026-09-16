<?php

namespace Tests\Unit;

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

function createContract(array $overrides = []): MediaProcessingContract
{
    $data = array_merge([
        'version' => '1.0.0',
        'media_asset_id' => 1,
        'project_id' => 1,
        'storage' => [
            'disk' => 'media',
            'key' => 'test/file.mp4',
            'mime_type' => 'video/mp4',
        ],
        'idempotency_key' => '550e8400-e29b-41d4-a716-446655440000',
        'created_at' => '2026-09-16T10:00:00Z',
    ], $overrides);

    return MediaProcessingContract::fromArray($data);
}

/**
 * Create a mockable ProcessMediaAction with a controllable process.
 */
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
| Probe Success
|--------------------------------------------------------------------------
*/

it('passes contract JSON via stdin to worker CLI', function () {
    $contract = createContract();

    $action = createMockableAction([
        [
            'exitCode' => 0,
            'stdout' => json_encode([
                'status' => 'success',
                'probe' => [
                    'duration_ms' => 120000,
                    'width' => 1920,
                    'height' => 1080,
                    'video_codec' => 'h264',
                    'audio_codec' => 'aac',
                    'bitrate_kbps' => 5000,
                    'fps' => 29.97,
                    'audio_channels' => 2,
                    'audio_sample_rate' => 48000,
                    'format' => 'mp4',
                    'size_bytes' => 1024000,
                ],
            ]),
            'stderr' => '',
        ],
    ]);

    $result = $action->probe($contract);

    expect($result['status'])->toBe('success');
    expect($result['probe'])->toBeArray();
    expect($result['probe']['duration_ms'])->toBe(120000);
});

it('reads stdout for probe result', function () {
    $contract = createContract();

    $probeData = [
        'duration_ms' => 60000,
        'width' => 1280,
        'height' => 720,
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
        'bitrate_kbps' => 3000,
        'fps' => 29.97,
        'audio_channels' => 2,
        'audio_sample_rate' => 44100,
        'format' => 'mp4',
        'size_bytes' => 5000000,
    ];

    $action = createMockableAction([
        [
            'exitCode' => 0,
            'stdout' => json_encode(['status' => 'success', 'probe' => $probeData]),
            'stderr' => '',
        ],
    ]);

    $result = $action->probe($contract);

    expect($result['probe'])->toBe($probeData);
});

/*
|--------------------------------------------------------------------------
| Probe Failure
|--------------------------------------------------------------------------
*/

it('throws ProcessMediaException on worker failure', function () {
    $contract = createContract();

    $action = createMockableAction([
        [
            'exitCode' => 1,
            'stdout' => json_encode([
                'status' => 'error',
                'error' => 'FFprobe failed with exit code 1',
                'stderr' => 'Invalid data found when processing input',
            ]),
            'stderr' => 'Invalid data found',
        ],
    ]);

    $this->expectException(ProcessMediaException::class);
    $action->probe($contract);
});

it('captures stderr on failure', function () {
    $contract = createContract();

    $action = createMockableAction([
        [
            'exitCode' => 1,
            'stdout' => json_encode([
                'status' => 'error',
                'error' => 'FFprobe failed',
                'stderr' => 'Detailed error output here',
            ]),
            'stderr' => 'Detailed error output here',
        ],
    ]);

    try {
        $action->probe($contract);
    } catch (ProcessMediaException $e) {
        expect($e->stderr)->toBe('Detailed error output here');

        return;
    }

    $this->fail('Expected ProcessMediaException was not thrown');
});

/*
|--------------------------------------------------------------------------
| Timeout
|--------------------------------------------------------------------------
*/

it('enforces timeout on process', function () {
    $contract = createContract();

    $action = createMockableAction([
        [
            'exitCode' => 0,
            'stdout' => json_encode([
                'status' => 'success',
                'probe' => ['duration_ms' => 0],
            ]),
            'stderr' => '',
        ],
    ]);

    $result = $action->probe($contract);

    expect($result['status'])->toBe('success');
});
