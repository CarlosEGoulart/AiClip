<?php

namespace App\Contracts;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Services\ClipAnalysisValidator;
use App\Services\ClipRecommendationValidator;

class MediaProcessingContract
{
    public string $version = '1.0.0';

    public int $mediaAssetId;

    public int $projectId;

    /** @var array{disk: string, key: string, mime_type: string} */
    public array $storage;

    public string $idempotencyKey;

    public string $createdAt;

    public string $action = 'probe';

    /** @var array{disk: string, key: string, mime_type: string}|null */
    public ?array $outputStorage = null;

    public ?int $derivedAssetId = null;

    public ?int $durationMs = null;

    /** @var array<int, array{index: int, start_ms: int, end_ms: int}>|null */
    public ?array $scenes = null;

    /** @var array<int, array{start_ms: int, end_ms: int}>|null */
    public ?array $transcriptSegments = null;

    /** @var array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>|null */
    public ?array $candidates = null;

    /** @var array{min_duration_ms: int, target_duration_ms: int, max_duration_ms: int, max_candidates: int, weights: array{duration_fit: int, speech_coverage: int, boundary_alignment: int}}|array{prototype_query: string}|null */
    public ?array $configuration = null;

    /**
     * Create a contract from a MediaAsset model.
     */
    public static function fromMediaAsset(MediaAsset $asset, string $idempotencyKey, string $action = 'probe'): self
    {
        $contract = new self;
        $contract->mediaAssetId = $asset->id;
        $contract->projectId = $asset->project_id;
        $contract->storage = [
            'disk' => $asset->storage_disk,
            'key' => $asset->storage_key,
            'mime_type' => $asset->mime_type,
        ];
        $contract->idempotencyKey = $idempotencyKey;
        $contract->createdAt = now()->toIso8601String();
        $contract->action = $action;
        $contract->durationMs = $asset->duration_ms ?? null;

        return $contract;
    }

    /**
     * Create a contract from an array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['action'] ?? null) === 'analyze_clips') {
            $data = ClipAnalysisValidator::request($data);
            $contract = new self;
            $contract->action = 'analyze_clips';
            $contract->durationMs = $data['media']['duration_ms'];
            $contract->scenes = $data['scenes'];
            $contract->configuration = $data['configuration'];
            $contract->transcriptSegments = $data['transcript_segments'] ?? null;

            return $contract;
        }

        if (($data['action'] ?? null) === 'rank_clips') {
            $data = ClipRecommendationValidator::request($data);
            $contract = new self;
            $contract->action = 'rank_clips';
            $contract->durationMs = $data['media']['duration_ms'];
            $contract->candidates = $data['candidates'];
            $contract->configuration = $data['configuration'];
            $contract->transcriptSegments = null; // Not used in rank_clips

            return $contract;
        }

        $contract = new self;
        $contract->version = $data['version'];
        $contract->mediaAssetId = $data['media_asset_id'];
        $contract->projectId = $data['project_id'];
        $contract->storage = $data['storage'];
        $contract->idempotencyKey = $data['idempotency_key'];
        $contract->createdAt = $data['created_at'];
        $contract->action = $data['action'] ?? 'probe';
        $contract->outputStorage = $data['output_storage'] ?? null;
        $contract->derivedAssetId = $data['derived_asset_id'] ?? null;
        $contract->durationMs = $data['media']['duration_ms'] ?? null;
        $contract->scenes = $data['scenes'] ?? null;
        $contract->transcriptSegments = $data['transcript_segments'] ?? null;
        $contract->configuration = $data['configuration'] ?? null;

        return $contract;
    }

    /**
     * Serialize the contract to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        // For analyze_clips, return metadata-only shape (no legacy envelope).
        if ($this->action === 'analyze_clips') {
            return $this->toMetadataArray();
        }

        // For rank_clips, return metadata-only shape (scenes + timing),
        // matching analyze_clips. Worker transport uses toRankClipsMetadataArray.
        if ($this->action === 'rank_clips') {
            return $this->toMetadataArray();
        }

        // Legacy envelope for probe, extract_audio, transcribe, detect_scenes.
        $data = [
            'version' => $this->version,
            'media_asset_id' => $this->mediaAssetId,
            'project_id' => $this->projectId,
            'storage' => $this->storage,
            'idempotency_key' => $this->idempotencyKey,
            'created_at' => $this->createdAt,
            'action' => $this->action,
        ];

        if ($this->outputStorage !== null) {
            $data['output_storage'] = $this->outputStorage;
        }

        if ($this->derivedAssetId !== null) {
            $data['derived_asset_id'] = $this->derivedAssetId;
        }

        if ($this->durationMs !== null) {
            $data['media'] = ['duration_ms' => $this->durationMs];
        }

        return $data;
    }

    /**
     * Serialize the contract for metadata-only (analyze_clips) transport.
     *
     * Produces a privacy-safe payload with only timing and configuration,
     * omitting legacy storage/project/identity fields.
     *
     * @return array{version: string, action: string, media: array{duration_ms: int}, scenes: array, configuration: array}
     */
    public function toMetadataArray(): array
    {
        $data = [
            'version' => $this->version,
            'action' => $this->action,
            'media' => ['duration_ms' => $this->durationMs],
            'scenes' => $this->scenes,
            'configuration' => $this->configuration,
        ];

        if ($this->transcriptSegments !== null) {
            $data['transcript_segments'] = $this->transcriptSegments;
        }

        return $data;
    }

    /**
     * Serialize the contract for metadata-only (rank_clips) transport.
     *
     * Produces a privacy-safe payload with only timing, candidates, and prototype query,
     * omitting legacy storage/project/identity fields.
     *
     * @return array{version: string, action: string, media: array{duration_ms: int}, candidates: array, configuration: array}
     */
    public function toRankClipsMetadataArray(): array
    {
        $data = [
            'version' => $this->version,
            'action' => $this->action,
            'media' => ['duration_ms' => $this->durationMs],
            'candidates' => $this->candidates,
            'configuration' => $this->configuration,
        ];

        return $data;
    }

    /**
     * Validate the contract.
     */
    public function validate(): bool
    {
        // Validate semver format
        if (! preg_match('/^\d+\.\d+\.\d+$/', $this->version)) {
            return false;
        }

        // Validate action is valid
        if (! in_array($this->action, ['probe', 'extract_audio', 'transcribe', 'detect_scenes', 'analyze_clips', 'rank_clips'], true)) {
            return false;
        }

        // analyze_clips uses metadata-only shape — skip legacy field validation.
        if ($this->action === 'analyze_clips') {
            try {
                ClipAnalysisValidator::request($this->toMetadataArray());
            } catch (ProcessMediaException) {
                return false;
            }

            return true;
        }

        // rank_clips uses metadata-only shape with candidates.
        if ($this->action === 'rank_clips') {
            try {
                ClipRecommendationValidator::request($this->toRankClipsMetadataArray());
            } catch (ProcessMediaException) {
                return false;
            }

            return true;
        }

        // Legacy actions (probe, extract_audio, transcribe, detect_scenes) require full envelope.

        // Validate required fields are present and non-empty
        if (! isset($this->mediaAssetId) || $this->mediaAssetId < 1 || ! isset($this->projectId) || $this->projectId < 1) {
            return false;
        }

        // Validate storage
        if (empty($this->storage) || empty($this->storage['disk']) || empty($this->storage['key']) || empty($this->storage['mime_type'])) {
            return false;
        }

        // Validate idempotency key is valid UUID
        if (! isset($this->idempotencyKey) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this->idempotencyKey)) {
            return false;
        }

        // Validate created_at is present
        if (! isset($this->createdAt) || empty($this->createdAt)) {
            return false;
        }

        // If action is extract_audio, output_storage must be present
        if ($this->action === 'extract_audio') {
            if (empty($this->outputStorage) || empty($this->outputStorage['disk']) || empty($this->outputStorage['key']) || empty($this->outputStorage['mime_type'])) {
                return false;
            }
        }

        // If action is transcribe, derived_asset_id must be present
        if ($this->action === 'transcribe') {
            if (! isset($this->derivedAssetId) || $this->derivedAssetId < 1) {
                return false;
            }
        }

        // If action is detect_scenes, durationMs must be present and > 0
        if ($this->action === 'detect_scenes') {
            if (! isset($this->durationMs) || ! is_int($this->durationMs) || $this->durationMs <= 0) {
                return false;
            }
        }

        return true;
    }
}
