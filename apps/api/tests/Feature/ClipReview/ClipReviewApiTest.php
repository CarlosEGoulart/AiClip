<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\Project;
use App\Models\User;
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
    ]);
    $this->otherDerivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->otherMediaAsset->id,
    ]);

    $this->clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->derivedAsset->id,
        'reviewer_id' => $this->user->id,
        'status' => ClipReviewStatus::PENDING,
    ]);
    $this->otherClipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->otherDerivedAsset->id,
        'reviewer_id' => $this->otherUser->id,
        'status' => ClipReviewStatus::PENDING,
    ]);

    // Get CSRF cookies for authenticated requests
    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

describe('GET /api/v1/projects/{project}/clip-reviews', function () {
    it('returns 200 with clip reviews for owner', function () {
        $response = $this->spaRequest('GET', "/api/v1/projects/{$this->project->id}/clip-reviews", $this->cookies);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'derived_asset_id',
                            'status',
                            'feedback',
                            'video_url',
                            'thumbnail_url',
                            'duration_ms',
                            'width',
                            'height',
                            'candidate_index',
                            'render_profile_version',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                    'links',
                    'meta',
                ],
            ]);

        expect($response->json('data.data'))->toHaveCount(1);
        expect($response->json('data.data.0.id'))->toBe($this->clipReview->id);
    });

    it('returns empty array when no reviews exist', function () {
        $emptyProject = Project::factory()->create(['user_id' => $this->user->id]);
        $emptyMediaAsset = MediaAsset::factory()->create(['project_id' => $emptyProject->id]);
        $emptyDerivedAsset = DerivedAsset::factory()->renderedClip()->create([
            'media_asset_id' => $emptyMediaAsset->id,
        ]);

        $response = $this->spaRequest('GET', "/api/v1/projects/{$emptyProject->id}/clip-reviews", $this->cookies);

        $response->assertOk();
        expect($response->json('data.data'))->toHaveCount(0);
    });

    it('returns 404 for non-owner project', function () {
        $response = $this->spaRequest('GET', "/api/v1/projects/{$this->otherProject->id}/clip-reviews", $this->cookies);

        $response->assertNotFound();
    });

    it('returns 404 for non-existent project', function () {
        $response = $this->spaRequest('GET', '/api/v1/projects/999999/clip-reviews', $this->cookies);

        $response->assertNotFound();
    });

    it('returns 401 for guest', function () {
        $guestCookies = [];
        $response = $this->spaRequest('GET', "/api/v1/projects/{$this->project->id}/clip-reviews", $guestCookies);

        $response->assertUnauthorized();
    });

    it('returns 429 when rate limit exceeded', function () {
        // Make 60 requests (limit is 60/min)
        for ($i = 0; $i < 60; $i++) {
            $this->spaRequest('GET', "/api/v1/projects/{$this->project->id}/clip-reviews", $this->cookies);
        }

        // 61st request should be rate limited
        $response = $this->spaRequest('GET', "/api/v1/projects/{$this->project->id}/clip-reviews", $this->cookies);

        $response->assertStatus(429);
        expect($response->headers->has('Retry-After'))->toBeTrue();
    });
});

describe('GET /api/v1/clip-reviews/{clipReview}', function () {
    it('returns 200 with clip review data for owner', function () {
        $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$this->clipReview->id}", $this->cookies);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'derived_asset_id',
                    'status',
                    'feedback',
                    'video_url',
                    'thumbnail_url',
                    'duration_ms',
                    'width',
                    'height',
                    'candidate_index',
                    'render_profile_version',
                    'created_at',
                    'updated_at',
                ],
            ]);

        expect($response->json('data.id'))->toBe($this->clipReview->id);
        expect($response->json('data.status'))->toBe('pending');
        expect($response->json('data.video_url'))->toContain('clip-review.video');
    });

    it('returns 404 for non-owner', function () {
        $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$this->otherClipReview->id}", $this->cookies);

        $response->assertNotFound();
    });

    it('returns 404 for non-existent clip review', function () {
        $response = $this->spaRequest('GET', '/api/v1/clip-reviews/999999', $this->cookies);

        $response->assertNotFound();
    });

    it('returns 401 for guest', function () {
        $guestCookies = [];
        $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$this->clipReview->id}", $guestCookies);

        $response->assertUnauthorized();
    });
});

describe('PATCH /api/v1/clip-reviews/{clipReview}/status', function () {
    it('approves clip review and dispatches job', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertOk();
        expect($response->json('data.status'))->toBe('approved');

        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);
    });

    it('rejects clip review and dispatches job', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'rejected',
        ]);

        $response->assertOk();
        expect($response->json('data.status'))->toBe('rejected');

        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::REJECTED);
    });

    it('sets needs_changes status and dispatches job', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'needs_changes',
        ]);

        $response->assertOk();
        expect($response->json('data.status'))->toBe('needs_changes');

        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::NEEDS_CHANGES);
    });

    it('returns 422 for invalid status (pending)', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'pending',
        ]);

        $response->assertUnprocessable();
    });

    it('returns 422 for invalid status (foo)', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'foo',
        ]);

        $response->assertUnprocessable();
    });

    it('returns 404 for non-owner', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->otherClipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertNotFound();
    });

    it('returns 404 for non-existent clip review', function () {
        $response = $this->spaRequest('PATCH', '/api/v1/clip-reviews/999999/status', $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertNotFound();
    });

    it('returns 419 without CSRF', function () {
        $noCsrfCookies = $this->cookies;
        unset($noCsrfCookies['XSRF-TOKEN']);

        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $noCsrfCookies, [
            'status' => 'approved',
        ]);

        $response->assertStatus(419);
    });

    it('returns 401 for guest', function () {
        $guestCookies = [];
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $guestCookies, [
            'status' => 'approved',
        ]);

        $response->assertUnauthorized();
    });

    it('returns 429 when rate limit exceeded (30/min)', function () {
        for ($i = 0; $i < 30; $i++) {
            $clipReview = ClipReview::factory()->create([
                'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                    'media_asset_id' => $this->mediaAsset->id,
                ])->id,
                'reviewer_id' => $this->user->id,
            ]);
            $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$clipReview->id}/status", $this->cookies, [
                'status' => 'approved',
            ]);
        }

        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                'media_asset_id' => $this->mediaAsset->id,
            ])->id,
            'reviewer_id' => $this->user->id,
        ]);

        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$clipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertStatus(429);
    });
});

describe('POST /api/v1/clip-reviews/{clipReview}/feedback', function () {
    it('stores feedback for rejected clip review', function () {
        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                'media_asset_id' => $this->mediaAsset->id,
            ])->id,
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::REJECTED,
        ]);

        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $this->cookies, [
            'feedback' => 'Caption timing is off at 0:15',
        ]);

        $response->assertOk();
        expect($response->json('data.feedback'))->toBe('Caption timing is off at 0:15');

        $clipReview->refresh();
        expect($clipReview->feedback)->toBe('Caption timing is off at 0:15');
    });

    it('stores feedback for needs_changes clip review', function () {
        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                'media_asset_id' => $this->mediaAsset->id,
            ])->id,
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::NEEDS_CHANGES,
        ]);

        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $this->cookies, [
            'feedback' => 'Please adjust the transition timing',
        ]);

        $response->assertOk();
        expect($response->json('data.feedback'))->toBe('Please adjust the transition timing');
    });

    it('returns 422 when feedback < 10 chars for rejected', function () {
        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                'media_asset_id' => $this->mediaAsset->id,
            ])->id,
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::REJECTED,
        ]);

        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $this->cookies, [
            'feedback' => 'Bad',
        ]);

        $response->assertUnprocessable();
    });

    it('returns 422 when feedback > 1000 chars', function () {
        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                'media_asset_id' => $this->mediaAsset->id,
            ])->id,
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::REJECTED,
        ]);

        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $this->cookies, [
            'feedback' => str_repeat('a', 1001),
        ]);

        $response->assertUnprocessable();
    });

    it('allows feedback for approved (no min length)', function () {
        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                'media_asset_id' => $this->mediaAsset->id,
            ])->id,
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::APPROVED,
        ]);

        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $this->cookies, [
            'feedback' => 'OK',
        ]);

        $response->assertOk();
    });

    it('returns 404 for non-owner', function () {
        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$this->otherClipReview->id}/feedback", $this->cookies, [
            'feedback' => 'Some feedback',
        ]);

        $response->assertNotFound();
    });

    it('returns 419 without CSRF', function () {
        $noCsrfCookies = $this->cookies;
        unset($noCsrfCookies['XSRF-TOKEN']);

        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => DerivedAsset::factory()->renderedClip()->create([
                'media_asset_id' => $this->mediaAsset->id,
            ])->id,
            'reviewer_id' => $this->user->id,
            'status' => ClipReviewStatus::REJECTED,
        ]);

        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $noCsrfCookies, [
            'feedback' => 'Some feedback',
        ]);

        $response->assertStatus(419);
    });

    it('returns 401 for guest', function () {
        $guestCookies = [];
        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$this->clipReview->id}/feedback", $guestCookies, [
            'feedback' => 'Some feedback',
        ]);

        $response->assertUnauthorized();
    });
});