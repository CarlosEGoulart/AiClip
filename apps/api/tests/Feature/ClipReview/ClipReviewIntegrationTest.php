<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->mediaAsset = MediaAsset::factory()->create(['project_id' => $this->project->id]);
    $this->derivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
    ]);
});

it('DerivedAsset has clipReview relationship', function () {
    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]);

    $this->derivedAsset->load('clipReview');
    expect($this->derivedAsset->clipReview)->toBeInstanceOf(ClipReview::class);
    expect($this->derivedAsset->clipReview->id)->toBe($clipReview->id);
});

it('DerivedAsset scopeWithReviewStatus loads clip review with reviewer', function () {
    ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]);

    $derivedAssets = DerivedAsset::withReviewStatus()->where('id', $this->derivedAsset->id)->get();
    expect($derivedAssets->first()->clipReview)->toBeInstanceOf(ClipReview::class);
    expect($derivedAssets->first()->clipReview->reviewer)->toBeInstanceOf(User::class);
});

it('ClipReview forProject scope works with DerivedAsset renderedClips scope', function () {
    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]);

    // Query via ClipReview forProject scope
    $reviews = ClipReview::forProject($this->project->id)->get();
    expect($reviews->count())->toBe(1);
    expect($reviews->first()->id)->toBe($clipReview->id);
});

it('ClipReview pending scope filters correctly', function () {
    ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::PENDING,
    ]);

    $approvedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
    ]);
    ClipReview::factory()->create([
        'derived_asset_id' => $approvedAsset->id,
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::APPROVED,
    ]);

    $pendingReviews = ClipReview::pending()->get();
    expect($pendingReviews->count())->toBe(1);
    expect($pendingReviews->first()->status)->toBe(ClipReviewStatus::PENDING);
});

it('unique constraint prevents duplicate reviews per user per clip', function () {
    ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]);

    expect(fn () => ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]))->toThrow(QueryException::class);
});

it('different users can review same clip', function () {
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->create(['user_id' => $otherUser->id]);
    $otherMediaAsset = MediaAsset::factory()->create(['project_id' => $otherProject->id]);
    $otherDerivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $otherMediaAsset->id,
    ]);

    // Same derived asset can't have two reviews from different users due to unique constraint
    // Wait - the unique constraint is on [derived_asset_id, reviewer_id]
    // So different users CAN review the same clip
    // Let me test this differently

    ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]);

    // This should work - different reviewer
    $review2 = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $otherUser->id,
    ]);

    expect($review2->id)->toBeInt();
});
