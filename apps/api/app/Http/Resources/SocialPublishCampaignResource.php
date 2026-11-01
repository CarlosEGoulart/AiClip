<?php

namespace App\Http\Resources;

use App\Enums\SocialPublishCampaignStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialPublishCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $campaign = $this->resource;

        return [
            'id' => $campaign->id,
            'user_id' => $campaign->user_id,
            'clip_review_id' => $campaign->clip_review_id,
            'status' => $campaign->status,
            'status_label' => $campaign->status instanceof SocialPublishCampaignStatus
                ? $campaign->status->label()
                : SocialPublishCampaignStatus::from($campaign->status)->label(),
            'publishes' => $this->when($campaign->relationLoaded('publishes'), function () use ($campaign) {
                return SocialPublishResource::collection($campaign->publishes)
            }),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];
    }
}
