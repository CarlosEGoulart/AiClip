<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublishClipRequest;
use App\Http\Resources\SocialPublishCollection;
use App\Http\Resources\SocialPublishResource;
use App\Enums\ClipReviewStatus;
use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToYouTube;
use App\Models\ClipReview;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SocialPublishController extends Controller
{
    /**
     * Initiate publishing a clip to a social platform.
     */
    public function store(PublishClipRequest $request): JsonResponse
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

        // Find connected YouTube connection for this user
        $connection = SocialConnection::where('user_id', $user->id)
            ->where('platform', 'youtube')
            ->where('status', 'connected')
            ->first();

        if (! $connection) {
            return response()->json([
                'message' => 'No connected YouTube account found',
            ], Response::HTTP_NOT_FOUND);
        }

        // Check for duplicate publish
        $existingPublish = SocialPublish::where('clip_review_id', $clipReview->id)
            ->first();

        if ($existingPublish) {
            return response()->json([
                'message' => 'A publish already exists for this clip review',
            ], Response::HTTP_CONFLICT);
        }

        // Create publish record
        $publish = SocialPublish::create([
            'user_id' => $user->id,
            'social_connection_id' => $connection->id,
            'clip_review_id' => $clipReview->id,
            'platform' => $validated['platform'],
            'status' => SocialPublishStatus::Pending->value,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
        ]);

        // Dispatch publish job
        PublishToYouTube::dispatch($publish->id);

        // Audit log (no tokens)
        Log::info('Publish initiated', [
            'user_id' => $user->id,
            'platform' => $validated['platform'],
            'clip_review_id' => $clipReview->id,
            'publish_id' => $publish->id,
        ]);

        return response()->json([
            'data' => [
                'publish_id' => $publish->id,
                'status' => $publish->status,
                'platform' => $publish->platform,
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * List the authenticated user's publishes.
     */
    public function index(Request $request): JsonResponse
    {
        $publishes = $request->user()
            ->socialPublishes()
            ->orderByDesc('created_at')
            ->paginate(15);

        return SocialPublishCollection::make($publishes)->response();
    }

    /**
     * Get publish status.
     */
    public function show(Request $request, SocialPublish $publish): JsonResponse
    {
        // Verify ownership (404 if not owned)
        if ($publish->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Publish not found',
            ], Response::HTTP_NOT_FOUND);
        }

        return SocialPublishResource::make($publish)->response();
    }
}
