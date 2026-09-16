<?php

namespace App\Contracts;

use App\Models\MediaAsset;

class MediaProcessingContract
{
    public string $version = '1.0.0';

    public int $mediaAssetId;

    public int $projectId;

    /** @var array{disk: string, key: string, mime_type: string} */
    public array $storage;

    public string $idempotencyKey;

    public string $createdAt;

    /**
     * Create a contract from a MediaAsset model.
     */
    public static function fromMediaAsset(MediaAsset $asset, string $idempotencyKey): self
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

        return $contract;
    }

    /**
     * Create a contract from an array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $contract = new self;
        $contract->version = $data['version'];
        $contract->mediaAssetId = $data['media_asset_id'];
        $contract->projectId = $data['project_id'];
        $contract->storage = $data['storage'];
        $contract->idempotencyKey = $data['idempotency_key'];
        $contract->createdAt = $data['created_at'];

        return $contract;
    }

    /**
     * Serialize the contract to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'media_asset_id' => $this->mediaAssetId,
            'project_id' => $this->projectId,
            'storage' => $this->storage,
            'idempotency_key' => $this->idempotencyKey,
            'created_at' => $this->createdAt,
        ];
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

        return true;
    }
}
