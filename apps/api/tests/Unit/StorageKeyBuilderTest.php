<?php

namespace Tests\Unit;

use App\Services\StorageKeyBuilder;
use InvalidArgumentException;
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

/*
|--------------------------------------------------------------------------
| captionFile() — TP-04
|--------------------------------------------------------------------------
*/

it('captionFile builds project-scoped caption key with UUID suffix', function () {
    $key = StorageKeyBuilder::captionFile(
        projectId: 7,
        mediaAssetId: 42,
        candidateIndex: 3,
        renderProfileVersion: 'vertical_v1',
    );

    expect($key)->toMatch(
        '#^projects/7/captions/42/3/vertical_v1/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.srt$#i'
    );
});

it('captionFile generates a fresh UUID for a new durable caption identity', function () {
    $first = StorageKeyBuilder::captionFile(7, 42, 3, 'vertical_v1');
    $second = StorageKeyBuilder::captionFile(7, 42, 4, 'vertical_v1');

    expect($first)->not->toBe($second);
    expect($first)->toStartWith('projects/7/captions/42/3/vertical_v1/');
    expect($second)->toStartWith('projects/7/captions/42/4/vertical_v1/');
});

it('captionFile keeps profile version as a path segment', function () {
    $key = StorageKeyBuilder::captionFile(11, 99, 0, 'vertical_v1');

    expect($key)->toStartWith('projects/11/captions/99/0/vertical_v1/');
    expect($key)->toEndWith('.srt');
});

it('captionFile returns .srt extension', function () {
    $key = StorageKeyBuilder::captionFile(1, 1, 0, 'vertical_v1');

    expect($key)->toEndWith('.srt');
});

it('captionFile throws when projectId < 1', function () {
    StorageKeyBuilder::captionFile(0, 1, 0, 'vertical_v1');
})->throws(InvalidArgumentException::class, 'Invalid render storage identity.');

it('captionFile throws when mediaAssetId < 1', function () {
    StorageKeyBuilder::captionFile(1, 0, 0, 'vertical_v1');
})->throws(InvalidArgumentException::class, 'Invalid render storage identity.');

it('captionFile throws when candidateIndex < 0', function () {
    StorageKeyBuilder::captionFile(1, 1, -1, 'vertical_v1');
})->throws(InvalidArgumentException::class, 'Invalid render storage identity.');

it('captionFile throws when renderProfileVersion is empty', function () {
    StorageKeyBuilder::captionFile(1, 1, 0, '');
})->throws(InvalidArgumentException::class, 'Invalid render profile version.');

it('captionFile throws when renderProfileVersion has invalid characters', function () {
    StorageKeyBuilder::captionFile(1, 1, 0, 'invalid/version');
})->throws(InvalidArgumentException::class, 'Invalid render profile version.');
