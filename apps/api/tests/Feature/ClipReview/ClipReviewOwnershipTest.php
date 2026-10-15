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
    ]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

it('returns 404 when accessing other user clip review via index', function () {
    $response = $this->spaRequest('GET', "/api/v1/projects/{$this->otherProject->id}/clip-reviews", $this->cookies);
    $response->assertNotFound();
});

it('returns 404 when accessing other user clip review via show', function () {
    $otherClipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->otherDerivedAsset->id,
        'reviewer_id' => $this->otherUser->id,
    ]);

    $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$otherClipReview->id}", $this->cookies);
    $response->assertNotFound();
});

it('returns 404 when updating status of other user clip review', function () {
    $otherClipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->otherDerivedAsset->id,
        'reviewer_id' => $this->otherUser->id,
    ]);

    $response = $this->spaRequest('PATCH', "/api/v1/clip-reviews/{$otherClipReview->id}/status", $this->cookies, [
        'status' => 'approved',
    ]);
    $response->assertNotFound();
});

it('returns 404 when submitting feedback for other user clip review', function () {
    $otherClipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->otherDerivedAsset->id,
        'reviewer_id' => $this->otherUser->id,
        'status' => 'rejected',
    ]);

    $response = $this->spaRequest('POST', "/api/v1/clip-reviews/{$otherClipReview->id}/feedback", $this->cookies, [
        'feedback' => 'Some feedback',
    ]);
    $response->assertNotFound();
});

it('returns 404 when accessing video for other user clip review', function () {
    $otherClipReview = ClipReview::factory()->create([
        'derived_asset_id' => $this->otherDerivedAsset->id,
        'reviewer_id' => $this->otherUser->id,
    ]);

    $response = $this->spaRequest('GET', "/api/v1/clip-reviews/{$otherClipReview->id}/video", $this->cookies);
    $response->assertNotFound();
});

it('returns 404 for non-existent clip review on all endpoints', function () {
    $endpoints = [
        ['GET', '/api/v1/clip-reviews/999999'],
        ['PATCH', '/api/v1/clip-reviews/999999/status'],
        ['POST', '/api/v1/clip-reviews/999999/feedback'],
        ['GET', '/api/v1/clip-reviews/999999/video'],
    ];

    foreach ($endpoints as [$method, $uri]) {
        $response = $this->spaRequest($method, $uri, $this->cookies, $method === 'POST' || $method === 'PATCH' ? ['status' => 'approved', 'feedback' => 'test'] : []);
        $response->assertNotFound();
    }
});
