<?php

namespace Tests\Feature\ImageStudio;

use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

/*
 * TC-BE-02 (test-plan §2): GET /api/v1/images/{image}/download — the dual
 * gate (valid signature -> 302 temporaryUrl redirect; invalid/expired/
 * tampered signature -> 403 before any ownership or storage information;
 * unsigned -> session ownership with guest 401, non-owner 404, non-existent
 * 404), the artifact guard (pending/failed -> 404), and the fail-closed
 * storage-error path (500 with a sanitized log and a body that leaks no
 * storage key, bucket, or prompt text). Every 302-expecting case fakes the
 * media disk first: the vendor temporary-URL callback makes temporaryUrl()
 * deterministic and offline.
 */

beforeEach(function () {
    $this->disk = config('media.disk', 'media');

    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->otherUser = User::factory()->create();

    $this->image = Image::factory()->generated()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);
    $this->otherUserImage = Image::factory()->generated()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

function imageSignedDownloadUrl(Image $image, $expiresAt = null): string
{
    return URL::temporarySignedRoute(
        'image.download',
        $expiresAt ?? now()->addHour(),
        ['image' => $image->id]
    );
}

it('redirects an owner with a valid signature to a non-empty temporary URL', function () {
    Storage::fake($this->disk);

    $signedUrl = imageSignedDownloadUrl($this->image);

    $response = $this->spaRequest('GET', $signedUrl, $this->cookies);

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBeString()
        ->not->toBeEmpty();
});

it('redirects an owner session request without a signature', function () {
    Storage::fake($this->disk);

    $response = $this->spaRequest(
        'GET',
        '/api/v1/images/'.$this->image->id.'/download',
        $this->cookies
    );

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBeString()
        ->not->toBeEmpty();
});

it('returns 403 for an expired signature', function () {
    $expiredUrl = imageSignedDownloadUrl($this->image, now()->subHour());

    $response = $this->call('GET', $expiredUrl);

    $response->assertStatus(403);
});

it('returns 403 for a tampered signature', function () {
    $tamperedUrl = imageSignedDownloadUrl($this->image).'&tampered=true';

    $response = $this->call('GET', $tamperedUrl);

    $response->assertStatus(403);
});

it('returns 404 for an unsigned request from a non-owner', function () {
    Storage::fake($this->disk);

    // beforeEach already authenticated $this->user; the requested image is
    // owned by $this->otherUser, so the session user is a non-owner.
    $response = $this->spaRequest(
        'GET',
        '/api/v1/images/'.$this->otherUserImage->id.'/download',
        $this->cookies
    );

    $response->assertNotFound();
});

it('returns 404 for an unsigned request to a non-existent image', function () {
    $response = $this->spaRequest('GET', '/api/v1/images/999999999/download', $this->cookies);

    $response->assertNotFound();
});

it('returns 401 for a guest unsigned request with a valid CSRF token', function () {
    $guestCookies = $this->csrfCookies();

    $response = $this->spaRequest(
        'GET',
        '/api/v1/images/'.$this->image->id.'/download',
        $guestCookies
    );

    $response->assertUnauthorized();
});

it('returns 404 for a signed URL minted for a pending image', function () {
    $pending = Image::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $response = $this->call('GET', imageSignedDownloadUrl($pending));

    $response->assertNotFound();
});

it('returns 404 for an owner session download of a pending image', function () {
    $pending = Image::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $response = $this->spaRequest(
        'GET',
        '/api/v1/images/'.$pending->id.'/download',
        $this->cookies
    );

    $response->assertNotFound();
});

it('returns 404 for an owner session download of a failed image', function () {
    $failed = Image::factory()->failed()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $response = $this->spaRequest(
        'GET',
        '/api/v1/images/'.$failed->id.'/download',
        $this->cookies
    );

    $response->assertNotFound();
});

it('returns a sanitized 500 when the storage temporary URL cannot be minted', function () {
    $prompt = 'A secret prompt that must never leak';

    $image = Image::factory()->generated()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'prompt' => $prompt,
    ]);

    // Reconfigure the media disk as a local driver WITHOUT serve, so
    // temporaryUrl() throws offline (no mocks, no network).
    config()->set('filesystems.disks.'.$this->disk, [
        'driver' => 'local',
        'root' => storage_path('app/test-download-500'),
        'throw' => true,
    ]);
    Storage::forgetDisk($this->disk);

    $response = $this->spaRequest(
        'GET',
        '/api/v1/images/'.$image->id.'/download',
        $this->cookies
    );

    $response->assertStatus(500);

    $body = $response->getContent();
    expect($body)->not->toContain($image->storage_key)
        ->and($body)->not->toContain('bucket')
        ->and($body)->not->toContain($prompt);
});

it('registers the download route outside the auth sanctum group with no throttle middleware', function () {
    $route = app('router')->getRoutes()->getByName('image.download');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('api/v1/images/{image}/download')
        ->and($route->methods())->toBe(['GET', 'HEAD']);

    $middleware = $route->gatherMiddleware();
    expect($middleware)->not->toContain('auth:sanctum')
        ->and(collect($middleware)->filter(
            fn ($entry) => is_string($entry) && str_starts_with($entry, 'throttle')
        )->isEmpty());
});
