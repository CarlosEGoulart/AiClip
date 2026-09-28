<?php

namespace Tests\Unit\Services;

use App\Exceptions\ProcessMediaException;
use App\Services\ClipRankingProfile;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

const PROFILE_SPEC_QUERY = 'Engaging, self-contained short-form video clip highlight with a clear narrative or punchline.';

function expectInvalidConfiguration(mixed $selector): void
{
    $failure = null;

    try {
        ClipRankingProfile::configuration($selector);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class)
        ->and($failure->getMessage())->toBe('invalid_configuration')
        ->and($failure->getPrevious())->toBeNull()
        ->and($failure->stderr)->toBe('');
}

/*
|--------------------------------------------------------------------------
| Pinned profiles
|--------------------------------------------------------------------------
*/

it('publishes the pinned real ranking profile exactly as specified', function () {
    expect(ClipRankingProfile::configuration('cross_encoder'))->toBe([
        'provider' => 'cross_encoder',
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'projection_version' => '1.0.0',
        'query_version' => '1.0.0',
        'prototype_query' => PROFILE_SPEC_QUERY,
        'model_id' => 'cross-encoder/ms-marco-MiniLM-L6-v2',
        'model_revision' => '233902d25c440f23af6f7d6e94d2946bac0bee0a',
        'runtime_profile' => 'minilm_cpu_v1',
        'normalization' => 'stable_sigmoid_half_up_6',
        'max_tokens' => 512,
        'batch_size' => 8,
        'truncation' => 'right_longest_first_512',
    ]);
});

it('publishes the pinned fake ranking profile exactly as specified', function () {
    expect(ClipRankingProfile::configuration('fake'))->toBe([
        'provider' => 'fake',
        'algorithm' => 'transcript_semantic_recommendation',
        'algorithm_version' => '1.0.0',
        'projection_version' => '1.0.0',
        'query_version' => '1.0.0',
        'prototype_query' => PROFILE_SPEC_QUERY,
        'model_id' => 'fake-ranking-v1',
        'model_revision' => '1.0.0',
        'runtime_profile' => 'fake_v1',
        'normalization' => 'fixture_units_6',
        'max_tokens' => 0,
        'batch_size' => 0,
        'truncation' => 'none',
    ]);
});

it('exposes the exact request configuration key set for every supported profile', function (string $selector) {
    expect(array_keys(ClipRankingProfile::configuration($selector)))->toBe([
        'provider',
        'algorithm',
        'algorithm_version',
        'projection_version',
        'query_version',
        'prototype_query',
        'model_id',
        'model_revision',
        'runtime_profile',
        'normalization',
        'max_tokens',
        'batch_size',
        'truncation',
    ]);
})->with(['cross_encoder', 'fake']);

it('exposes truthful provider identity and inference flag per selected profile', function () {
    expect(ClipRankingProfile::providerName('cross_encoder'))->toBe('cross_encoder_ranking_provider')
        ->and(ClipRankingProfile::inferencePerformed('cross_encoder'))->toBeTrue()
        ->and(ClipRankingProfile::providerName('fake'))->toBe('fake_ranking_provider')
        ->and(ClipRankingProfile::inferencePerformed('fake'))->toBeFalse();
});

it('never names the real model in fake profile metadata', function () {
    $configuration = ClipRankingProfile::configuration('fake');

    expect($configuration['model_id'])->not->toBe('cross-encoder/ms-marco-MiniLM-L6-v2')
        ->and($configuration['runtime_profile'])->not->toBe('minilm_cpu_v1')
        ->and($configuration['normalization'])->not->toBe('stable_sigmoid_half_up_6')
        ->and(json_encode($configuration))->not->toContain('cross-encoder')
        ->and(json_encode(ClipRankingProfile::providerName('fake')))->not->toContain('cross_encoder_ranking_provider');
});

it('exposes identical ranking criteria metadata for both profiles', function () {
    expect(ClipRankingProfile::criteria('cross_encoder'))->toBe(ClipRankingProfile::criteria('fake'))
        ->and(ClipRankingProfile::criteria('fake'))->toBe([
            'query_passage_relevance',
            'quantized_score_desc',
            'm4_rank_asc',
            'candidate_index_asc',
        ]);
});

/*
|--------------------------------------------------------------------------
| Explicit selection, no detection and no fallback
|--------------------------------------------------------------------------
*/

it('fails invalid_configuration for an unset provider selection', function () {
    config(['media.clip_ranking_provider' => null]);

    expectInvalidConfiguration(null);
});

it('fails invalid_configuration for an unknown provider selection', function (string $selector) {
    config(['media.clip_ranking_provider' => $selector]);

    expectInvalidConfiguration(null);
})->with([
    'unknown name' => ['random'],
    'real provider identity instead of selector' => ['cross_encoder_ranking_provider'],
    'wrong case' => ['Cross_Encoder'],
    'padded' => [' cross_encoder '],
    'boolean true' => [''],
]);

it('fails invalid_configuration for an unset selection even when the fake profile is available', function () {
    // No automatic environment detection and no fallback: an unset trusted
    // selection must fail on a new attempt even though a valid profile exists.
    config(['media.clip_ranking_provider' => null]);

    $failure = null;

    try {
        ClipRankingProfile::configuration();
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class)
        ->and($failure->getMessage())->toBe('invalid_configuration')
        ->and(ClipRankingProfile::providerName('fake'))->toBe('fake_ranking_provider');
});

it('fails invalid_configuration for a non-string provider selection', function (mixed $selector) {
    config(['media.clip_ranking_provider' => $selector]);

    expectInvalidConfiguration(null);
})->with([
    'integer' => [1],
    'boolean' => [true],
    'array' => [['cross_encoder']],
    'float' => [1.0],
]);

/*
|--------------------------------------------------------------------------
| Operational timeout
|--------------------------------------------------------------------------
*/

it('derives the lock wait from the strict operational timeout exactly once', function (int $timeout) {
    config(['media.clip_ranking_timeout_seconds' => $timeout]);

    expect(ClipRankingProfile::timeoutSeconds())->toBe($timeout)
        ->and(ClipRankingProfile::lockWaitSeconds())->toBe($timeout + 5);
})->with([1, 60, 120]);

it('accepts a canonical decimal string timeout supplied by the environment', function () {
    config(['media.clip_ranking_timeout_seconds' => '45']);

    expect(ClipRankingProfile::timeoutSeconds())->toBe(45)
        ->and(ClipRankingProfile::lockWaitSeconds())->toBe(50);
});

it('fails invalid_configuration for a non-strict or out-of-range timeout', function (mixed $value) {
    config(['media.clip_ranking_timeout_seconds' => $value]);

    $failure = null;

    try {
        ClipRankingProfile::timeoutSeconds();
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class)
        ->and($failure->getMessage())->toBe('invalid_configuration');
})->with([
    'zero' => [0],
    'negative' => [-1],
    'above maximum' => [121],
    'far above maximum' => [200],
    'float' => [1.5],
    'float string' => ['1.5'],
    'boolean true' => [true],
    'boolean false' => [false],
    'garbage string' => ['abc'],
    'empty string' => [''],
    'array' => [[60]],
    'null' => [null],
]);

it('rejects leading zeros in canonical decimal string', function (string $value) {
    config(['media.clip_ranking_timeout_seconds' => $value]);

    $failure = null;

    try {
        ClipRankingProfile::timeoutSeconds();
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class)
        ->and($failure->getMessage())->toBe('invalid_configuration');
})->with([
    'leading zero' => '045',
    'multiple leading zeros' => '0045',
]);

it('rejects trailing whitespace and control characters in canonical decimal string', function (string $value) {
    config(['media.clip_ranking_timeout_seconds' => $value]);

    $failure = null;

    try {
        ClipRankingProfile::timeoutSeconds();
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeInstanceOf(ProcessMediaException::class)
        ->and($failure->getMessage())->toBe('invalid_configuration');
})->with([
    'trailing LF' => "45\n",
    'trailing CR' => "45\r",
    'trailing CRLF' => "45\r\n",
    'leading space' => ' 45',
    'trailing space' => '45 ',
    'tab' => "\t45",
]);

it('reports the fixed operational bounds and default of the specification', function () {
    expect(ClipRankingProfile::TIMEOUT_MIN)->toBe(1)
        ->and(ClipRankingProfile::TIMEOUT_MAX)->toBe(120)
        ->and(ClipRankingProfile::TIMEOUT_DEFAULT)->toBe(60)
        ->and(ClipRankingProfile::LOCK_WAIT_OFFSET_SECONDS)->toBe(5);
});
