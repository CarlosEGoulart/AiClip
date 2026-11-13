<?php

namespace Tests\Unit\Jobs;

use App\Enums\ClipReviewStatus;
use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToYouTube;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialConnection;
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

it('ownership_validation_failed_when_connection_user_id_mismatch', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $connection = SocialConnection::factory()->for($userB)->connected()->create([
        'platform' => 'youtube',
    ]);

    $clipReview = ClipReview::factory()->for(
        DerivedAsset::factory()->renderedClip()
    )->create([
        'reviewer_id' => $userA->id,
        'status' => ClipReviewStatus::APPROVED,
    ]);
    $clipReview->derivedAsset->mediaAsset->project->update([
        'user_id' => $userA->id,
    ]);

    $publish = SocialPublish::query()->create([
        'user_id' => $userA->id,
        'social_connection_id' => $connection->id,
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
