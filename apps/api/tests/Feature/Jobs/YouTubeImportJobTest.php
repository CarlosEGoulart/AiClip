<?php

namespace Tests\Feature\Jobs;

use App\Contracts\YouTubeImportProvider;
use App\Exceptions\InvalidInputException;
use App\Jobs\YouTubeImportJob;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use App\Services\FakeYouTubeImportProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * TC-BE-03 (test-plan §1.3): YouTubeImportJob — ownership re-validation,
 * non-pending idempotency, strict result validation, completed/failed
 * marking, and sync-queue observability. Runs inline through the sync
 * queue connection used by tests.
 */

/**
 * Spy/stub provider injected through the job's optional constructor
 * argument. Records every validateUrl/extractMetadata call and can
 * override the result or throw.
 */
class RecordingYouTubeProvider implements YouTubeImportProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $validateCalls = [];

    /** @var array<int, array<string, mixed>> */
    public array $extractCalls = [];

    public array $metadata = [
        'video_id' => 'dQw4w9WgXcQ',
        'title' => 'Fake YouTube Video',
        'duration_seconds' => 300,
        'thumbnail_url' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
    ];

    public ?\Throwable $exception = null;

    public function validateUrl(string $url): bool
    {
        $this->validateCalls[] = ['url' => $url];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return true;
    }

    public function extractMetadata(string $url): array
    {
        $this->extractCalls[] = ['url' => $url];

        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->metadata;
    }
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->otherUser = User::factory()->create();
    $this->otherProject = Project::factory()->create(['user_id' => $this->otherUser->id]);
});

it('marks a queued asset completed when the provider succeeds', function () {
    $provider = new RecordingYouTubeProvider;
    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

    $fresh = $asset->fresh();
    expect($fresh->status)->toBe(MediaAsset::PROCESSING_COMPLETED)
        ->and($fresh->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED)
        ->and($fresh->processing_error)->toBeNull()
        ->and($fresh->processing_completed_at)->not->toBeNull();
});

it('passes the persisted url to the provider', function () {
    $provider = new RecordingYouTubeProvider;
    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    (new YouTubeImportJob($asset->id, 'https://youtu.be/dQw4w9WgXcQ', (string) \Illuminate\Support\Str::uuid(), $this->user->id, $provider))->handle();

    expect($provider->validateCalls)->toHaveCount(1)
        ->and($provider->validateCalls[0])->toBe(['url' => 'https://youtu.be/dQw4w9WgXcQ'])
        ->and($provider->extractCalls)->toHaveCount(1)
        ->and($provider->extractCalls[0])->toBe(['url' => 'https://youtu.be/dQw4w9WgXcQ']);
});

it('is idempotent on a completed asset and never re-invokes the provider', function () {
    $provider = new RecordingYouTubeProvider;
    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_COMPLETED,
        'processing_status' => MediaAsset::PROCESSING_COMPLETED,
    ]);

    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();
    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

    $fresh = $asset->fresh();
    expect($provider->validateCalls)->toHaveCount(0)
        ->and($provider->extractCalls)->toHaveCount(0)
        ->and($fresh->processing_status)->toBe(MediaAsset::PROCESSING_COMPLETED);
});

foreach (['completed', 'failed'] as $status) {
    it("returns early on a {$status} asset without calling the provider", function () use ($status) {
        $provider = new RecordingYouTubeProvider;
        $asset = MediaAsset::factory()->create([
            'project_id' => $this->project->id,
            'status' => $status,
            'processing_status' => $status,
        ]);

        (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

        $fresh = $asset->fresh();
        expect($provider->validateCalls)->toHaveCount(0)
            ->and($provider->extractCalls)->toHaveCount(0)
            ->and($fresh->processing_status)->toBe($status);
    });
}

it('skips assets whose project is owned by another user, leaving them queued', function () {
    $provider = new RecordingYouTubeProvider;
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs) {
        $logs[] = $event;
    });

    $asset = MediaAsset::factory()->create([
        'project_id' => $this->otherProject->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    // Job runs with $this->user->id, but the project belongs to $this->otherUser.
    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

    $fresh = $asset->fresh();
    expect($provider->validateCalls)->toHaveCount(0)
        ->and($provider->extractCalls)->toHaveCount(0)
        ->and($fresh->processing_status)->toBe(MediaAsset::PROCESSING_QUEUED);

    $warnings = array_values(array_filter(
        $logs,
        fn (MessageLogged $event) => $event->level === 'warning',
    ));
    expect($warnings)->toHaveCount(1);
});

it('throws invalid input for a missing media asset id', function () {
    (new YouTubeImportJob(999999999, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) \Illuminate\Support\Str::uuid(), $this->user->id))->handle();
})->throws(InvalidInputException::class);

it('marks the asset failed when the provider throws', function () {
    $provider = new RecordingYouTubeProvider;
    $provider->exception = new RuntimeException('provider exploded with secret url details');

    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

    $fresh = $asset->fresh();
    expect($fresh->status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_error)->toBe('import_failed');
});

it('marks the asset failed when the provider result is missing video_id', function () {
    $provider = new RecordingYouTubeProvider;
    $provider->metadata = ['title' => 'No video id'];

    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

    $fresh = $asset->fresh();
    expect($fresh->status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_error)->toBe('import_failed');
});

it('marks the asset failed when the provider result is missing title', function () {
    $provider = new RecordingYouTubeProvider;
    $provider->metadata = ['video_id' => 'dQw4w9WgXcQ'];

    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

    $fresh = $asset->fresh();
    expect($fresh->status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_error)->toBe('import_failed');
});

it('marks the asset failed when the URL does not pass provider validation', function () {
    $rejectingProvider = new class implements YouTubeImportProvider {
        public function validateUrl(string $url): bool { return false; }
        public function extractMetadata(string $url): array { return []; }
    };

    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    (new YouTubeImportJob($asset->id, 'https://example.com/video', (string) Str::uuid(), $this->user->id, $rejectingProvider))->handle();

    $fresh = $asset->fresh();
    expect($fresh->status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_status)->toBe(MediaAsset::PROCESSING_FAILED)
        ->and($fresh->processing_error)->toBe('invalid_url')
        ->and($rejectingProvider instanceof YouTubeImportProvider)->toBeTrue();
});

it('logs sanitized failure reasons without url content or provider internals', function () {
    $provider = new RecordingYouTubeProvider;
    $provider->exception = new RuntimeException('boom containing the url: https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs) {
        $logs[] = $event;
    });

    (new YouTubeImportJob($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id, $provider))->handle();

    expect($asset->fresh()->status)->toBe(MediaAsset::PROCESSING_FAILED);

    $warnings = array_values(array_filter(
        $logs,
        fn (MessageLogged $event) => $event->level === 'warning',
    ));
    expect($warnings)->not->toBeEmpty();

    foreach ($warnings as $warning) {
        $rendered = json_encode([$warning->message, $warning->context]);
        expect($rendered)->not->toContain('https://www.youtube.com/watch?v=dQw4w9WgXcQ')
            ->and($rendered)->not->toContain('boom');
    }
});

it('generates inline through the sync queue connection used by tests and e2e', function () {
    Queue::fake();
    $asset = MediaAsset::factory()->create([
        'project_id' => $this->project->id,
        'status' => MediaAsset::PROCESSING_QUEUED,
        'processing_status' => MediaAsset::PROCESSING_QUEUED,
    ]);

    YouTubeImportJob::dispatch($asset->id, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', (string) Str::uuid(), $this->user->id);

    Queue::assertPushed(YouTubeImportJob::class, function (YouTubeImportJob $job) use ($asset) {
        return $job->mediaAssetId === $asset->id;
    });
    Queue::assertPushedTimes(YouTubeImportJob::class, 1);
});

it('exposes the queue idempotency identity and retry policy', function () {
    $job = new YouTubeImportJob(42, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'key-123', 7);

    expect($job->uniqueId())->toBe('youtube-import:42')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(5);
});
