<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

beforeEach(function () {
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

        // Database now has a pending ClipReview for a rendered DerivedAsset
        expect(ClipReview::where('reviewer_id', $this->user->id)
            ->where('status', ClipReviewStatus::PENDING)
            ->count())->toBe(1);
        expect(DerivedAsset::where('type', 'clip_rendered')
            ->where('render_status', 'completed')
            ->count())->toBe(1);
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

    it('missing CSRF returns 419', function () {
        $noCsrfCookies = $this->cookies;
        unset($noCsrfCookies['XSRF-TOKEN']);

        $response = $this->spaRequest('POST', '/api/v1/test/clip-reviews/seed', $noCsrfCookies, [
            'project_id' => $this->project->id,
        ]);

        $response->assertStatus(419);
    });
});
