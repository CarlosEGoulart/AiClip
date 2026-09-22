<?php

namespace Tests\Support;

use App\Contracts\MediaProcessingContract;
use App\Services\ProcessMediaAction;
use Symfony\Component\Process\Process;

final class ClipAnalysisFixture
{
    public static function contract(): MediaProcessingContract
    {
        $contract = new MediaProcessingContract;
        $contract->action = 'analyze_clips';
        $contract->mediaAssetId = 1;
        $contract->durationMs = 40000;
        $contract->scenes = [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000],
            ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000],
            ['index' => 2, 'start_ms' => 20000, 'end_ms' => 40000],
        ];
        $contract->transcriptSegments = [['start_ms' => 5000, 'end_ms' => 15000], ['start_ms' => 25000, 'end_ms' => 35000]];
        $contract->configuration = [
            'min_duration_ms' => 5000, 'target_duration_ms' => 10000,
            'max_duration_ms' => 20000, 'max_candidates' => 2,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ];

        return $contract;
    }

    public static function response(): array
    {
        // Hand-derived specification golden example, never production scoring.
        return [
            'status' => 'success',
            'analysis' => [
                'algorithm' => 'scene_timing_baseline', 'algorithm_version' => '1.0.0',
                'parameters' => [
                    'configuration' => self::contract()->configuration,
                    'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
                    'transcript_used' => true,
                    'candidate_policy' => 'whole_scene_non_overlapping',
                    'timing_policy' => 'original_media_ms',
                    'transcript_policy' => 'optional_strict_unshifted',
                    'boundary_policy' => 'strict_interior_speech_cut',
                    'score_scale' => 1000000, 'rounding' => 'half_up',
                    'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
                ],
                'candidates' => [
                    ['index' => 0, 'start_ms' => 0, 'end_ms' => 10000, 'rank' => 1, 'score' => 0.75,
                        'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0.5, 'boundary_alignment' => 0.5], 'source_scene_indexes' => [0]],
                    ['index' => 1, 'start_ms' => 10000, 'end_ms' => 20000, 'rank' => 2, 'score' => 0.75,
                        'criteria' => ['duration_fit' => 1, 'speech_coverage' => 0.5, 'boundary_alignment' => 0.5], 'source_scene_indexes' => [1]],
                ],
            ],
        ];
    }

    public static function action(string $output, ?\Throwable $failure = null): ProcessMediaAction
    {
        return new class($output, $failure) extends ProcessMediaAction
        {
            public int $creations = 0;

            public function __construct(private string $output, private ?\Throwable $failure) {}

            protected function createProcess(array $command): Process
            {
                $this->creations++;

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
    }
}
