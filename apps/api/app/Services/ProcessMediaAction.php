<?php

namespace App\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ProcessMediaAction
{
    /**
     * Maximum captured worker response for the metadata-only rank_clips
     * boundary. The rank_clips contract caps the worker response at 1 MiB
     * (spec.md "Exact text rules and durable binding"); anything larger is a
     * runtime failure, never a partially decoded result.
     */
    private const MAX_RANKING_OUTPUT_BYTES = 1048576;

    /**
     * Probe media file using the Python worker CLI.
     *
     * @return array{status: string, probe: array<string, mixed>}
     *
     * @throws ProcessMediaException
     */
    public function probe(MediaProcessingContract $contract): array
    {
        $timeout = config('media.probe_timeout_seconds', 30);
        $workerCommand = config('media.worker_command', 'python -m aiclip_worker.cli');

        $contractJson = json_encode($contract->toArray(), JSON_THROW_ON_ERROR);

        Log::info('ProcessMediaAction: invoking worker probe', [
            'media_asset_id' => $contract->mediaAssetId,
            'timeout' => $timeout,
        ]);

        $process = $this->createProcess([
            ...explode(' ', $workerCommand),
            'probe',
            '--contract-json',
            $contractJson,
        ]);

        $process->setTimeout($timeout);
        $process->run();

        if ($process->isSuccessful()) {
            $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($output) || ($output['status'] ?? '') !== 'success') {
                throw ProcessMediaException::fromWorkerOutput(
                    $output ?? ['error' => 'Invalid worker output'],
                    $process->getExitCode(),
                );
            }

            Log::info('ProcessMediaAction: probe succeeded', [
                'media_asset_id' => $contract->mediaAssetId,
            ]);

            return $output;
        }

        // Process failed
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Try to parse error output as JSON
        $errorOutput = json_decode($process->getOutput(), true);
        if (is_array($errorOutput) && isset($errorOutput['error'])) {
            throw ProcessMediaException::fromWorkerOutput($errorOutput, $exitCode);
        }

        throw new ProcessMediaException(
            "Worker process failed with exit code {$exitCode}",
            $exitCode,
            $stderr,
        );
    }

    /**
     * Extract and normalize audio using the Python worker CLI.
     *
     * @return array{status: string, extraction: array<string, mixed>}
     *
     * @throws ProcessMediaException
     */
    public function extractAudio(MediaProcessingContract $contract): array
    {
        $timeout = config('media.extract_audio_timeout_seconds', 120);
        $workerCommand = config('media.worker_command', 'python -m aiclip_worker.cli');

        $contractJson = json_encode($contract->toArray(), JSON_THROW_ON_ERROR);

        Log::info('ProcessMediaAction: invoking worker extract-audio', [
            'media_asset_id' => $contract->mediaAssetId,
            'timeout' => $timeout,
        ]);

        $process = $this->createProcess([
            ...explode(' ', $workerCommand),
            'extract-audio',
            '--contract-json',
            $contractJson,
        ]);

        $process->setTimeout($timeout);
        $process->run();

        if ($process->isSuccessful()) {
            $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($output) || ($output['status'] ?? '') !== 'success') {
                throw ProcessMediaException::fromWorkerOutput(
                    $output ?? ['error' => 'Invalid worker output'],
                    $process->getExitCode(),
                );
            }

            Log::info('ProcessMediaAction: extract-audio succeeded', [
                'media_asset_id' => $contract->mediaAssetId,
            ]);

            return $output;
        }

        // Process failed
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Try to parse error output as JSON
        $errorOutput = json_decode($process->getOutput(), true);
        if (is_array($errorOutput) && isset($errorOutput['error'])) {
            throw ProcessMediaException::fromWorkerOutput($errorOutput, $exitCode);
        }

        throw new ProcessMediaException(
            "Worker process failed with exit code {$exitCode}",
            $exitCode,
            $stderr,
        );
    }

    /**
     * Transcribe audio using the Python worker CLI.
     *
     * @return array{status: string, transcription: array<string, mixed>}
     *
     * @throws ProcessMediaException
     */
    public function transcribe(MediaProcessingContract $contract): array
    {
        $timeout = config('media.transcribe_timeout_seconds', 300);
        $workerCommand = config('media.worker_command', 'python -m aiclip_worker.cli');

        $contractJson = json_encode($contract->toArray(), JSON_THROW_ON_ERROR);

        Log::info('ProcessMediaAction: invoking worker transcribe', [
            'media_asset_id' => $contract->mediaAssetId,
            'timeout' => $timeout,
        ]);

        $process = $this->createProcess([
            ...explode(' ', $workerCommand),
            'transcribe',
            '--contract-json',
            $contractJson,
        ]);

        $process->setTimeout($timeout);
        $process->run();

        if ($process->isSuccessful()) {
            $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($output) || ($output['status'] ?? '') !== 'success') {
                throw ProcessMediaException::fromWorkerOutput(
                    $output ?? ['error' => 'Invalid worker output'],
                    $process->getExitCode(),
                );
            }

            Log::info('ProcessMediaAction: transcribe succeeded', [
                'media_asset_id' => $contract->mediaAssetId,
            ]);

            return $output;
        }

        // Process failed
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Try to parse error output as JSON
        $errorOutput = json_decode($process->getOutput(), true);
        if (is_array($errorOutput) && isset($errorOutput['error'])) {
            throw ProcessMediaException::fromWorkerOutput($errorOutput, $exitCode);
        }

        throw new ProcessMediaException(
            "Worker process failed with exit code {$exitCode}",
            $exitCode,
            $stderr,
        );
    }

    /**
     * Detect scenes in video using the Python worker CLI.
     *
     * @return array{status: string, scene_detection: array<string, mixed>}
     *
     * @throws ProcessMediaException
     */
    public function detectScenes(MediaProcessingContract $contract): array
    {
        if (! $contract->validate()) {
            throw new ProcessMediaException('Invalid detect_scenes media processing contract');
        }

        $timeout = config('media.scene_detect_timeout_seconds', 120);
        $workerCommand = config('media.worker_command', 'python -m aiclip_worker.cli');

        $contractJson = json_encode($contract->toArray(), JSON_THROW_ON_ERROR);

        Log::info('ProcessMediaAction: invoking worker detect-scenes', [
            'media_asset_id' => $contract->mediaAssetId,
            'timeout' => $timeout,
        ]);

        $process = $this->createProcess([
            ...explode(' ', $workerCommand),
            'detect-scenes',
            '--contract-json',
            $contractJson,
        ]);

        $process->setTimeout($timeout);
        $process->run();

        if ($process->isSuccessful()) {
            $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($output) || ($output['status'] ?? '') !== 'success') {
                throw ProcessMediaException::fromWorkerOutput(
                    $output ?? ['error' => 'Invalid worker output'],
                    $process->getExitCode(),
                );
            }

            Log::info('ProcessMediaAction: detect-scenes succeeded', [
                'media_asset_id' => $contract->mediaAssetId,
            ]);

            return $output;
        }

        // Process failed
        $stderr = $process->getErrorOutput();
        $exitCode = $process->getExitCode();

        // Try to parse error output as JSON
        $errorOutput = json_decode($process->getOutput(), true);
        if (is_array($errorOutput) && isset($errorOutput['error'])) {
            throw ProcessMediaException::fromWorkerOutput($errorOutput, $exitCode);
        }

        throw new ProcessMediaException(
            "Worker process failed with exit code {$exitCode}",
            $exitCode,
            $stderr,
        );
    }

    /**
     * Analyze clip candidates using the Python worker CLI.
     *
     * Contract travels via stdin, not command-line arguments.
     *
     * @return array{status: string, analysis: array<string, mixed>}
     *
     * @throws ProcessMediaException
     */
    public function analyzeClips(MediaProcessingContract $contract): array
    {
        if (! $contract->validate()) {
            throw new ProcessMediaException('Invalid analyze_clips media processing contract');
        }

        // Validate operational timeout before process creation
        $timeout = config('media.clip_analysis_timeout_seconds', 30);
        if (! is_int($timeout) || $timeout <= 0 || $timeout > 120) {
            throw new ProcessMediaException('Invalid clip analysis timeout configuration');
        }

        try {
            $workerCommand = config('media.worker_command', 'python -m aiclip_worker.cli');
            $request = $contract->toMetadataArray();
            $contractJson = json_encode($request, JSON_THROW_ON_ERROR);
            $process = $this->createProcess([
                ...explode(' ', $workerCommand),
                'analyze-clips',
            ]);
            $process->setTimeout($timeout);
            $process->setInput($contractJson);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new ProcessMediaException('Clip analysis failed');
            }

            // Keep JSON objects distinct from lists until strict validation completes.
            $output = json_decode($process->getOutput(), false, 512, JSON_THROW_ON_ERROR);

            return ClipAnalysisValidator::result($output, $request);
        } catch (ProcessMediaException|ProcessTimedOutException|JsonException) {
            // Classified failures (explicit worker/validation failure, actual
            // process timeout, invalid worker JSON) commit a fixed sanitized
            // ordinary failure with no raw cause chained.
            throw new ProcessMediaException('Clip analysis failed', 1, '');
        } catch (\Throwable) {
            // Unexpected runtime/programming/infrastructure failure: escape as
            // a sanitized abort, never as ordinary worker failure, carrying
            // no raw message, output, contract or previous cause.
            throw new ProcessMediaException('clip_analysis_aborted', 1, '');
        }
    }

    /**
     * Rank clip candidates using the Python worker CLI.
     *
     * Contract travels via stdin, not command-line arguments.
     *
     * @return array{status: string, ranking: array<string, mixed>}
     *
     * @throws ProcessMediaException
     */
    public function rankClips(MediaProcessingContract $contract): array
    {
        if (! $contract->validate()) {
            throw new ProcessMediaException('Invalid ranking contract');
        }

        // Strict operational configuration is validated before process
        // creation: an unset or unknown provider selection, or a non-integer
        // or out-of-range timeout, fails closed without launching anything.
        $timeout = ClipRankingProfile::timeoutSeconds();

        try {
            $workerCommand = config('media.worker_command', 'python -m aiclip_worker.cli');
            $request = $contract->toRankClipsMetadataArray();
            $contractJson = json_encode($request, JSON_THROW_ON_ERROR);

            // Python hashes the exact raw stdin bytes; Laravel computes the
            // same digest over the exact bytes it is about to send.
            $requestSha256 = hash('sha256', $contractJson);

            $process = $this->createProcess([
                ...explode(' ', $workerCommand),
                'rank-clips',
            ]);
            $process->setTimeout($timeout);
            $process->setInput($contractJson);

            // Bound the captured worker output while it streams: on overflow the
            // owned child is terminated immediately and the whole attempt is
            // rejected, so no unbounded buffer and no partial ranking exists.
            $capturedBytes = 0;
            $process->run(function (string $type, string $buffer) use ($process, &$capturedBytes): void {
                $capturedBytes += strlen($buffer);

                if ($capturedBytes > self::MAX_RANKING_OUTPUT_BYTES) {
                    $process->stop(0.0);

                    throw new ProcessMediaException('Ranking failed', 1, '');
                }
            });

            // Re-check the bound on the authoritative captured output. A real
            // process already stopped above; this also covers any transport
            // that buffers without reporting chunks.
            $stdout = $process->getOutput();
            if (strlen($stdout) > self::MAX_RANKING_OUTPUT_BYTES) {
                throw new ProcessMediaException('Ranking failed', 1, '');
            }

            if (! $process->isSuccessful()) {
                // Worker diagnostics, stdout, stderr and the error envelope are
                // never propagated: only a fixed category, the exit code and an
                // empty stderr cross this boundary.
                throw new ProcessMediaException('Ranking failed', $process->getExitCode() ?? 1, '');
            }

            // Keep JSON objects distinct from lists until strict validation completes.
            $output = json_decode($stdout, false, 512, JSON_THROW_ON_ERROR);

            return ClipRecommendationValidator::result($output, $request, $requestSha256);
        } catch (ProcessMediaException $e) {
            // Already sanitized categories (invalid_configuration, Ranking
            // failed, Ranking validation failed) are preserved verbatim: no
            // raw previous cause is chained and no captured worker diagnostic
            // is ever attached.
            throw $e;
        } catch (JsonException|ProcessTimedOutException) {
            // Invalid worker JSON or actual process timeout: fixed sanitized
            // ordinary failure with no raw cause chained.
            throw new ProcessMediaException('Ranking validation failed', 1, '');
        } catch (\Throwable) {
            // Unexpected runtime/programming/infrastructure failure: escape as
            // a sanitized abort, never as ordinary worker failure, carrying
            // no raw message, output, contract or previous cause.
            throw new ProcessMediaException('clip_ranking_aborted', 1, '');
        }
    }

    /**
     * Create a process instance. Overridable for testing.
     *
     * @param  list<string>  $command
     */
    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }
}
