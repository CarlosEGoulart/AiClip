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

it('all migrations are reversible (have down methods)', function () {
    $migrationFiles = glob(database_path('migrations/*.php'));

    expect($migrationFiles)->not->toBeEmpty();

    foreach ($migrationFiles as $file) {
        $contents = file_get_contents($file);
        expect($contents)->toContain('function down', 'Migration ' . basename($file) . ' is missing a down() method');
    }
});
