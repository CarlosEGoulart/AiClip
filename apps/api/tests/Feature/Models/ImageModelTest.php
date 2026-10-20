<?php

namespace Tests\Feature\Models;

use App\Enums\ImageStatus;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/*
 * TC-BE-02 (test-plan §1): images data model integrity — factory states,
 * enum cast + constants, relations, unique storage_key, fillable
 * boundary, and the pending column default.
 */

it('persists the pending factory default state with a project-scoped storage key', function () {
    $project = Project::factory()->create();

    $image = Image::factory()->create(['project_id' => $project->id]);

    expect($image->status)->toBe(ImageStatus::PENDING)
        ->and($image->storage_disk)->toBe(config('media.disk'))
        ->and($image->storage_key)->toMatch('#\Aprojects/'.$project->id.'/images/[0-9a-f-]{36}\.png\z#')
        ->and($image->parameters)->toBeArray();
});

it('casts status to the image status enum and exposes the status constants', function () {
    $image = Image::factory()->create();

    expect($image->status)->toBeInstanceOf(ImageStatus::class)
        ->and(Image::STATUS_PENDING)->toBe(ImageStatus::PENDING->value)
        ->and(Image::STATUS_GENERATED)->toBe(ImageStatus::GENERATED->value)
        ->and(Image::STATUS_FAILED)->toBe(ImageStatus::FAILED->value);
});

it('persists the generated and failed factory states', function () {
    $generated = Image::factory()->generated()->create();
    $failed = Image::factory()->failed()->create();

    expect($generated->status)->toBe(ImageStatus::GENERATED)
        ->and($failed->status)->toBe(ImageStatus::FAILED);
});

it('resolves the owning project and user relations', function () {
    $project = Project::factory()->create();

    $image = Image::factory()->create(['project_id' => $project->id]);

    expect($image->project?->id)->toBe($project->id)
        ->and($image->user?->id)->toBe($project->user_id);
});

it('gives every factory row a unique project-scoped storage key', function () {
    $project = Project::factory()->create();

    $first = Image::factory()->create(['project_id' => $project->id]);
    $second = Image::factory()->create(['project_id' => $project->id]);

    expect($first->storage_key)->not->toBe($second->storage_key);
});

it('enforces the unique storage key constraint', function () {
    $existing = Image::factory()->create();

    Image::factory()->create(['storage_key' => $existing->storage_key]);
})->throws(QueryException::class);

it('only allows the whitelisted attributes to be mass assigned', function () {
    expect((new Image)->getFillable())->toBe([
        'user_id',
        'project_id',
        'prompt',
        'parameters',
        'storage_disk',
        'storage_key',
        'mime_type',
        'size_bytes',
        'width',
        'height',
        'status',
    ]);

    $image = Image::factory()->make();
    $image->fill([
        'id' => 999,
        'created_at' => '2000-01-01 00:00:00',
        'user_id' => $image->user_id,
    ]);

    expect($image->id)->toBeNull()
        ->and($image->created_at)->toBeNull()
        ->and($image->user_id)->toBeInt();
});

it('casts the parameters json snapshot to an array', function () {
    $image = Image::factory()->create([
        'parameters' => ['style_preset' => 'cinematic', 'width' => 1080, 'height' => 1920],
    ]);

    $fresh = Image::query()->findOrFail($image->id);

    expect($fresh->parameters)->toBe(['style_preset' => 'cinematic', 'width' => 1080, 'height' => 1920]);
});

it('defaults new rows to the pending status', function () {
    $project = Project::factory()->create();

    DB::table('images')->insert([
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'prompt' => 'A quiet harbor at dawn',
        'storage_disk' => config('media.disk'),
        'storage_key' => 'projects/'.$project->id.'/images/00000000-0000-4000-8000-000000000000.png',
        'width' => 1024,
        'height' => 1024,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $row = DB::table('images')->where('project_id', $project->id)->first();

    expect($row->status)->toBe('pending')
        ->and($row->mime_type)->toBe('image/png')
        ->and((int) $row->size_bytes)->toBe(0);
});

it('cascade deletes images with the owning user and project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $image = Image::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    expect((int) DB::table('images')->where('id', $image->id)->count())->toBe(1);

    $project->delete();
    expect((int) DB::table('images')->where('id', $image->id)->count())->toBe(0);

    $image = Image::factory()->create();
    $image->user?->delete();
    expect((int) DB::table('images')->where('id', $image->id)->count())->toBe(0);
});
