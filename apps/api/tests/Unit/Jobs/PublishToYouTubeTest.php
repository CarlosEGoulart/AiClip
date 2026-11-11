<?php

namespace Tests\Unit\Jobs;

use App\Enums\ClipReviewStatus;
use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToYouTube;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialPublish;
use App\Models\User;
use Tests\Support\CreatesTestUsers;
use Tests\TestCase;

uses(TestCase::class, CreatesTestUsers::class);

it('job_implements_should_queue', function () {
    $job = new PublishToYouTube(1);

    expect($job)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
});

it('job_has_tries_and_backoff', function () {
    $job = new PublishToYouTube(1);

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(5);
});

it('unique_id_based_on_publish_id', function () {
    $job = new PublishToYouTube(42);

    expect($job->uniqueId())->toBe('social-publish:42');
});

it('null_social_connection_fails_without_throwing', function () {
    $this->user = User::factory()->create();

    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::APPROVED,
    ]);

    // social_connection_id references a non-existent connection
    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => 999999,
        'clip_review_id' => $clipReview->id,
        'platform' => 'youtube',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $job = new PublishToYouTube($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->toBe('Ownership validation failed');
});
