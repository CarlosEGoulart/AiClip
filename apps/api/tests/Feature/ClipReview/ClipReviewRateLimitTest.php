<?php

namespace Tests\Feature\ClipReview;

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
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->mediaAsset = MediaAsset::factory()->create(['project_id' => $this->project->id]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

it('rate limits clip-review-update at 30 requests per minute', function () {
    for ($i = 0; $i < 30; $i++) {
        $derivedAsset = DerivedAsset::factory()->renderedClip()->create([
            'media_asset_id' => $this->mediaAsset->id,
        ]);
        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => $derivedAsset->id,
            'reviewer_id' => $this->user->id,
        ]);

        $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$clipReview->id}/status", $this->cookies, [
            'status' => 'approved',
        ]);
        $response->assertOk();
    }

    // 31st request should be rate limited
    $derivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
    ]);
    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $this->user->id,
    ]);

    $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$clipReview->id}/status", $this->cookies, [
        'status' => 'approved',
    ]);

    $response->assertStatus(429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
});

it('rate limits clip-review-feedback at 30 requests per minute', function () {
    for ($i = 0; $i < 30; $i++) {
        $derivedAsset = DerivedAsset::factory()->renderedClip()->create([
            'media_asset_id' => $this->mediaAsset->id,
        ]);
        $clipReview = ClipReview::factory()->create([
            'derived_asset_id' => $derivedAsset->id,
            'reviewer_id' => $this->user->id,
            'status' => 'rejected',
        ]);

        $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $this->cookies, [
            'feedback' => 'Feedback '.$i,
        ]);
        $response->assertOk();
    }

    // 31st request should be rate limited
    $derivedAsset = DerivedAsset::factory()->renderedClip()->create([
        'media_asset_id' => $this->mediaAsset->id,
    ]);
    $clipReview = ClipReview::factory()->create([
        'derived_asset_id' => $derivedAsset->id,
        'reviewer_id' => $this->user->id,
        'status' => 'rejected',
    ]);

    $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$clipReview->id}/feedback", $this->cookies, [
        'feedback' => 'Feedback 30',
    ]);

    $response->assertStatus(429);
});
