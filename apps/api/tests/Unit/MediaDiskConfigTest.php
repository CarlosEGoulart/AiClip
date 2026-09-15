<?php

use Tests\TestCase;

uses(TestCase::class);

it('has the media disk in filesystem configuration', function () {
    $disks = config('filesystems.disks');
    expect($disks)->toHaveKey('media');
});

it('uses the s3 driver for the media disk', function () {
    $driver = config('filesystems.disks.media.driver');
    expect($driver)->toBe('s3');
});

it('uses path-style endpoints by default for MinIO compatibility', function () {
    $usePathStyle = config('filesystems.disks.media.use_path_style_endpoint');
    expect($usePathStyle)->toBeTrue();
});

it('has throw enabled for the media disk', function () {
    $throw = config('filesystems.disks.media.throw');
    expect($throw)->toBeTrue();
});
