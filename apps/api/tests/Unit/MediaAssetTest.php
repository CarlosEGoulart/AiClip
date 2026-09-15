<?php

namespace Tests\Unit;

use App\Models\MediaAsset;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('can create a MediaAsset via factory with all expected attributes', function () {
    $media = MediaAsset::factory()->create();

    expect($media)->toBeInstanceOf(MediaAsset::class);
    expect($media->id)->toBeInt();
    expect($media->project_id)->toBeInt();
    expect($media->original_name)->toBeString();
    expect($media->storage_disk)->toBe('media');
    expect($media->storage_key)->toBeString();
    expect($media->mime_type)->toBeString();
    expect($media->size_bytes)->toBeInt();
    expect($media->status)->toBe('stored');
    expect($media->created_at)->not->toBeNull();
    expect($media->updated_at)->not->toBeNull();
});

it('belongs to a Project', function () {
    $media = MediaAsset::factory()->create();

    expect($media->project)->toBeInstanceOf(Project::class);
    expect($media->project->id)->toBe($media->project_id);
});

it('has default status of stored', function () {
    $media = MediaAsset::factory()->create();

    expect($media->status)->toBe('stored');
});

it('casts size_bytes to integer', function () {
    $media = MediaAsset::factory()->create(['size_bytes' => 52428800]);

    expect($media->size_bytes)->toBeInt();
    expect($media->size_bytes)->toBe(52428800);
});

it('has unique storage_key', function () {
    $media1 = MediaAsset::factory()->create();
    $media2 = MediaAsset::factory()->create();

    expect($media1->storage_key)->not->toBe($media2->storage_key);
});

it('accepts project_id override', function () {
    $project = Project::factory()->create();
    $media = MediaAsset::factory()->create(['project_id' => $project->id]);

    expect($media->project_id)->toBe($project->id);
});

it('generates realistic attributes', function () {
    $media = MediaAsset::factory()->create();

    expect($media->original_name)->toMatch('/\.(mp4|mov|webm)$/');
    expect($media->mime_type)->toBeIn(['video/mp4', 'video/quicktime', 'video/webm']);
    expect($media->size_bytes)->toBeGreaterThan(0);
});
