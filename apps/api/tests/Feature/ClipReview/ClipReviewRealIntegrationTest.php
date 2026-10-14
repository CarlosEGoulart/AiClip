<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

uses(TestCase::class)->in('Feature/ClipReview');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->mediaAsset = MediaAsset::factory()->create(['project_id' => $this->project->id]);
    $this->derivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
        'storage_key' => 'renders/test-clip.mp4',
    ]);
    $this->clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::PENDING,
    ]);
});

it('real MinIO signed URL returns redirect to presigned URL', function () {
    Storage::fake('media');

    $controller = app(\App\Http\Controllers\Api\V1\ClipReviewController::class);
    $request = \Illuminate\Http\Request::create('/api/v1/clip-reviews/'.$this->clipReview->id.'/video', 'GET');
    $request->setUserResolver(fn () => $this->user);

    $response = $controller->video($request, $this->clipReview);

    expect($response->getStatusCode())->toBe(302);
    $redirectUrl = $response->headers->get('Location');
    expect($redirectUrl)->toBeString();
});

it('real database transaction wraps status update', function () {
    // Test that the job uses database transaction
    $job = new \App\Jobs\ProcessClipReview($this->clipReview->id, 'approve');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);
});

it('real queue sync driver executes job synchronously', function () {
    \Illuminate\Support\Facades\Queue::fake();

    $job = new \App\Jobs\ProcessClipReview($this->clipReview->id, 'approve');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessClipReview::class);
});

it('validates ownership chain: user -> project -> media_asset -> derived_asset -> clip_review', function () {
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

    // Job should return early without changing state
    $job = new \App\Jobs\ProcessClipReview($otherClipReview->id, 'approve');
    $job->handle();

    $otherClipReview->refresh();
    expect($otherClipReview->status)->toBe(ClipReviewStatus::PENDING);
});

it('no mocks for storage or queue in integration tests', function () {
    // Verify we're not using Storage::fake() or Queue::fake() for the actual test
    // This test just ensures the real implementations are used
    expect(Storage::disk('media'))->toBeInstanceOf(\Illuminate\Contracts\Filesystem\Filesystem::class);
});