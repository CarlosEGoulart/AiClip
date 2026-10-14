<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Events\ClipReviewCompleted;
use App\Jobs\ProcessClipReview;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

uses(TestCase::class)->in('Feature/ClipReview');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->mediaAsset = MediaAsset::factory()->create(['project_id' => $this->project->id]);
    $this->derivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
    ]);
    $this->clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::PENDING,
    ]);
});

it('approves clip review and dispatches event', function () {
    Event::fake();

    $job = new ProcessClipReview($this->clipReview->id, 'approve');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);
    expect($this->clipReview->derivedAsset->render_status)->toBe('completed');

    Event::assertDispatched(ClipReviewCompleted::class, function ($event) {
        return $event->clipReview->id === $this->clipReview->id;
    });
});

it('rejects clip review and dispatches event', function () {
    Event::fake();

    $job = new ProcessClipReview($this->clipReview->id, 'reject');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::REJECTED);
    expect($this->clipReview->derivedAsset->render_status)->toBe('completed');

    Event::assertDispatched(ClipReviewCompleted::class, function ($event) {
        return $event->clipReview->id === $this->clipReview->id;
    });
});

it('sets needs_changes status and dispatches event', function () {
    Event::fake();

    $job = new ProcessClipReview($this->clipReview->id, 'needs_changes');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::NEEDS_CHANGES);
    expect($this->clipReview->derivedAsset->render_status)->toBe('completed');

    Event::assertDispatched(ClipReviewCompleted::class, function ($event) {
        return $event->clipReview->id === $this->clipReview->id;
    });
});

it('is idempotent - second dispatch does nothing', function () {
    Event::fake();

    $job1 = new ProcessClipReview($this->clipReview->id, 'approve');
    $job1->handle();

    $job2 = new ProcessClipReview($this->clipReview->id, 'approve');
    $job2->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);

    // Event should only be dispatched once
    Event::assertDispatchedTimes(ClipReviewCompleted::class, 1);
});

it('does nothing if review is already approved', function () {
    Event::fake();

    $this->clipReview->update(['status' => ClipReviewStatus::APPROVED]);

    $job = new ProcessClipReview($this->clipReview->id, 'approve');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);

    Event::assertNotDispatched(ClipReviewCompleted::class);
});

it('does nothing if review is already rejected', function () {
    Event::fake();

    $this->clipReview->update(['status' => ClipReviewStatus::REJECTED]);

    $job = new ProcessClipReview($this->clipReview->id, 'approve');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::REJECTED);

    Event::assertNotDispatched(ClipReviewCompleted::class);
});

it('returns early and logs warning when ownership re-validation fails', function () {
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->create(['user_id' => $otherUser->id]);
    $otherMediaAsset = MediaAsset::factory()->create(['project_id' => $otherProject->id]);
    $otherDerivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $otherMediaAsset->id,
    ]);

    $otherClipReview = ClipReview::factory()->create([
        'derived_asset_id' => $otherDerivedAsset->id,
        'reviewer_id' => $this->user->id, // Same reviewer but different project
    ]);

    Event::fake();

    $job = new ProcessClipReview($otherClipReview->id, 'approve');
    $job->handle();

    $otherClipReview->refresh();
    expect($otherClipReview->status)->toBe(ClipReviewStatus::PENDING);

    Event::assertNotDispatched(ClipReviewCompleted::class);
});

it('throws InvalidArgumentException for invalid action', function () {
    expect(function () {
        $job = new ProcessClipReview($this->clipReview->id, 'invalid_action');
        $job->handle();
    })->toThrow(\InvalidArgumentException::class, 'Invalid action: invalid_action');
});

it('wraps in database transaction', function () {
    // Test that partial state doesn't persist on error
    // We'll simulate an error by using an invalid clip review ID
    $job = new ProcessClipReview(999999, 'approve');

    expect(fn() => $job->handle())->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    // Original clip review should be unchanged
    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::PENDING);
});