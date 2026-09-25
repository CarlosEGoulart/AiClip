<?php

namespace Tests\Support;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Services\ProcessMediaAction;
use LogicException;

final class Issue64RecordingAction extends ProcessMediaAction
{
    public int $rankCalls = 0;

    public int $extractionCalls = 0;

    public int $transcriptionCalls = 0;

    public bool $sentinelSent = false;

    public function __construct(private bool $transcriptUsed = true) {}

    public function probe(MediaProcessingContract $contract): array
    {
        return ['status' => 'success', 'probe' => MediaAsset::findOrFail($contract->mediaAssetId)->probe_result];
    }

    public function extractAudio(MediaProcessingContract $contract): array
    {
        $this->extractionCalls++;
        throw new ProcessMediaException('synthetic_extraction_failed');
    }

    public function transcribe(MediaProcessingContract $contract): array
    {
        $this->transcriptionCalls++;
        // The real job currently retries in-flight transcription before M5 can see not-ready.
        // A bounded upstream failure exposes that actual behavior without fabricating readiness.
        throw new ProcessMediaException('synthetic_transcription_failed');
    }

    public function detectScenes(MediaProcessingContract $contract): array
    {
        throw new LogicException('SETUP_BLOCKER: completed scene fixture was not reused');
    }

    public function analyzeClips(MediaProcessingContract $contract): array
    {
        throw new LogicException('SETUP_BLOCKER: completed M4 fixture was not reused');
    }

    public function rankClips(MediaProcessingContract $contract): array
    {
        $this->rankCalls++;
        $this->sentinelSent = str_contains(json_encode($contract->candidates), 'SYNTHETIC_64_');

        return Issue64RecoveryFixture::ranking($this->transcriptUsed);
    }
}
