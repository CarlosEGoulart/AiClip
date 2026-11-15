<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Project;
use App\Models\MediaAsset;
use App\Models\Image;
use App\Models\ClipReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('non-owner access to own media returns 404 (no existence leak)', function () {
    $owner = User::factory()->create();
    $nonOwner = User::factory()->create();

    $project = Project::factory()->create(['user_id' => $owner->id]);
    $media = MediaAsset::factory()->create(['project_id' => $project->id]);

    $response = $this->actingAs($nonOwner)->getJson('/api/v1/projects/' . $project->id . '/media');

    expect($response->status())->toBe(404);
});

it('non-owner access to own image returns 404 (no existence leak)', function () {
    $owner = User::factory()->create();
    $nonOwner = User::factory()->create();

    $project = Project::factory()->create(['user_id' => $owner->id]);
    $image = Image::factory()->create(['project_id' => $project->id, 'user_id' => $owner->id]);

    $response = $this->actingAs($nonOwner)->getJson('/api/v1/images/' . $image->id);

    expect($response->status())->toBe(404);
});

it('non-owner access to own clip review returns 404 (no existence leak)', function () {
    $owner = User::factory()->create();
    $nonOwner = User::factory()->create();

    $project = Project::factory()->create(['user_id' => $owner->id]);

    $response = $this->actingAs($nonOwner)->getJson('/api/v1/projects/' . $project->id . '/clip-reviews');

    expect($response->status())->toBe(404);
});

it('upload endpoint enforces ownership', function () {
    $owner = User::factory()->create();
    $nonOwner = User::factory()->create();

    $project = Project::factory()->create(['user_id' => $owner->id]);

    $response = $this->actingAs($nonOwner)
        ->postJson('/api/v1/projects/' . $project->id . '/media/upload');

    expect($response->status())->toBe(404);
});

it('storage internals are not in media asset response bodies', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);
    $media = MediaAsset::factory()->create([
        'project_id' => $project->id,
        'storage_key' => 'secret/storage/key.mp4',
        'storage_disk' => 'secret-bucket',
    ]);

    $response = $this->actingAs($owner)->getJson('/api/v1/projects/' . $project->id . '/media');

    $response->assertStatus(200);
    $content = $response->getContent();
    expect($content)->not->toContain('secret/storage/key.mp4');
    expect($content)->not->toContain('secret-bucket');
});

it('storage internals are not in image response bodies', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);
    $image = Image::factory()->create([
        'project_id' => $project->id,
        'user_id' => $owner->id,
        'storage_key' => 'secret/image/key.png',
        'storage_disk' => 'secret-bucket',
    ]);

    $response = $this->actingAs($owner)->getJson('/api/v1/images/' . $image->id);

    $response->assertStatus(200);
    $content = $response->getContent();
    expect($content)->not->toContain('secret/image/key.png');
    expect($content)->not->toContain('secret-bucket');
});
