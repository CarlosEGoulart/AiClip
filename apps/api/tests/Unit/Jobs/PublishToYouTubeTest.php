<?php

namespace Tests\Unit\Jobs;

use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToYouTube;
use App\Models\SocialPublish;
use Tests\TestCase;

uses(TestCase::class);

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
