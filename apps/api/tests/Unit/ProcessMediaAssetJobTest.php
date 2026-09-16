<?php

namespace Tests\Unit;

use App\Jobs\ProcessMediaAsset;
use App\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Job Construction (No DB required)
|--------------------------------------------------------------------------
*/

it('accepts MediaAsset and idempotency key in constructor', function () {
    $asset = new MediaAsset;
    $asset->id = 1;
    $asset->project_id = 1;
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';

    $job = new ProcessMediaAsset($asset, $idempotencyKey);

    expect($job->mediaAsset)->toBeInstanceOf(MediaAsset::class);
    expect($job->idempotencyKey)->toBe($idempotencyKey);
});

it('stores mediaAsset as public property', function () {
    $asset = new MediaAsset;
    $asset->id = 42;
    $job = new ProcessMediaAsset($asset, '550e8400-e29b-41d4-a716-446655440000');

    expect($job->mediaAsset)->toBe($asset);
});

it('stores idempotencyKey as public property', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    $job = new ProcessMediaAsset(new MediaAsset, $idempotencyKey);

    expect($job->idempotencyKey)->toBe($idempotencyKey);
});

/*
|--------------------------------------------------------------------------
| Job Interface (No DB required)
|--------------------------------------------------------------------------
*/

it('implements ShouldQueue interface', function () {
    $job = new ProcessMediaAsset(new MediaAsset, '550e8400-e29b-41d4-a716-446655440000');

    expect($job)->toBeInstanceOf(ShouldQueue::class);
});

it('has retry count of 3', function () {
    $job = new ProcessMediaAsset(new MediaAsset, '550e8400-e29b-41d4-a716-446655440000');

    expect($job->tries)->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Job Idempotency (No DB required)
|--------------------------------------------------------------------------
*/

it('uses idempotency key as unique ID for deduplication', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';
    $job = new ProcessMediaAsset(new MediaAsset, $idempotencyKey);

    expect($job->uniqueId())->toBe($idempotencyKey);
});

it('two jobs with same idempotency key have same unique ID', function () {
    $idempotencyKey = '550e8400-e29b-41d4-a716-446655440000';

    $job1 = new ProcessMediaAsset(new MediaAsset, $idempotencyKey);
    $job2 = new ProcessMediaAsset(new MediaAsset, $idempotencyKey);

    expect($job1->uniqueId())->toBe($job2->uniqueId());
});

/*
|--------------------------------------------------------------------------
| Job Properties Exist (No DB required)
|--------------------------------------------------------------------------
*/

it('has mediaAsset property', function () {
    $job = new ProcessMediaAsset(new MediaAsset, '550e8400-e29b-41d4-a716-446655440000');

    expect(isset($job->mediaAsset))->toBeTrue();
});

it('has idempotencyKey property', function () {
    $job = new ProcessMediaAsset(new MediaAsset, '550e8400-e29b-41d4-a716-446655440000');

    expect(isset($job->idempotencyKey))->toBeTrue();
});

it('has handle method', function () {
    $job = new ProcessMediaAsset(new MediaAsset, '550e8400-e29b-41d4-a716-446655440000');

    expect(method_exists($job, 'handle'))->toBeTrue();
});

it('has failed method', function () {
    $job = new ProcessMediaAsset(new MediaAsset, '550e8400-e29b-41d4-a716-446655440000');

    expect(method_exists($job, 'failed'))->toBeTrue();
});
