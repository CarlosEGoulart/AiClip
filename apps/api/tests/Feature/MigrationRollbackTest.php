<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('migrate:fresh succeeds and test schema loads cleanly', function () {
    $exitCode = Artisan::call('migrate:fresh', ['--force' => true]);

    expect($exitCode)->toBe(0);
});

it('migrate:rollback succeeds and migrations reverse cleanly', function () {
    Artisan::call('migrate:fresh', ['--force' => true]);

    $exitCode = Artisan::call('migrate:rollback');

    expect($exitCode)->toBe(0);
});
