<?php

namespace Tests\Feature\ClipReview;

use App\Enums\ClipReviewStatus;
use App\Models\ClipReview;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
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

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

describe('PATCH /api/v1/clip-reviews/{clipReview}/status — valid one-way transitions', function () {
    it('owner: pending → approved returns 200 with updated status', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertOk();
        expect($response->json('data.status'))->toBe('approved');

        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);
    });

    it('owner: pending → rejected returns 200 with updated status', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'rejected',
        ]);

        $response->assertOk();
        expect($response->json('data.status'))->toBe('rejected');

        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::REJECTED);
    });

    it('owner: pending → needs_changes returns 200 with updated status', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'needs_changes',
        ]);

        $response->assertOk();
        expect($response->json('data.status'))->toBe('needs_changes');

        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::NEEDS_CHANGES);
    });

    it('transition observable in PATCH response matches requested target status', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        expect($response->json('data.status'))->toBe('approved');
    });
});

describe('PATCH /api/v1/clip-reviews/{clipReview}/status — invalid / forbidden transitions (422)', function () {
    it('owner: pending → pending (revert) returns 422', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'pending',
        ]);

        $response->assertUnprocessable();
    });

    it('owner: pending → foo (invalid status) returns 422', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'foo',
        ]);

        $response->assertUnprocessable();
    });

    it('owner: approved → rejected (terminal state) returns 422', function () {
        $this->clipReview->update(['status' => ClipReviewStatus::APPROVED]);

        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'rejected',
        ]);

        $response->assertUnprocessable();
        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::APPROVED);
    });

    it('owner: rejected → approved (terminal state) returns 422', function () {
        $this->clipReview->update(['status' => ClipReviewStatus::REJECTED]);

        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertUnprocessable();
        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::REJECTED);
    });

    it('owner: needs_changes → approved (terminal state) returns 422', function () {
        $this->clipReview->update(['status' => ClipReviewStatus::NEEDS_CHANGES]);

        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertUnprocessable();
        $this->clipReview->refresh();
        expect($this->clipReview->status)->toBe(ClipReviewStatus::NEEDS_CHANGES);
    });

    it('terminal state cannot transition to any other state', function () {
        foreach ([ClipReviewStatus::APPROVED, ClipReviewStatus::REJECTED, ClipReviewStatus::NEEDS_CHANGES] as $terminalStatus) {
            $this->clipReview->update(['status' => $terminalStatus]);

            foreach (['approved', 'rejected', 'needs_changes', 'pending'] as $targetStatus) {
                $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $this->cookies, [
                    'status' => $targetStatus,
                ]);

                $response->assertUnprocessable();
            }

            $this->clipReview->refresh();
            expect($this->clipReview->status)->toBe($terminalStatus);
        }
    });
});

describe('PATCH /api/v1/clip-reviews/{clipReview}/status — ownership, auth, CSRF, rate limit', function () {
    it('non-owner returns 404', function () {
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->otherClipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertNotFound();
    });

    it('non-existent clip review returns 404', function () {
        $response = $this->spaRequest('PATCH', '/api/v1/clip-reviews/999999/status', $this->cookies, [
            'status' => 'approved',
        ]);

        $response->assertNotFound();
    });

    it('succeeds without CSRF', function () {
        $noCsrfCookies = $this->cookies;
        unset($noCsrfCookies['XSRF-TOKEN']);

        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $noCsrfCookies, [
            'status' => 'approved',
        ]);

        $response->assertOk();
    });

    it('guest (valid CSRF, no session) returns 401', function () {
        $guestCookies = $this->csrfCookies();
        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$this->clipReview->id}/status", $guestCookies, [
            'status' => 'approved',
        ]);

        $response->assertUnauthorized();
    });

    it('rate limit: 429 on 31st update request', function () {
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
