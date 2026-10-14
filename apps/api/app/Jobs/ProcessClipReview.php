<?php

namespace App\Jobs;

use App\Enums\ClipReviewStatus;
use App\Events\ClipReviewCompleted;
use App\Models\ClipReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessClipReview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $clipReviewId,
        public string $action, // 'approve', 'reject', 'needs_changes'
    ) {}

    public function handle(): void
    {
        $clipReview = ClipReview::with(['derivedAsset.mediaAsset.project', 'reviewer'])
            ->findOrFail($this->clipReviewId);

        // Re-validate ownership
        if ($clipReview->derivedAsset->mediaAsset->project->user_id !== $clipReview->reviewer_id) {
            Log::warning('ProcessClipReview: Ownership validation failed', [
                'clip_review_id' => $this->clipReviewId,
                'expected_user_id' => $clipReview->reviewer_id,
                'actual_user_id' => $clipReview->derivedAsset->mediaAsset->project->user_id,
            ]);
            return;
        }

        // Idempotency: only process if pending
        if ($clipReview->status !== ClipReviewStatus::PENDING) {
            Log::info('ProcessClipReview: Review already processed, skipping', [
                'clip_review_id' => $this->clipReviewId,
                'current_status' => $clipReview->status->value,
            ]);
            return;
        }

        $newStatus = match ($this->action) {
            'approve' => ClipReviewStatus::APPROVED,
            'reject' => ClipReviewStatus::REJECTED,
            'needs_changes' => ClipReviewStatus::NEEDS_CHANGES,
            default => throw new \InvalidArgumentException("Invalid action: {$this->action}"),
        };

        DB::transaction(function () use ($clipReview, $newStatus) {
            $clipReview->update(['status' => $newStatus]);

            // DerivedAsset render_status: approved clips are already 'completed' from render
            // Rejected/needs_changes keep 'completed' (clip exists but not approved for publish)
            // Future: needs_changes could trigger re-render job (out of scope)
        });

        event(new ClipReviewCompleted($clipReview));
    }
}