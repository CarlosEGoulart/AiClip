<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublishCampaignRequest;
use App\Http\Resources\SocialPublishCampaignCollection;
use App\Http\Resources\SocialPublishCampaignResource;
use App\Enums\ClipReviewStatus;
use App\Enums\SocialPublishCampaignStatus;
use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToInstagram;
use App\Jobs\PublishToTikTok;
use App\Jobs\PublishToYouTube;
use App\Models\ClipReview;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
use App\Models\SocialPublishCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SocialPublishCampaignController extends Controller
{
    /**
     * Platform to job class mapping.
     *
     * @var array<string, string>
     */
    protected array $jobMap = [
        'youtube' => PublishToYouTube::class,
        'instagram' => PublishToInstagram::class,
        'tiktok' => PublishToTikTok::class,
    ];

    /**
     * Create a multi-target publish campaign.
     *
     * Pre-execution validation checks ALL targets before queueing ANY.
     * Invalid targets are reported; valid targets are never silently dropped.
     */
    public function store(PublishCampaignRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        // Find clip review and verify ownership + publishable state
        $clipReview = ClipReview::with(['derivedAsset.mediaAsset.project'])
            ->where('id', $validated['clip_review_id'])
            ->whereHas('derivedAsset.mediaAsset.project', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->first();

        if (! $clipReview) {
            return response()->json([
                'message' => 'Clip review not found or not publishable',
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify review is approved
        if ($clipReview->status !== ClipReviewStatus::APPROVED) {
            return response()->json([
                'message' => 'Clip review is not approved',
            ], Response::HTTP_NOT_FOUND);
        }

        // Verify derived asset is a completed rendered clip
        $derivedAsset = $clipReview->derivedAsset;
        if (! $derivedAsset || $derivedAsset->type !== 'clip_rendered' || $derivedAsset->render_status !== 'completed') {
            return response()->json([
                'message' => 'No completed rendered clip found',
            ], Response::HTTP_NOT_FOUND);
        }

        // Pre-execution validation: check ALL targets before queueing ANY
        $targets = $validated['targets'];
        $validationErrors = [];
        $connections = [];

        foreach ($targets as $index => $target) {
            $platform = $target['platform'];

            $connection = SocialConnection::where('user_id', $user->id)
                ->where('platform', $platform)
                ->where('status', 'connected')
                ->first();

            if (! $connection) {
                $validationErrors["targets.{$index}"] = [
                    'No connected account found for ' . ucfirst($platform),
                ];
                continue;
            }

            $connections[$index] = $connection;
        }

        if (! empty($validationErrors)) {
            return response()->json([
                'message' => 'Validation failed. Please check all targets.',
                'errors' => $validationErrors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Create campaign
        $campaign = DB::transaction(function () use ($user, $clipReview) {
            return SocialPublishCampaign::create([
                'user_id' => $user->id,
                'clip_review_id' => $clipReview->id,
                'status' => SocialPublishCampaignStatus::Pending->value,
            ]);
        });

        // Create per-target SocialPublish records and queue jobs
        foreach ($targets as $index => $target) {
            $connection = $connections[$index];

            $publish = SocialPublish::create([
                'user_id' => $user->id,
                'social_connection_id' => $connection->id,
                'clip_review_id' => $clipReview->id,
                'campaign_id' => $campaign->id,
                'platform' => $target['platform'],
                'status' => SocialPublishStatus::Pending->value,
                'title' => $target['title'],
                'description' => $target['description'] ?? null,
            ]);

            $jobClass = $this->jobMap[$target['platform']];

            $jobClass::dispatch($publish->id, $campaign->id);

            Log::info('Per-target publish queued', [
                'user_id' => $user->id,
                'platform' => $target['platform'],
                'clip_review_id' => $clipReview->id,
                'publish_id' => $publish->id,
                'campaign_id' => $campaign->id,
            ]);
        }

        // Audit log (no tokens)
        Log::info('Campaign initiated', [
            'user_id' => $user->id,
            'clip_review_id' => $clipReview->id,
            'campaign_id' => $campaign->id,
            'target_count' => count($targets),
            'platforms' => array_column($targets, 'platform'),
        ]);

        $campaign->load('publishes');

        return SocialPublishCampaignResource::make($campaign)
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    /**
     * List the authenticated user's campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $campaigns = $request->user()
            ->socialPublishCampaigns()
            ->orderByDesc('created_at')
            ->paginate(15);

        return SocialPublishCampaignCollection::make($campaigns)->response();
    }

    /**
     * Get campaign status with per-target publishes.
     */
    public function show(Request $request, SocialPublishCampaign $campaign): JsonResponse
    {
        // Verify ownership (404 if not owned)
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Campaign not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $campaign->load('publishes');

        return SocialPublishCampaignResource::make($campaign)->response();
    }

    /**
     * Cancel a campaign (pending/processing). Cancels pending targets only.
     */
    public function cancel(Request $request, SocialPublishCampaign $campaign): JsonResponse
    {
        // Verify ownership (404 if not owned)
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Campaign not found',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! in_array($campaign->status->value, [SocialPublishCampaignStatus::Pending->value, SocialPublishCampaignStatus::Processing->value], true)) {
            return response()->json([
                'message' => 'Campaign cannot be cancelled',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($campaign) {
            // Cancel pending targets only; published/failed targets untouched
            $campaign->publishes()->pending()->update([
                'status' => SocialPublishStatus::Failed->value,
                'error_message' => 'Campaign cancelled by user',
            ]);

            $campaign->update([
                'status' => SocialPublishCampaignStatus::Cancelled->value,
            ]);
        });

        // Audit log (no tokens)
        Log::info('Campaign cancelled', [
            'user_id' => $request->user()->id,
            'campaign_id' => $campaign->id,
        ]);

        return response()->json([
            'data' => [
                'campaign_id' => $campaign->id,
                'status' => $campaign->status,
            ],
        ]);
    }

    /**
     * Retry failed targets of a campaign (idempotent, no duplicates).
     */
    public function retry(Request $request, SocialPublishCampaign $campaign): JsonResponse
    {
        // Verify ownership (404 if not owned)
        if ($campaign->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Campaign not found',
            ], Response::HTTP_NOT_FOUND);
        }

        $failedPublishes = $campaign->publishes()->failed()->get();

        if ($failedPublishes->isEmpty()) {
            return response()->json([
                'message' => 'Nothing to retry',
            ], Response::HTTP_CONFLICT);
        }

        $user = $request->user();

        foreach ($failedPublishes as $publish) {
            // Idempotency: only re-queue if still failed (pending-skip happens in job)
            if ($publish->status->value !== SocialPublishStatus::Failed->value) {
                continue;
            }

            $publish->update([
                'status' => SocialPublishStatus::Pending->value,
                'error_message' => null,
            ]);

            $jobClass = $this->jobMap[$publish->platform];

            $jobClass::dispatch($publish->id, $campaign->id);

            Log::info('Per-target publish retried', [
                'user_id' => $user->id,
                'platform' => $publish->platform,
                'clip_review_id' => $publish->clip_review_id,
                'publish_id' => $publish->id,
                'campaign_id' => $campaign->id,
            ]);
        }

        // Recompute campaign aggregate status
        $this->recomputeCampaignStatus($campaign);

        // Audit log (no tokens)
        Log::info('Campaign retry initiated', [
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'retry_count' => $failedPublishes->count(),
        ]);

        return response()->json([
            'data' => [
                'campaign_id' => $campaign->id,
                'status' => $campaign->fresh()->status,
                'retried_count' => $failedPublishes->count(),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Recompute the campaign aggregate status from its per-target publishes.
     */
    protected function recomputeCampaignStatus(SocialPublishCampaign $campaign): void
    {
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
    }
}
