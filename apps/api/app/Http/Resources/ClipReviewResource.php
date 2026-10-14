<?php

namespace App\Http\Resources;

use App\Enums\ClipReviewStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClipReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $derivedAsset = $this->whenLoaded('derivedAsset');

        return [
            'id' => $this->id,
            'derived_asset_id' => $this->derived_asset_id,
            'status' => $this->status instanceof ClipReviewStatus
                ? $this->status->value
                : $this->status,
            'feedback' => $this->feedback,
            'video_url' => $this->videoUrl(),
            'thumbnail_url' => $this->thumbnailUrl(),
            'duration_ms' => $derivedAsset?->duration_ms,
            'width' => $derivedAsset?->width,
            'height' => $derivedAsset?->height,
            'candidate_index' => $derivedAsset?->candidate_index,
            'render_profile_version' => $derivedAsset?->render_profile_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function videoUrl(): string
    {
        return route('clip-review.video', ['clipReview' => $this->id], true);
    }

    private function thumbnailUrl(): ?string
    {
        // Thumbnail generation is out of scope for this slice
        // Field is present but null for now
        return null;
    }
}