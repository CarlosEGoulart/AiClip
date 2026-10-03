<?php

namespace App\Contracts;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Services\ClipAnalysisValidator;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationProjection;
use App\Services\ClipRecommendationValidator;
use App\Services\RenderProfile;
use App\Services\RenderValidator;
use App\Services\StorageKeyBuilder;

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

    /** @var array<int, array{index: int, start_ms: int, end_ms: int, m4_rank: int, m4_score: float|int, transcript_text: string}>|null */
    public ?array $candidates = null;

    /** @var array{candidates: array<int, array{index: int, start_ms: int, end_ms: int, semantic_rank: int, semantic_score: float|int|null}>, candidate_index: int}|null */
    public ?array $recommendation = null;

    public ?int $recommendationId = null;

    public ?int $candidateIndex = null;

    /** @var array{start_ms: int, end_ms: int}|null */
    public ?array $singularCandidate = null;

    /** @var array{disk: string, key: string, width: int, height: int, video_codec: string, audio_codec: string|null}|null */
    public ?array $sourceMedia = null;

    /** @var array{min_duration_ms: int, target_duration_ms: int, max_duration_ms: int, max_candidates: int, weights: array{duration_fit: int, speech_coverage: int, boundary_alignment: int}}|array<string, mixed>|null */
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
     * Build the exact singular render_clip request from authoritative local inputs.
     *
     * The M5 recommendation is re-read and validated before projection.
     * Validation happens strictly before any process is created.
     *
     * @param  int  $mediaAssetId  The media asset ID
     * @param  int  $durationMs
     * @param  array<string, mixed>  $recommendation  Completed M5 recommendation with candidates
     * @param  int  $recommendationId  The recommendation ID
     * @param  int  $candidateIndex  Explicitly selected candidate index
     * @param  array{disk: string, key: string, width: int, height: int, video_codec: string, audio_codec: string|null}  $sourceMedia
     * @param  int  $projectId  The project ID for output storage key
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException
     */
    public static function renderClipRequest(
        int $mediaAssetId,
        int $durationMs,
        array $recommendation,
        int $recommendationId,
        int $candidateIndex,
        array $sourceMedia,
        int $projectId
    ): array {
        // Validate recommendation has candidates and candidate_index is valid
        if (! isset($recommendation['recommendations']) || ! is_array($recommendation['recommendations'])) {
            throw new ProcessMediaException('Invalid recommendation: missing recommendations');
        }

        $candidates = $recommendation['recommendations'];
        if (count($candidates) === 0) {
            throw new ProcessMediaException('Recommendation has no candidates');
        }
        if ($candidateIndex < 0 || $candidateIndex >= count($candidates)) {
            throw new ProcessMediaException('candidate_index out of bounds');
        }

        // Extract the selected candidate
        $selectedCandidate = $candidates[$candidateIndex];

        // Validate selected candidate has non-null semantic_score
        if ($selectedCandidate['semantic_score'] === null) {
            throw new ProcessMediaException('Selected candidate must have non-null semantic_score');
        }

        // Build candidate timing at root level (singular format)
        $candidate = [
            'start_ms' => (int) $selectedCandidate['start_ms'],
            'end_ms' => (int) $selectedCandidate['end_ms'],
        ];

        // Build output storage key using project-scoped format
        $outputStorageKey = StorageKeyBuilder::renderClip(
            $projectId,
            $mediaAssetId,
            $candidateIndex,
            RenderProfile::RENDER_PROFILE_VERSION,
        );

        $outputStorage = [
            'disk' => $sourceMedia['disk'],
            'key' => $outputStorageKey,
            'mime_type' => 'video/mp4',
        ];

        // Build base request
        $request = [
            'version' => RenderValidator::CONTRACT_VERSION,
            'action' => RenderValidator::ACTION,
            'media' => ['duration_ms' => $durationMs],
            'candidate_index' => $candidateIndex,
            'candidate' => $candidate,
            'configuration' => RenderProfile::configuration(),
            'source_media' => $sourceMedia,
            'output_storage' => $outputStorage,
        ];

        // Add captions if transcript is available and enabled
        $configuration = RenderProfile::configuration();
        if ($configuration['captions']['enabled'] === true) {
            $transcript = \App\Models\MediaTranscript::where('media_asset_id', $mediaAssetId)
                ->where('status', \App\Models\MediaTranscript::STATUS_COMPLETED)
                ->first();

            if ($transcript !== null) {
                $candidateStartMs = $candidate['start_ms'];
                $candidateEndMs = $candidate['end_ms'];
                $projectedSegments = $transcript->projectSegmentsToCandidate($candidateStartMs, $candidateEndMs);

                if (! empty($projectedSegments)) {
                    $request['captions'] = [
                        'enabled' => true,
                        'segments' => $projectedSegments,
                    ];
                }
            }
        }

        return $request;
    }

    /**
     * Build the exact rank_clips request from authoritative local inputs.
     *
     * The M4 candidates and canonical transcript texts are used to build
     * the worker request. The configuration is validated against the
     * currently selected ranking profile.
     *
     * @param  int  $durationMs
     * @param  array<int, array{index: int, start_ms: int, end_ms: int, rank: int, score: float|int}>  $m4Candidates
     * @param  array<int, string>  $canonicalTexts
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException
     */
    public static function rankClipsRequest(
        int $durationMs,
        array $m4Candidates,
        array $canonicalTexts
    ): array {
        // Validate inputs
        if (count($m4Candidates) === 0) {
            throw new ProcessMediaException('No M4 candidates provided for ranking');
        }
        if (count($canonicalTexts) !== count($m4Candidates)) {
            throw new ProcessMediaException('Canonical texts count must match M4 candidates count');
        }

        // Build candidates for rank_clips request
        $candidates = [];
        foreach ($m4Candidates as $position => $m4Candidate) {
            $text = $canonicalTexts[$position] ?? '';
            $candidates[] = [
                'index' => $position,
                'start_ms' => (int) $m4Candidate['start_ms'],
                'end_ms' => (int) $m4Candidate['end_ms'],
                'm4_rank' => (int) $m4Candidate['rank'],
                'm4_score' => (float) $m4Candidate['score'],
                'transcript_text' => $text,
            ];
        }

        return self::fromArray([
            'version' => ClipRecommendationValidator::CONTRACT_VERSION,
            'action' => ClipRecommendationValidator::ACTION,
            'media' => ['duration_ms' => $durationMs],
            'candidates' => $candidates,
            'configuration' => ClipRankingProfile::configuration(),
        ])->toRankClipsMetadataArray();
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

        if (($data['action'] ?? null) === 'render_clips') {
            $data = RenderValidator::request($data);
            $contract = new self;
            $contract->action = 'render_clips';
            $contract->version = $data['version'];
            $contract->mediaAssetId = $data['media_asset_id'];
            $contract->recommendationId = $data['recommendation_id'];
            $contract->durationMs = $data['media']['duration_ms'];
            $contract->recommendation = $data['recommendation'];
            $contract->candidateIndex = $data['candidate_index'];
            $contract->configuration = $data['configuration'];
            $contract->sourceMedia = $data['source_media'];

            return $contract;
        }

        if (($data['action'] ?? null) === 'render_clip') {
            $data = RenderValidator::request($data);
            $contract = new self;
            $contract->action = 'render_clip';
            $contract->version = $data['version'];
            $contract->durationMs = $data['media']['duration_ms'];
            $contract->candidateIndex = $data['candidate_index'];
            // Store singular candidate timing directly (not in recommendation array)
            $contract->singularCandidate = [
                'start_ms' => $data['candidate']['start_ms'],
                'end_ms' => $data['candidate']['end_ms'],
            ];
            $contract->configuration = $data['configuration'];
            $contract->sourceMedia = $data['source_media'];
            $contract->outputStorage = $data['output_storage'];

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
     * Serialize the contract for metadata-only (render_clips) transport.
     *
     * Produces a privacy-safe payload with only timing, recommendation, candidate index,
     * configuration, and source media info, omitting legacy storage/project/identity fields.
     *
     * @return array{version: string, action: string, media: array{duration_ms: int}, recommendation: array, candidate_index: int, configuration: array, source_media: array, media_asset_id: int, recommendation_id: int}
     */
    public function toRenderClipsMetadataArray(): array
    {
        $data = [
            'version' => $this->version,
            'action' => $this->action,
            'media' => ['duration_ms' => $this->durationMs],
            'recommendation' => $this->recommendation,
            'candidate_index' => $this->candidateIndex,
            'configuration' => $this->configuration,
            'source_media' => $this->sourceMedia,
            'media_asset_id' => $this->mediaAssetId,
            'recommendation_id' => $this->recommendationId ?? 0,
        ];

        return $data;
    }

    /**
     * Serialize the contract for metadata-only (render_clip) transport.
     *
     * Produces a privacy-safe payload with singular format:
     * version, action, media.duration_ms, candidate_index, candidate{start_ms,end_ms},
     * configuration, source_media, output_storage.
     *
     * @return array{version: string, action: string, media: array{duration_ms: int}, candidate_index: int, candidate: array{start_ms: int, end_ms: int}, configuration: array, source_media: array, output_storage: array{disk: string, key: string, mime_type: string}}
     */
    public function toRenderClipMetadataArray(): array
    {
        $data = [
            'version' => $this->version,
            'action' => $this->action,
            'media' => ['duration_ms' => $this->durationMs],
            'candidate_index' => $this->candidateIndex,
            'candidate' => [
                'start_ms' => $this->singularCandidate['start_ms'],
                'end_ms' => $this->singularCandidate['end_ms'],
            ],
            'configuration' => $this->configuration,
            'source_media' => $this->sourceMedia,
            'output_storage' => $this->outputStorage,
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
        if (! in_array($this->action, ['probe', 'extract_audio', 'transcribe', 'detect_scenes', 'analyze_clips', 'rank_clips', 'render_clips', 'render_clip'], true)) {
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

        // render_clip uses singular metadata-only shape.
        if ($this->action === 'render_clip') {
            try {
                RenderValidator::request($this->toRenderClipMetadataArray());
            } catch (ProcessMediaException) {
                return false;
            }

            return true;
        }

        // render_clips uses metadata-only shape with recommendation and candidate_index.
        if ($this->action === 'render_clips') {
            try {
                RenderValidator::request($this->toRenderClipsMetadataArray());
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
