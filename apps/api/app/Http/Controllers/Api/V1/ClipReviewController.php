<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClipReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ClipReviewFeedbackRequest;
use App\Http\Requests\ClipReviewStatusUpdateRequest;
use App\Http\Resources\ClipReviewCollection;
use App\Http\Resources\ClipReviewResource;
use App\Jobs\ProcessClipReview;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

        // Terminal-state guard: only pending reviews can transition.
        if ($clipReview->status !== ClipReviewStatus::PENDING) {
            return response()->json([
                'message' => 'Cannot transition from a non-pending status.',
            ], 422);
        }

        $status = $request->validated('status');
        $action = match ($status) {
            'approved' => 'approve',
            'rejected' => 'reject',
            'needs_changes' => 'needs_changes',
            default => throw new \InvalidArgumentException("Invalid status: {$status}"),
        };

        // Dispatch the queue job to process the review.
        // With QUEUE_CONNECTION=sync (test/e2e) the job runs inline during
        // dispatch(), so refresh() observes the new status immediately.
        ProcessClipReview::dispatch($clipReview->id, $action);

        $clipReview->refresh();

        return response()->json([
            'data' => new ClipReviewResource($clipReview),
        ]);
    }

    public function seed(Request $request): JsonResponse
    {
        if (! app()->environment('testing')) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
        ]);

        try {
            $project = Project::findOrFail($validated['project_id']);
        } catch (ModelNotFoundException $e) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // Enforce project ownership (non-owner → 404, no information leak)
        if ($project->user_id !== $request->user()->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $mediaAsset = MediaAsset::factory()->create([
            'project_id' => $project->id,
            'mime_type' => 'video/mp4',
            'status' => 'stored',
            'processing_status' => 'completed',
        ]);

        $derivedAsset = DerivedAsset::factory()->renderedClip()->create([
            'media_asset_id' => $mediaAsset->id,
        ]);

        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => $derivedAsset->id,
            'reviewer_id' => $request->user()->id,
            'status' => ClipReviewStatus::PENDING,
        ]);

        $clipReview->load(['derivedAsset.mediaAsset.project', 'reviewer']);

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

    public function video(Request $request, ClipReview $clipReview): RedirectResponse
    {
        if ($request->filled('signature')) {
            // Signed playback URLs are time-limited, owner-scoped tokens issued via
            // the clip review resource; an invalid, expired, or tampered signature is
            // rejected before any ownership or storage information is exposed.
            abort_unless($request->hasValidSignature(), Response::HTTP_FORBIDDEN);
        } else {
            $this->authorizeOwnership($request, $clipReview);
        }

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

            return redirect()->away($url);
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
