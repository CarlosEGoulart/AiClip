<?php

namespace Tests\Unit;

use App\Services\StorageKeyBuilder;
use Tests\TestCase;

uses(TestCase::class);

it('builds the exact project scoped render key with UUID suffix', function () {
    $key = StorageKeyBuilder::renderClip(
        projectId: 7,
        mediaAssetId: 42,
        candidateIndex: 3,
        renderProfileVersion: 'vertical_v1',
    );

    expect($key)->toMatch(
        '#^projects/7/renders/42/3/vertical_v1/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.mp4$#i'
    );
});

it('generates a fresh UUID for a new durable render identity', function () {
    $first = StorageKeyBuilder::renderClip(7, 42, 3, 'vertical_v1');
    $second = StorageKeyBuilder::renderClip(7, 42, 4, 'vertical_v1');

    expect($first)->not->toBe($second);
    expect($first)->toStartWith('projects/7/renders/42/3/vertical_v1/');
    expect($second)->toStartWith('projects/7/renders/42/4/vertical_v1/');
});

it('keeps profile version as a path segment rather than deriving worker identity', function () {
    $key = StorageKeyBuilder::renderClip(11, 99, 0, 'vertical_v1');

    expect($key)->toStartWith('projects/11/renders/99/0/vertical_v1/');
    expect($key)->toEndWith('.mp4');
});
