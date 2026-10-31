<?php

namespace App\Http\Resources;

use App\Enums\SocialPublishStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialPublishResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $publish = $this->resource;

        return [
            'id' => $publish->id,
            'platform' => $publish->platform,
            'platform_label' => $this->getPlatformLabel($publish->platform),
            'status' => $publish->status,
            'external_video_id' => $publish->external_video_id,
            'video_url' => $publish->video_url,
            'title' => $publish->title,
            'description' => $publish->description,
            'error_message' => $publish->error_message,
            'created_at' => $publish->created_at?->toIso8601String(),
            'updated_at' => $publish->updated_at?->toIso8601String(),
        ];
    }

    private function getPlatformLabel(string $platform): string
    {
        return match ($platform) {
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            default => ucfirst($platform),
        };
    }
}
