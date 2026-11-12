<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(config('media.disk', 'media'));

    $this->user = User::factory()->create();
    $this->otherUser = User::factory()->create();

    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->otherProject = Project::factory()->create(['user_id' => $this->otherUser->id]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

describe('POST /api/v1/test/clip-reviews/seed — test-seeding route (testing environment only)', function () {
    it('authenticated user seeds own project → 200 with ClipReviewResource', function () {
        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $this->cookies, [
            'project_id' => $this->project->id,
        ]);

        $response->assertOk();
        expect($response->json('data.status'))->toBe('pending');
        expect($response->json('data.video_url'))
            ->toContain('clip-reviews/')
            ->toContain('signature')
            ->toContain('expires');

        // Database now has 2 pending ClipReviews: 1 from beforeEach + 1 from seed
        expect(ClipReview::where('reviewer_id', $this->user->id)
            ->where('status', ClipReviewStatus::PENDING)
            ->count())->toBe(2);
        expect(DerivedAsset::where('type', 'clip_rendered')
            ->where('render_status', 'completed')
            ->count())->toBe(2);

        // The seeded derived asset is the most recently created one
        $seededDerivedAsset = DerivedAsset::where('type', 'clip_rendered')
            ->latest('created_at')
            ->firstOrFail();
        expect($seededDerivedAsset->mediaAsset->project_id)->toBe($this->project->id);
    });

    it('persists a real, non-empty media object at the derived asset storage key on the configured media disk', function () {
        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $this->cookies, [
            'project_id' => $this->project->id,
        ]);

        $response->assertOk();

        // Get the derived asset that was just seeded (most recent)
        $derivedAsset = DerivedAsset::where('type', 'clip_rendered')
            ->latest('created_at')
            ->firstOrFail();
        $disk = Storage::disk(config('media.disk', 'media'));

        // The seeded fixture must be complete: a real backing storage object,
        // not a metadata-only row (spec fixture-completeness contract).
        expect($disk->exists($derivedAsset->storage_key))->toBeTrue();
        expect($disk->size($derivedAsset->storage_key))->toBeGreaterThan(0);
    });

    it('resolves the returned signed video_url to an existing storage object', function () {
        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $this->cookies, [
            'project_id' => $this->project->id,
        ]);

        $response->assertOk();
        $videoUrl = $response->json('data.video_url');
        expect($videoUrl)->toBeString();

        // Following the signed playback route must redirect (never 403/404/500)...
        $playback = $this->call('GET', $videoUrl);
        $playback->assertStatus(302);

        // ...and the redirect target must be a storage object that actually
        // exists, so the browser <video> request can never 404.
        $location = $playback->headers->get('Location');
        expect($location)->toBeString();

        $targetPath = trim((string) parse_url($location, PHP_URL_PATH), '/');
        expect($targetPath)->not->toBe('');

        // The derived asset from the seeded response is the most recent one
        $derivedAsset = DerivedAsset::where('type', 'clip_rendered')
            ->latest('created_at')
            ->firstOrFail();
        expect($targetPath)->toBe($derivedAsset->storage_key);

        $disk = Storage::disk(config('media.disk', 'media'));
        expect($disk->exists($targetPath))->toBeTrue();
    });

    it('returns resource with no storage internals exposed', function () {
        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $this->cookies, [
            'project_id' => $this->project->id,
        ]);

        $response->assertOk();
        $data = $response->json('data');
        expect(array_keys($data))->not->toContain('storage_disk');
        expect(array_keys($data))->not->toContain('storage_key');
        expect(array_keys($data))->not->toContain('bucket');
    });

    it('non-owner project returns 404', function () {
        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $this->cookies, [
            'project_id' => $this->otherProject->id,
        ]);

        $response->assertNotFound();
    });

    it('non-existent project returns 404', function () {
        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $this->cookies, [
            'project_id' => 999999,
        ]);

        $response->assertNotFound();
    });

    it('guest returns 401', function () {
        $guestCookies = $this->csrfCookies();
        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $guestCookies, [
            'project_id' => $this->project->id,
        ]);

        $response->assertUnauthorized();
    });

    it('succeeds without CSRF', function () {
        $noCsrfCookies = $this->cookies;
        unset($noCsrfCookies['XSRF-TOKEN']);

        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $noCsrfCookies, [
            'project_id' => $this->project->id,
        ]);

        $response->assertOk();
    });
});
