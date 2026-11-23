<?php

namespace Tests\Unit\Contracts;

use App\Contracts\YouTubeImportProvider;
use App\Services\FakeYouTubeImportProvider;
use ReflectionClass;
use Tests\TestCase;

uses(TestCase::class);

/*
 * TC-BE-01 (test-plan §1.1): YouTubeImportProvider contract — interface
 * shape. Plain unit test — no HTTP, no database. Verifies the contract
 * exposes the required methods with the correct signatures.
 */

it('exposes validateUrl with the correct signature', function () {
    $reflection = new ReflectionClass(YouTubeImportProvider::class);

    $method = $reflection->getMethod('validateUrl');
    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBe(1);

    $param = $method->getParameters()[0];
    expect($param->getName())->toBe('url');
    expect($param->getType()->getName())->toBe('string');
    expect($method->getReturnType()->getName())->toBe('bool');
});

it('exposes extractMetadata with the correct signature', function () {
    $reflection = new ReflectionClass(YouTubeImportProvider::class);

    $method = $reflection->getMethod('extractMetadata');
    expect($method->isPublic())->toBeTrue();
    expect($method->getNumberOfParameters())->toBe(1);

    $param = $method->getParameters()[0];
    expect($param->getName())->toBe('url');
    expect($param->getType()->getName())->toBe('string');
    expect($method->getReturnType()->getName())->toBe('array');
});

it('is implemented by FakeYouTubeImportProvider', function () {
    $provider = new FakeYouTubeImportProvider;

    expect($provider)->toBeInstanceOf(YouTubeImportProvider::class);
});
