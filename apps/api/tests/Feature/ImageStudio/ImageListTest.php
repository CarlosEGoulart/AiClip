<?php

namespace Tests\Feature\ImageStudio;

use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

/*
 * TC-BE-06 (test-plan §3): GET /api/v1/images — paginated, authenticated
 * user's images only, newest first, optional owned project_id filter, the
 * standard pagination envelope wrapped in a top-level data key, and the
 * field whitelist on every item.
 */

const LIST_WHITELISTED_FIELDS = [
    'id',
    'project_id',
    'prompt',
    'parameters',
    'mime_type',
    'size_bytes',
    'width',
    'height',
    'status',
    'created_at',
    'updated_at',
];

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);
    $this->secondProject = Project::factory()->create(['user_id' => $this->user->id]);

    $this->otherUser = User::factory()->create();
    $this->otherProject = Project::factory()->create(['user_id' => $this->otherUser->id]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

it('returns the standard pagination envelope with per_page defaulting to 15', function () {
    Image::factory()->count(3)->create(['user_id' => $this->user->id, 'project_id' => $this->project->id]);

    $response = $this->spaRequest('GET', '/api/v1/images', $this->cookies);

    $response->assertOk();

    $envelope = $response->json('data');
    expect(collect(array_keys($envelope))->sort()->values()->all())->toBe(['data', 'links', 'meta']);
    expect($envelope['meta']['per_page'])->toBe(15)
        ->and($envelope['meta']['total'])->toBe(3)
        ->and(count($envelope['data']))->toBe(3);
});

it('lists the authenticated user images newest first', function () {
    $oldest = Image::factory()->create(['user_id' => $this->user->id, 'project_id' => $this->project->id]);
    $newest = Image::factory()->create(['user_id' => $this->user->id, 'project_id' => $this->project->id]);

    $response = $this->spaRequest('GET', '/api/v1/images', $this->cookies);

    $response->assertOk();
    $ids = collect($response->json('data.data'))->pluck('id')->all();
    expect($ids)->toBe([$newest->id, $oldest->id]);
});

it('never exposes another user images', function () {
    Image::factory()->create(['user_id' => $this->user->id, 'project_id' => $this->project->id]);
    Image::factory()->count(2)->create([
        'user_id' => $this->otherUser->id,
        'project_id' => $this->otherProject->id,
    ]);

    $response = $this->spaRequest('GET', '/api/v1/images', $this->cookies);

    $response->assertOk();
    $items = collect($response->json('data.data'));
    expect($items)->toHaveCount(1)
        ->and($items->first()['id'])->toBe(Image::query()
        ->where('user_id', $this->user->id)
        ->sole()->id);
});

it('filters the list by an owned project', function () {
    $first = Image::factory()->create(['user_id' => $this->user->id, 'project_id' => $this->project->id]);
    Image::factory()->create(['user_id' => $this->user->id, 'project_id' => $this->secondProject->id]);

    $response = $this->spaRequest('GET', '/api/v1/images?project_id='.$this->project->id, $this->cookies);

    $response->assertOk();
    $items = collect($response->json('data.data'));
    expect($items)->toHaveCount(1)
        ->and($items->first()['id'])->toBe($first->id);
});

it('returns 404 when filtering by a project owned by another user', function () {
    $response = $this->spaRequest('GET', '/api/v1/images?project_id='.$this->otherProject->id, $this->cookies);

    $response->assertNotFound();
});

it('returns 404 when filtering by a non-existent project', function () {
    $response = $this->spaRequest('GET', '/api/v1/images?project_id=999999999', $this->cookies);

    $response->assertNotFound();
});

it('caps per_page at 100', function () {
    Image::factory()->count(3)->create(['user_id' => $this->user->id, 'project_id' => $this->project->id]);

    $response = $this->spaRequest('GET', '/api/v1/images?per_page=200', $this->cookies);

    $response->assertOk();
    expect($response->json('data.meta.per_page'))->toBe(100)
        ->and(count($response->json('data.data')))->toBe(3);
});

it('exposes only whitelisted fields on every list item', function () {
    Image::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'parameters' => ['style_preset' => 'cinematic', 'width' => 1080, 'height' => 1920],
    ]);

    $response = $this->spaRequest('GET', '/api/v1/images', $this->cookies);

    $response->assertOk();

    $item = $response->json('data.data.0');
    expect(collect(array_keys($item))->sort()->values()->all())
        ->toBe(collect(LIST_WHITELISTED_FIELDS)->sort()->values()->all());

    $rendered = json_encode($item);
    expect($rendered)->not->toContain('storage_disk')
        ->and($rendered)->not->toContain('storage_key')
        ->and($rendered)->not->toContain('user_id')
        ->and($rendered)->not->toContain('bucket');
});

it('returns 401 for a guest', function () {
    $guestCookies = $this->csrfCookies();

    $response = $this->spaRequest('GET', '/api/v1/images', $guestCookies);

    $response->assertUnauthorized();
});
