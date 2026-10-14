<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClipReviewFeedbackRequest;
use App\Http\Requests\ClipReviewStatusUpdateRequest;
use App\Http\Resources\ClipReviewCollection;
use App\Http\Resources\ClipReviewResource;
use App\Jobs\ProcessClipReview;
use App\Models\ClipReview;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ClipReviewController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeOwnership($request, $project);

        $clipReviews = ClipReview::forProject($project->id)
            ->with(['derivedAsset', 'reviewer'])
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => new ClipReviewCollection($clipReviews),
        ]);
    }

    public function show(Request $request, ClipReview $clipReview): JsonResponse
    {
        $this->authorizeOwnership($request, $clipReview);

        $clipReview->load(['derivedAsset.mediaAsset.project', 'reviewer']);

        return response()->json([
            'data' => new ClipReviewResource($clipReview),
        ]);
    }

    public function updateStatus(ClipReviewStatusUpdateRequest $request, ClipReview $clipReview): JsonResponse
    {
        $this->authorizeOwnership($request, $clipReview);

        $status = $request->validated('status');
        $action = match ($status) {
            'approved' => 'approve',
            'rejected' => 'reject',
            'needs_changes' => 'needs_changes',
            default => throw new \InvalidArgumentException("Invalid status: {$status}"),
        };

        // Dispatch the queue job to process the review
        ProcessClipReview::dispatch($clipReview->id, $action);

        // Return the current state (job will update asynchronously)
        $clipReview->refresh();

        return response()->json([
            'data' => new ClipReviewResource($clipReview),
        ]);
    }

    public function storeFeedback(ClipReviewFeedbackRequest $request, ClipReview $clipReview): JsonResponse
    {
        $this->authorizeOwnership($request, $clipReview);

        $clipReview->update([
            'feedback' => $request->validated('feedback'),
        ]);

        return response()->json([
            'data' => new ClipReviewResource($clipReview->refresh()),
        ]);
    }

    public function video(Request $request, ClipReview $clipReview): Response
    {
        $this->authorizeOwnership($request, $clipReview);

        $clipReview->load('derivedAsset');

        $derivedAsset = $clipReview->derivedAsset;

        if (! $derivedAsset) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Generate a temporary signed URL to the storage (1 hour expiry)
        try {
            $disk = Storage::disk(config('media.disk', 'media'));
            $url = $disk->temporaryUrl(
                $derivedAsset->storage_key,
                now()->addHour()
            );

            return response()->redirectTo($url);
        } catch (\Exception $e) {
            Log::error('Failed to generate signed video URL', [
                'clip_review_id' => $clipReview->id,
                'derived_asset_id' => $derivedAsset->id,
                'error' => $e->getMessage(),
            ]);

            abort(Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function authorizeOwnership(Request $request, ClipReview|Project $resource): void
    {
        $user = $request->user();

        if (! $user) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $ownerId = match (true) {
            $resource instanceof ClipReview => $resource->derivedAsset->mediaAsset->project->user_id,
            $resource instanceof Project => $resource->user_id,
            default => null,
        };

        if ($ownerId !== $user->id) {
            abort(Response::HTTP_NOT_FOUND);
        }
    }
}