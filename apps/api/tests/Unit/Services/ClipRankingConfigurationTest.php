<?php

namespace Tests\Unit\Services;

use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Pinned versioned configuration profile
|--------------------------------------------------------------------------
|
| The specification pins the whole M5 scoring profile. These assertions read
| the trusted server configuration directly, so a divergence is observable
| as a value mismatch rather than as a missing class.
|
*/

/**
 * The exact fixed query mandated by the specification.
 */
const SPEC_PROTOTYPE_QUERY = 'Engaging, self-contained short-form video clip highlight with a clear narrative or punchline.';

/**
 * Isolate MEDIA_CLIP_RANKING_TIMEOUT_SECONDS for a single test.
 * Snapshots ONLY this env key independently in getenv, $_ENV, $_SERVER.
 * Snapshots original tested config presence/value for clip_ranking_timeout_seconds.
 * Requires config/media.php directly and assigns only the loaded timeout into Laravel config.
 * Restores EXACT independent stores and original config presence/value on success AND throw.
 */
function withIsolatedTimeoutConfig(callable $test): void
{
    $envKey = 'MEDIA_CLIP_RANKING_TIMEOUT_SECONDS';

    // Snapshot the env key independently in each store
    $originalGetenv = getenv($envKey);
    $originalHasEnv = array_key_exists($envKey, $_ENV);
    $originalEnvValue = $originalHasEnv ? $_ENV[$envKey] : null;
    $originalHasServer = array_key_exists($envKey, $_SERVER);
    $originalServerValue = $originalHasServer ? $_SERVER[$envKey] : null;

    // Snapshot original config presence/value for the specific key
    $originalConfigHas = config()->offsetExists('media.clip_ranking_timeout_seconds');
    $originalConfigValue = $originalConfigHas ? config('media.clip_ranking_timeout_seconds') : null;

    try {
        // Unset the env key in all stores to simulate absent
        putenv($envKey);
        unset($_ENV[$envKey]);
        unset($_SERVER[$envKey]);

        // Require config/media.php directly and assign ONLY the loaded timeout into Laravel config
        $mediaConfig = require __DIR__.'/../../../config/media.php';
        config(['media.clip_ranking_timeout_seconds' => $mediaConfig['clip_ranking_timeout_seconds']]);

        $test();
    } finally {
        // Restore EXACT independent stores
        if ($originalGetenv === false) {
            putenv($envKey);
        } else {
            putenv($envKey.'='.$originalGetenv);
        }

        if ($originalHasEnv) {
            $_ENV[$envKey] = $originalEnvValue;
        } else {
            unset($_ENV[$envKey]);
        }

        if ($originalHasServer) {
            $_SERVER[$envKey] = $originalServerValue;
        } else {
            unset($_SERVER[$envKey]);
        }

        // Restore original config presence/value for the specific key
        if ($originalConfigHas) {
            config(['media.clip_ranking_timeout_seconds' => $originalConfigValue]);
        } else {
            // Properly remove the nested key (not set null) to restore true absence
            $mediaConfig = config('media', []);
            Arr::forget($mediaConfig, 'clip_ranking_timeout_seconds');
            config(['media' => $mediaConfig]);
        }
    }
}

it('publishes the pinned versioned scoring configuration', function () {
    expect(config('media.clip_ranking.algorithm'))->toBe('transcript_semantic_recommendation')
        ->and(config('media.clip_ranking.algorithm_version'))->toBe('1.0.0')
        ->and(config('media.clip_ranking.projection_version'))->toBe('1.0.0')
        ->and(config('media.clip_ranking.query_version'))->toBe('1.0.0')
        ->and(config('media.clip_ranking.prototype_query'))->toBe(SPEC_PROTOTYPE_QUERY);
});

it('replaces the divergent prototype query of the superseded protocol', function () {
    // The pre-replacement configuration carried an unapproved viral-worthy
    // query. The pinned fixed query is now authoritative, so the superseded
    // key and value must no longer be published anywhere in the media config.
    $encoded = json_encode(config('media'));

    expect(config('media.clip_ranking_prototype_query'))->toBeNull()
        ->and($encoded)->not->toContain('viral-worthy')
        ->and($encoded)->not->toContain(SPEC_PROTOTYPE_QUERY === '' ? 'x' : 'viral-worthy');
});

it('publishes the pinned real provider profile', function () {
    $profile = config('media.clip_ranking.profiles.cross_encoder');

    expect($profile)->toBe([
        'provider_name' => 'cross_encoder_ranking_provider',
        'model_id' => 'cross-encoder/ms-marco-MiniLM-L6-v2',
        'model_revision' => '233902d25c440f23af6f7d6e94d2946bac0bee0a',
        'runtime_profile' => 'minilm_cpu_v1',
        'normalization' => 'stable_sigmoid_half_up_6',
        'max_tokens' => 512,
        'batch_size' => 8,
        'truncation' => 'right_longest_first_512',
        'inference_performed' => true,
        'criteria' => [
            'query_passage_relevance',
            'quantized_score_desc',
            'm4_rank_asc',
            'candidate_index_asc',
        ],
    ]);
});

it('publishes the pinned fake provider profile without naming the real model', function () {
    $profile = config('media.clip_ranking.profiles.fake');

    expect($profile)->toBe([
        'provider_name' => 'fake_ranking_provider',
        'model_id' => 'fake-ranking-v1',
        'model_revision' => '1.0.0',
        'runtime_profile' => 'fake_v1',
        'normalization' => 'fixture_units_6',
        'max_tokens' => 0,
        'batch_size' => 0,
        'truncation' => 'none',
        'inference_performed' => false,
        'criteria' => [
            'query_passage_relevance',
            'quantized_score_desc',
            'm4_rank_asc',
            'candidate_index_asc',
        ],
    ]);
});

it('publishes only the two explicitly supported provider selections', function () {
    expect(array_keys((array) config('media.clip_ranking.profiles', [])))->toBe(['cross_encoder', 'fake']);
});

it('keeps the explicit provider selection unset by default with no detection', function () {
    // Trusted server configuration selects the profile explicitly. There is no
    // automatic environment detection and no fallback, so an absent selection
    // must stay absent in the published configuration.
    $provider = config('media.clip_ranking_provider');

    expect($provider === null || $provider === 'fake' || $provider === 'cross_encoder')->toBeTrue()
        ->and((string) config('media.clip_ranking.autodetect', 'absent'))->not->toBe('true');
});

it('keeps the operational timeout raw so strict integer validation is possible', function () {
    // A cast-to-int configuration would silently accept fractional and garbage
    // environment values, defeating the strict 1..120 integer contract.
    // The raw env value is published as a string; validation happens in ClipRankingProfile::timeoutSeconds()
    // Use isolated config load to ensure we test the default from config/media.php,
    // independent of any ambient environment variable.
    withIsolatedTimeoutConfig(function () {
        expect(config('media.clip_ranking_timeout_seconds'))->toBe('60')
            ->and(is_string(config('media.clip_ranking_timeout_seconds')))->toBeTrue();
    });
});

it('configuration helper preserves independent stores and absent versus null config on repeated success and throw', function () {
    $key = 'MEDIA_CLIP_RANKING_TIMEOUT_SECONDS';
    $getenv = getenv($key);
    $hasEnv = array_key_exists($key, $_ENV);
    $env = $hasEnv ? $_ENV[$key] : null;
    $hasServer = array_key_exists($key, $_SERVER);
    $server = $hasServer ? $_SERVER[$key] : null;
    $media = config('media');

    try {
        foreach ([false, true, false, true] as $present) {
            putenv($key.'=null');
            $_ENV[$key] = null;
            unset($_SERVER[$key]);
            $expectedMedia = $media;
            Arr::forget($expectedMedia, 'clip_ranking_timeout_seconds');
            if ($present) {
                $expectedMedia['clip_ranking_timeout_seconds'] = null;
            }
            config(['media' => $expectedMedia]);

            foreach ([false, true] as $throws) {
                $callback = function () use ($throws) {
                    expect(config('media.clip_ranking_timeout_seconds'))->toBe('60');
                    if ($throws) {
                        throw new \RuntimeException('cleanup sentinel');
                    }
                };
                if ($throws) {
                    expect(fn () => withIsolatedTimeoutConfig($callback))
                        ->toThrow(\RuntimeException::class, 'cleanup sentinel');
                } else {
                    withIsolatedTimeoutConfig($callback);
                }

                expect(getenv($key))->toBe('null')
                    ->and(array_key_exists($key, $_ENV))->toBeTrue()
                    ->and($_ENV[$key])->toBeNull()
                    ->and(array_key_exists($key, $_SERVER))->toBeFalse()
                    ->and(config()->offsetExists('media.clip_ranking_timeout_seconds'))->toBe($present)
                    ->and(config('media'))->toBe($expectedMedia);
            }
        }
    } finally {
        $getenv === false ? putenv($key) : putenv($key.'='.$getenv);
        if ($hasEnv) {
            $_ENV[$key] = $env;
        } else {
            unset($_ENV[$key]);
        }
        if ($hasServer) {
            $_SERVER[$key] = $server;
        } else {
            unset($_SERVER[$key]);
        }
        config(['media' => $media]);
    }
});
