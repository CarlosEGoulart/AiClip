<?php

namespace Tests\Unit\Jobs;

use App\Contracts\PublishResult;
use App\Contracts\PublishStatus;
use App\Contracts\SocialPublisher;
use App\Enums\ClipReviewStatus;
use App\Enums\SocialPublishStatus;
use App\Jobs\PublishToTikTok;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\SocialConnection;
use App\Models\SocialPublish;
use App\Models\User;
use App\Services\SocialConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Tests\Support\CreatesTestUsers;

uses(TestCase::class, RefreshDatabase::class, CreatesTestUsers::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('job_implements_should_queue', function () {
    $job = new PublishToTikTok(1);

    expect($job)->toBeInstanceOf(ShouldQueue::class);
});

it('job_has_tries_and_backoff', function () {
    $job = new PublishToTikTok(1);

    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe(5);
});

it('unique_id_based_on_publish_id', function () {
    $job = new PublishToTikTok(42);

    expect($job->uniqueId())->toBe('social-publish:42');
});

it('publish_not_found_logs_warning_and_returns_without_exception', function () {
    $job = new PublishToTikTok(999999);

    $job->handle();

    expect(true)->toBeTrue();
});

it('non_pending_publish_is_skipped_idempotency', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Uploading->value,
        'title' => 'Test Clip',
    ]);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Uploading);
    expect($publish->error_message)->toBeNull();
});

it('ownership_validation_failed_when_connection_user_id_mismatch', function () {
    $other = $this->createSecondUser();
    $connection = SocialConnection::factory()->for($other)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->toBe('Ownership validation failed');
});

it('connection_platform_not_tiktok_fails', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'youtube']);
    $clipReview = $this->createPublishableClipReview();

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->toBe('Connection is not a TikTok connection');
});

it('no_valid_access_token_fails_with_sanitized_message', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturnNull();
    $this->app->instance(SocialConnectionManager::class, $manager);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->toBe('No valid access token available');
});

it('no_completed_rendered_clip_fails_with_sanitized_message', function () {
    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = ClipReview::factory()->for(DerivedAsset::factory()->create())->create([
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::APPROVED,
    ]);

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->toBe('No completed rendered clip found');
});

it('success_path_transitions_pending_to_uploading_to_published', function () {
    Storage::fake('media');

    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturn('fake_tiktok_access_token');
    $this->app->instance(SocialConnectionManager::class, $manager);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Published);
    expect($publish->external_video_id)->toBe('tiktok_video_12345');
    expect($publish->video_url)->toBe('https://www.tiktok.com/@testaccount/video/tiktok_video_12345');
    expect($publish->error_message)->toBeNull();
});

it('publisher_returns_failed_transitions_to_failed_with_sanitized_error', function () {
    Storage::fake('media');

    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturn('fake_tiktok_access_token');
    $this->app->instance(SocialConnectionManager::class, $manager);

    $fakePublisher = new class implements SocialPublisher {
        public function getPlatform(): string
        {
            return 'tiktok';
        }

        public function getPlatformLabel(): string
        {
            return 'TikTok';
        }

        public function publishVideo(array $input): PublishResult
        {
            return new PublishResult(
                externalVideoId: '',
                videoUrl: '',
                status: 'failed',
                errorMessage: 'Simulated TikTok publish failure',
            );
        }

        public function getPublishStatus(string $externalVideoId): PublishStatus
        {
            return new PublishStatus(externalVideoId: '', status: 'failed');
        }

        public function hasPublishPermission(string $accessToken): bool
        {
            return false;
        }
    };

    $this->app->instance(SocialPublisher::class.':tiktok', $fakePublisher);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->toBe('Simulated TikTok publish failure');
});

it('publisher_throws_exception_transitions_to_failed_without_leaking_details', function () {
    Storage::fake('media');

    $connection = SocialConnection::factory()->for($this->user)->connected()->create(['platform' => 'tiktok']);
    $clipReview = $this->createPublishableClipReview();

    $publish = SocialPublish::query()->create([
        'user_id' => $this->user->id,
        'social_connection_id' => $connection->id,
        'clip_review_id' => $clipReview->id,
        'platform' => 'tiktok',
        'status' => SocialPublishStatus::Pending->value,
        'title' => 'Test Clip',
    ]);

    $manager = Mockery::mock(SocialConnectionManager::class);
    $manager->shouldReceive('getValidAccessToken')->andReturn('fake_tiktok_access_token');
    $this->app->instance(SocialConnectionManager::class, $manager);

    $fakePublisher = Mockery::mock(SocialPublisher::class);
    $fakePublisher->shouldReceive('publishVideo')->andThrow(new \RuntimeException('boom secret token abc123'));
    $this->app->instance(SocialPublisher::class.':tiktok', $fakePublisher);

    $job = new PublishToTikTok($publish->id);
    $job->handle();

    $publish->refresh();
    expect($publish->status)->toBe(SocialPublishStatus::Failed);
    expect($publish->error_message)->toBe('Publish failed');
    expect(strtolower($publish->error_message))->not->toContain('secret');
    expect(strtolower($publish->error_message))->not->toContain('token');
    expect($publish->error_message)->not->toContain('boom');
});
