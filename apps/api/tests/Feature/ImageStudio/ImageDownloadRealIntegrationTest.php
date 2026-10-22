<?php

namespace Tests\Feature\ImageStudio;

use App\Http\Controllers\Api\V1\ImageController;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
 * exercises the configured real infrastructure (test-plan §3; AC-08).
 *
 * The availability guard mirrors tests/Feature/ClipReview/
 * ClipReviewRealIntegrationTest.php: full-suite runs stay graceful in
 * environments without MinIO by skipping via markTestSkipped, while the
 * gated CI invocation stays fail-closed there because --fail-on-skipped
 * converts a skip into a failure. The authoritative venue for this group
 * is the CI backend `tests` job, which provisions real PostgreSQL and
 * real MinIO.
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
        Storage::disk('media')->put('__image_download_connectivity_test__', 'ping');
        Storage::disk('media')->delete('__image_download_connectivity_test__');
    } catch (\Exception $e) {
        $this->markTestSkipped('MinIO not reachable: '.$e->getMessage());
    }

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    // Unique storage key so a real MinIO object can never collide with a
    // leftover object from a previous (failed) run of this file.
    $this->image = Image::factory()->generated()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'storage_key' => 'projects/'.$this->project->id.'/images/image-download-real-integration-'.Str::uuid().'.png',
    ]);
});

it('serves a real presigned MinIO URL that streams the exact stored PNG bytes', function () {
    // Real MinIO put of PNG-signature-prefixed bytes at the image's storage key.
    $bytes = "\x89PNG\r\n\x1a\n".'image-download-real-integration-payload-'.Str::uuid();
    Storage::disk('media')->put($this->image->storage_key, $bytes);

    try {
        $controller = app(ImageController::class);
        $request = Request::create('/api/v1/images/'.$this->image->id.'/download', 'GET');
        $request->setUserResolver(fn () => $this->user);

        $response = $controller->download($request, $this->image);

        expect($response->getStatusCode())->toBe(302);

        $location = $response->headers->get('Location');
        expect($location)->toBeString();

        // The redirect targets a presigned URL on the configured MinIO endpoint.
        $presigned = parse_url($location);
        $endpoint = parse_url((string) config('filesystems.disks.media.endpoint'));
        expect($presigned['host'] ?? null)->toBe($endpoint['host'] ?? null);
        expect($presigned['port'] ?? null)->toBe($endpoint['port'] ?? null);

        // The path contains the bucket and the image's storage key
        // (path-style endpoint, per AWS_USE_PATH_STYLE_ENDPOINT).
        $bucket = (string) config('filesystems.disks.media.bucket');
        expect($presigned['path'] ?? '')->toContain('/'.$bucket.'/'.$this->image->storage_key);

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
        Storage::disk('media')->delete($this->image->storage_key);
    }
})->group('real-integration');

it('no mocks for storage or queue in this file', function () {
    // This file contains no Storage::fake()/Queue::fake() (grep-verifiable).
    // Runtime proof that the real implementations are in use: the media disk
    // is backed by the real S3 adapter (a Storage::fake would substitute a
    // local in-memory adapter), and the queue connection is the pinned sync
    // driver.
    expect(Storage::disk('media'))->toBeInstanceOf(Filesystem::class);
    expect(Storage::disk('media')->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class);
    expect(config('queue.default'))->toBe('sync');
})->group('real-integration');
