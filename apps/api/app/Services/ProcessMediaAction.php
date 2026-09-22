<?php

namespace App\Services;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ProcessMediaAction
{
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

        $timeout = config('media.clip_analysis_timeout_seconds', 30);
        $workerCommand = config('media.worker_command', 'python -m aiclip_worker.cli');

        $contractJson = json_encode($contract->toMetadataArray(), JSON_THROW_ON_ERROR);

        Log::info('ProcessMediaAction: invoking worker analyze-clips', [
            'media_asset_id' => $contract->mediaAssetId,
            'timeout' => $timeout,
        ]);

        $process = $this->createProcess([
            ...explode(' ', $workerCommand),
            'analyze-clips',
        ]);

        $process->setTimeout($timeout);
        $process->setInput($contractJson);
        $process->run();

        if ($process->isSuccessful()) {
            $rawOutput = $process->getOutput();
            $output = json_decode($rawOutput, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($output) || ($output['status'] ?? '') !== 'success') {
                throw new ProcessMediaException(
                    'Invalid clip analysis result',
                    $process->getExitCode(),
                    '',
                );
            }

            Log::info('ProcessMediaAction: analyze-clips succeeded', [
                'media_asset_id' => $contract->mediaAssetId,
            ]);

            return $output;
        }

        // Process failed — sanitize output, never leak raw worker text
        $exitCode = $process->getExitCode();

        throw new ProcessMediaException(
            'Clip analysis failed',
            $exitCode,
            '',
        );
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
