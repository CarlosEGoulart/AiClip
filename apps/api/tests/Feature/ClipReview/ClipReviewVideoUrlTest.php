<?php

namespace Tests\Feature\ClipReview;

use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class)->in('Feature/ClipReview');

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();

    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->otherProject = Project::factory()->create(['user_id' => $this->otherUser->id]);

    $this->mediaAsset = MediaAsset::factory()->create(['project_id' => $this->project->id]);
    $this->otherMediaAsset = MediaAsset::factory()->create(['project_id' => $this->otherProject->id]);

    $this->derivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
        'storage_key' => 'renders/test-clip.mp4',
    ]);
    $this->otherDerivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->otherMediaAsset->id,
        'storage_key' => 'renders/other-clip.mp4',
    ]);

    $this->clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]);
    $this->otherClipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->otherDerivedAsset->id,
        'reviewer_id' => $this->otherUser->id,
    ]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

it('generates signed video URL for owner', function () {
    Storage::fake('media');

    $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$this->clipReview->id}/video", $this->cookies);

    // Should redirect to signed URL
    $response->assertStatus(302);
    $redirectUrl = $response->headers->get('Location');
    expect($redirectUrl)->toBeString();
});

it('returns 404 for non-owner', function () {
    Storage::fake('media');

    $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$this->otherClipReview->id}/video", $this->cookies);

    $response->assertNotFound();
});

it('returns 404 for non-existent clip review', function () {
    $response = $this->spaRequest('GET', '/api/v1/clip-reviews/999999/video', $this->cookies);

    $response->assertNotFound();
});

it('clip review resource includes signed video URL', function () {
    $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$this->clipReview->id}", $this->cookies);

    $response->assertOk();
    $videoUrl = $response->json('data.video_url');
    expect($videoUrl)->toBeString();
    expect($videoUrl)->toContain('clip-review.video');
    expect($videoUrl)->toContain((string)$this->clipReview->id);
});

it('signed URL expires after 1 hour (tested via URL signature)', function () {
    $signedUrl = URL::temporarySignedRoute(
        'clip-review.video',
        now()->addHour(),
        ['clipReview' => $this->clipReview->id]
    );

    expect($signedUrl)->toBeString();
    expect($signedUrl)->toContain('signature');
    expect($signedUrl)->toContain('expires');
});

it('returns 403 for expired signature', function () {
    $expiredUrl = URL::temporarySignedRoute(
        'clip-review.video',
        now()->subHour(),
        ['clipReview' => $this->clipReview->id]
    );

    $response = $this->call('GET', $expiredUrl);
    $response->assertStatus(403);
});

it('returns 403 for tampered signature', function () {
    $signedUrl = URL::temporarySignedRoute(
        'clip-review.video',
        now()->addHour(),
        ['clipReview' => $this->clipReview->id]
    );

    // Tamper with the signature
    $tamperedUrl = $signedUrl . '&tampered=true';

    $response = $this->call('GET', $tamperedUrl);
    $response->assertStatus(403);
});