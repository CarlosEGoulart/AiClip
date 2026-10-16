<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Events\ClipReviewCompleted;
use App\Http\Controllers\Api\V1\ClipReviewController;
use App\Jobs\ProcessClipReview;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Tests\TestCase;

/*
 * Group: real-integration
 *
 * Real Laravel -> real PostgreSQL -> real MinIO (S3 media disk) -> real
 * presigned HTTP round trip -> real sync queue. No Storage::fake, no
 * Queue::fake, no mocked adapters, no test doubles: every assertion
 * exercises the configured real infrastructure (test-plan §7.1; AC-12).
 *
 * The availability guard in beforeEach mirrors
 * tests/Feature/Media/MinIOIntegrationTest.php: full-suite runs stay
 * graceful in environments without MinIO by skipping via markTestSkipped,
 * while the gated command of test-plan §9 stays fail-closed there because
 * --fail-on-skipped converts a skip into a failure. The authoritative venue
 * for this group is the CI backend `tests` job, which provisions real
 * PostgreSQL and real MinIO.
 */

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Availability guard: skip when the real media infrastructure is absent.
    $disk = config('filesystems.disks.media');
    if ($disk === null || ($disk['driver'] ?? '') !== 's3') {
        $this->markTestSkipped('Media disk not configured as S3');
    }

    // Lightweight real connectivity probe (put/delete of a probe object).
    try {
        Storage::disk('media')->put('__clip_review_connectivity_test__', 'ping');
        Storage::disk('media')->delete('__clip_review_connectivity_test__');
    } catch (\Exception $e) {
        $this->markTestSkipped('MinIO not reachable: '.$e->getMessage());
    }

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->mediaAsset = MediaAsset::factory()->create(['project_id' => $this->project->id]);
    // Unique storage key so a real MinIO object can never collide with a
    // leftover object from a previous (failed) run of this file.
    $this->derivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
        'storage_key' => 'renders/clip-review-real-integration-'.Str::uuid().'.mp4',
    ]);
    $this->clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::PENDING,
    ]);
});

it('serves a real presigned MinIO URL that streams the exact stored bytes', function () {
    // Real MinIO put at the review's derived_asset->storage_key.
    $bytes = 'clip-review-real-integration-payload-'.Str::uuid();
    Storage::disk('media')->put($this->derivedAsset->storage_key, $bytes);

    try {
        $controller = app(ClipReviewController::class);
        $request = Request::create('/api/v1/clip-reviews/'.$this->clipReview->id.'/video', 'GET');
        $request->setUserResolver(fn () => $this->user);

        $response = $controller->video($request, $this->clipReview);

        expect($response->getStatusCode())->toBe(302);

        $location = $response->headers->get('Location');
        expect($location)->toBeString();

        // The redirect targets a presigned URL on the configured MinIO endpoint.
        $presigned = parse_url($location);
        $endpoint = parse_url((string) config('filesystems.disks.media.endpoint'));
        expect($presigned['host'] ?? null)->toBe($endpoint['host'] ?? null);
        expect($presigned['port'] ?? null)->toBe($endpoint['port'] ?? null);

        // The path contains the bucket and the review's storage key
        // (path-style endpoint, per AWS_USE_PATH_STYLE_ENDPOINT).
        $bucket = (string) config('filesystems.disks.media.bucket');
        expect($presigned['path'] ?? '')->toContain('/'.$bucket.'/'.$this->derivedAsset->storage_key);

        // The query carries the AWS presign parameters.
        $query = [];
        parse_str($presigned['query'] ?? '', $query);
        expect($query)->toHaveKey('X-Amz-Signature');
        expect($query)->toHaveKey('X-Amz-Expires');

        // A real HTTP GET of the presigned URL returns the exact bytes written.
        $download = Http::timeout(30)->get($location);
        expect($download->status())->toBe(200);
        expect($download->body())->toBe($bytes);
    } finally {
        // Cleanup: remove the real object even when an assertion fails.
        Storage::disk('media')->delete($this->derivedAsset->storage_key);
    }
})->group('real-integration');

it('real database transaction wraps status update', function () {
    // Test that the job uses database transaction
    $job = new ProcessClipReview($this->clipReview->id, 'approve');
    $job->handle();

    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);
})->group('real-integration');

it('rolls back the status update and dispatches no event when the transaction fails mid-way', function () {
    // A real listener on the real dispatcher records whether the completion
    // event fires. Event::fake() is a test double and is forbidden in this
    // file (test-plan §7.1; AC-12).
    $completedEvents = [];
    Event::listen(ClipReviewCompleted::class, function (ClipReviewCompleted $event) use (&$completedEvents) {
        $completedEvents[] = $event;
    });

    $feedbackBefore = $this->clipReview->feedback;
    $derivedAssetRenderStatusBefore = $this->derivedAsset->refresh()->render_status;

    // Real-database failure injection (no mocks/fakes): a PostgreSQL trigger
    // whose function raises an exception on any clip_reviews UPDATE. The
    // job's inner update then throws a real QueryException inside its
    // DB::transaction. DDL is transactional in PostgreSQL and the test runs
    // inside RefreshDatabase's outer transaction, so the trigger cannot
    // leak beyond this test.
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION clip_reviews_reject_update() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'injected rollback failure: clip_reviews update rejected';
        END;
        $$ LANGUAGE plpgsql;
        SQL);

    DB::unprepared('DROP TRIGGER IF EXISTS clip_reviews_reject_update ON clip_reviews;');
    DB::unprepared('CREATE TRIGGER clip_reviews_reject_update BEFORE UPDATE ON clip_reviews FOR EACH ROW EXECUTE FUNCTION clip_reviews_reject_update();');

    // The exception propagates to the caller through the real sync queue.
    expect(function () {
        ProcessClipReview::dispatch($this->clipReview->id, 'approve');
    })->toThrow(QueryException::class, 'injected rollback failure');

    // The failed transaction left no partial state behind.
    expect($this->clipReview->refresh()->status)->toBe(ClipReviewStatus::PENDING);
    expect($this->clipReview->feedback)->toBe($feedbackBefore);
    expect($this->derivedAsset->refresh()->render_status)->toBe($derivedAssetRenderStatusBefore);

    // The completion event was never dispatched.
    expect($completedEvents)->toBe([]);
})->group('real-integration');

it('real queue sync driver executes job synchronously', function () {
    ProcessClipReview::dispatch($this->clipReview->id, 'approve');

    // Sync driver executes inline, so state changes are visible immediately.
    $this->clipReview->refresh();
    expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);
})->group('real-integration');

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
    $job = new ProcessClipReview($otherClipReview->id, 'approve');
    $job->handle();

    $otherClipReview->refresh();
    expect($otherClipReview->status)->toBe(ClipReviewStatus::PENDING);
})->group('real-integration');

it('no mocks for storage or queue in integration tests', function () {
    // This file contains no Storage::fake()/Queue::fake() (grep-verifiable).
    // Runtime proof that the real implementations are in use: the media disk
    // is backed by the real S3 adapter (a Storage::fake would substitute a
    // local in-memory adapter), and the queue connection is the pinned sync
    // driver whose real inline execution the dispatch tests above prove.
    expect(Storage::disk('media'))->toBeInstanceOf(Filesystem::class);
    expect(Storage::disk('media')->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class);
    expect(config('queue.default'))->toBe('sync');
})->group('real-integration');
