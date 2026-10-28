<?php

namespace Tests\Support;

use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\User;
use App\Enums\ClipReviewStatus;

trait CreatesTestUsers
{
    protected function createSecondUser(): User
    {
        return User::factory()->create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'password123',
        ]);
    }

    /**
     * Create an approved clip review with a completed rendered clip whose
     * project is owned by the authenticated test user.
     *
     * The SocialPublishController verifies ownership through
     * derivedAsset.mediaAsset.project.user_id, so the factory chain must
     * resolve to $this->user rather than a factory-generated random user.
     */
    protected function createPublishableClipReview(array $attributes = []): ClipReview
    {
        $clipReview = ClipReview::factory()->for(
            DerivedAsset::factory()->renderedClip()
        )->create(array_merge([
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::APPROVED,
        ], $attributes));

        $clipReview->derivedAsset->mediaAsset->project->update([
            'user_id' => $this->user->id,
        ]);

        return $clipReview->refresh();
    }
}
