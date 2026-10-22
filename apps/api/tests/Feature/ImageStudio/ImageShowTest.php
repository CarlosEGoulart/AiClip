<?php

namespace Tests\Feature\ImageStudio;

use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SpaTestCase;

uses(SpaTestCase::class, RefreshDatabase::class);

/*
 * TC-BE-07 (test-plan §3): GET /api/v1/images/{image} — owner 200 with
 * the field whitelist; non-owner and non-existent ids return 404 without
 * leaking storage internals.
 */

const SHOW_WHITELISTED_FIELDS = [
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
    'download_url',
];

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->create(['user_id' => $this->user->id]);

    $this->otherUser = User::factory()->create();

    $this->image = Image::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'parameters' => ['style_preset' => 'digital_art', 'width' => 1920, 'height' => 1080],
    ]);

    $this->otherUserImage = Image::factory()->create([
        'user_id' => $this->otherUser->id,
    ]);

    $this->cookies = $this->csrfCookies();
    $this->spaRequest('POST', '/api/v1/auth/login', $this->cookies, [
        'email' => $this->user->email,
        'password' => 'password',
    ])->assertOk();
});

it('shows an owned image with only the whitelisted fields', function () {
    $response = $this->spaRequest('GET', '/api/v1/images/'.$this->image->id, $this->cookies);

    $response->assertOk();

    $data = $response->json('data');
    expect(collect(array_keys($data))->sort()->values()->all())
        ->toBe(collect(SHOW_WHITELISTED_FIELDS)->sort()->values()->all());

    expect($data['id'])->toBe($this->image->id)
        ->and($data['project_id'])->toBe($this->project->id)
        ->and($data['prompt'])->toBe($this->image->prompt)
        ->and($data['parameters'])->toBe(['style_preset' => 'digital_art', 'width' => 1920, 'height' => 1080])
        ->and($data['status'])->toBe('pending')
        ->and($data['download_url'])->toBeNull();

    $rendered = $response->getContent();
    expect($rendered)->not->toContain('storage_disk')
        ->and($rendered)->not->toContain('storage_key')
        ->and($rendered)->not->toContain('user_id')
        ->and($rendered)->not->toContain('bucket');
});

it('includes a signed download_url for a generated image', function () {
    $generated = Image::factory()->generated()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $response = $this->spaRequest('GET', '/api/v1/images/'.$generated->id, $this->cookies);

    $response->assertOk();

    $data = $response->json('data');
    expect(collect(array_keys($data))->sort()->values()->all())
        ->toBe(collect(SHOW_WHITELISTED_FIELDS)->sort()->values()->all());

    // Signed URLs embed the route URI path plus signature/expires query
    // parameters — never the dot-separated route name (M7 lesson).
    expect($data['download_url'])->toBeString()
        ->and($data['download_url'])->toContain('images/'.$generated->id.'/download')
        ->and($data['download_url'])->toContain('signature')
        ->and($data['download_url'])->toContain('expires')
        ->and($data['download_url'])->not->toContain('image.download');
});

it('returns a null download_url for a failed image', function () {
    $failed = Image::factory()->failed()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $response = $this->spaRequest('GET', '/api/v1/images/'.$failed->id, $this->cookies);

    $response->assertOk();

    $data = $response->json('data');
    expect(collect(array_keys($data))->sort()->values()->all())
        ->toBe(collect(SHOW_WHITELISTED_FIELDS)->sort()->values()->all())
        ->and($data['download_url'])->toBeNull();
});

it('mints the download_url for the owning image id only', function () {
    $first = Image::factory()->generated()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);
    $second = Image::factory()->generated()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $response = $this->spaRequest('GET', '/api/v1/images/'.$first->id, $this->cookies);

    $response->assertOk();

    $downloadUrl = (string) $response->json('data.download_url');
    expect($downloadUrl)->toContain('images/'.$first->id.'/download')
        ->and($downloadUrl)->not->toContain('images/'.$second->id.'/download');
});

it('returns 404 for an image owned by another user', function () {
    $response = $this->spaRequest('GET', '/api/v1/images/'.$this->otherUserImage->id, $this->cookies);

    $response->assertNotFound();
});

it('returns 404 for a non-existent image', function () {
    $response = $this->spaRequest('GET', '/api/v1/images/999999999', $this->cookies);

    $response->assertNotFound();
});

it('returns 401 for a guest', function () {
    $guestCookies = $this->csrfCookies();

    $response = $this->spaRequest('GET', '/api/v1/images/'.$this->image->id, $guestCookies);

    $response->assertUnauthorized();
});
