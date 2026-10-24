<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $connection = $this->resource;

        return [
            'id' => $connection->id,
            'platform' => $connection->platform,
            'platform_label' => $this->getPlatformLabel($connection->platform),
            'display_name' => $connection->display_name,
            'avatar_url' => $connection->avatar_url,
            'channel_url' => $connection->channel_url,
            'status' => $connection->status,
            'last_error' => $connection->last_error,
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }

    private function getPlatformLabel(string $platform): string
    {
        return match ($platform) {
            'youtube' => 'YouTube',
            'instagram' => 'Instagram',
            'tiktok' => 'TikTok',
            default => ucfirst($platform),
        };
    }
}
