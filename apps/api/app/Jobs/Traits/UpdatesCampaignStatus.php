<?php

namespace App\Jobs\Traits;

use App\Enums\SocialPublishCampaignStatus;
use App\Enums\SocialPublishStatus;
use App\Models\SocialPublishCampaign;
use Illuminate\Support\Facades\Log;

trait UpdatesCampaignStatus
{
    /**
     * Recompute the campaign aggregate status from its per-target publishes.
     *
     * @param  int  $campaignId
     * @return void
     */
    protected function updateCampaignStatus(int $campaignId): void
    {
        $campaign = SocialPublishCampaign::find($campaignId);

        if (! $campaign) {
            return;
        }

        $publishes = $campaign->publishes;

        $total = $publishes->count();
        $publishedValue = SocialPublishStatus::Published->value;
        $failedValue = SocialPublishStatus::Failed->value;
        $pendingValue = SocialPublishStatus::Pending->value;
        $uploadingValue = SocialPublishStatus::Uploading->value;

        $publishedCount = $publishes->filter(fn ($p) => $p->status->value === $publishedValue)->count();
        $failedCount = $publishes->filter(fn ($p) => $p->status->value === $failedValue)->count();
        $pendingCount = $publishes->filter(fn ($p) => $p->status->value === $pendingValue)->count();
        $processingCount = $publishes->filter(fn ($p) => $p->status->value === $uploadingValue)->count();

        if ($pendingCount > 0 || $processingCount > 0) {
            $status = SocialPublishCampaignStatus::Processing->value;
        } elseif ($publishedCount === $total) {
            $status = SocialPublishCampaignStatus::Published->value;
        } elseif ($failedCount === $total) {
            $status = SocialPublishCampaignStatus::Failed->value;
        } elseif ($publishedCount > 0) {
            $status = SocialPublishCampaignStatus::PartiallyPublished->value;
        } else {
            $status = SocialPublishCampaignStatus::Failed->value;
        }

        $campaign->update(['status' => $status]);

        Log::info('Campaign aggregate status updated', [
            'campaign_id' => $campaignId,
            'status' => $status,
            'total' => $total,
            'published' => $publishedCount,
            'failed' => $failedCount,
            'pending' => $pendingCount,
            'processing' => $processingCount,
        ]);
    }
}
