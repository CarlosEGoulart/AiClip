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
        expect($contents)->not->toBeFalse();

        // Use the PHP tokenizer to check for a function down() declaration,
        // which is more robust than naive string matching across formatting
        // variations (PSR-12, blank lines, comments between function and name).
        $tokens = token_get_all($contents);
        $hasDownMethod = false;
        foreach ($tokens as $i => $token) {
            if (is_array($token) && $token[0] === T_FUNCTION) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    $next = $tokens[$j];
                    if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (is_string($next) && $next === 'down') {
                        $hasDownMethod = true;
                        break 2;
                    }
                    break;
                }
            }
        }

        expect($hasDownMethod)->toBeTrue(
            'Migration ' . basename($file) . ' is missing a down() method'
        );
    }
});
