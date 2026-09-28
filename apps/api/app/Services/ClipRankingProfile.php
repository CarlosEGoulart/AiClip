<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;

/**
 * Trusted server configuration for the M5 semantic ranking stage.
 *
 * The specification pins the whole scoring profile. Only the provider
 * selection and the operational timeout are environment driven, and both are
 * validated strictly: an unset or unknown selection fails closed with
 * invalid_configuration instead of falling back to any provider.
 */
final class ClipRankingProfile
{
    public const SELECTOR_FAKE = 'fake';

    public const SELECTOR_CROSS_ENCODER = 'cross_encoder';

    public const TIMEOUT_MIN = 1;

    public const TIMEOUT_MAX = 120;

    public const TIMEOUT_DEFAULT = 60;

    public const LOCK_WAIT_OFFSET_SECONDS = 5;

    public const MAX_DURATION_MS = 2147483647;

    public const MAX_CANDIDATES = 1000;

    /**
     * The pinned profile values that are part of the request configuration
     * key set. Provider identity, the inference flag and criteria are
     * metadata only and never cross the request configuration boundary.
     *
     * @return list<string>
     */
    private const PROFILE_VALUE_KEYS = [
        'model_id',
        'model_revision',
        'runtime_profile',
        'normalization',
        'max_tokens',
        'batch_size',
        'truncation',
    ];

    /**
     * The exact request configuration key set, in the specification's order.
     *
     * @return list<string>
     */
    public const CONFIGURATION_KEYS = [
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
    ];

    /**
     * The trusted selection currently configured, or null when unset.
     */
    public static function selection(): mixed
    {
        return config('media.clip_ranking_provider');
    }

    public static function supports(mixed $selector): bool
    {
        if (! is_string($selector) || $selector === '') {
            return false;
        }

        $profiles = (array) config('media.clip_ranking.profiles', []);

        return is_array($profiles) && array_key_exists($selector, $profiles);
    }

    /**
     * The exact request configuration for the selected (or given) profile.
     *
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function configuration(mixed $selector = null): array
    {
        $selector ??= self::selection();

        if (! is_string($selector) || $selector === '') {
            throw self::invalidConfiguration();
        }

        $profiles = (array) config('media.clip_ranking.profiles', []);
        if (! array_key_exists($selector, $profiles) || ! is_array($profiles[$selector])) {
            throw self::invalidConfiguration();
        }

        $profile = $profiles[$selector];
        $shared = [
            'algorithm' => config('media.clip_ranking.algorithm'),
            'algorithm_version' => config('media.clip_ranking.algorithm_version'),
            'projection_version' => config('media.clip_ranking.projection_version'),
            'query_version' => config('media.clip_ranking.query_version'),
            'prototype_query' => config('media.clip_ranking.prototype_query'),
        ];

        $configuration = ['provider' => $selector] + $shared;
        foreach (self::PROFILE_VALUE_KEYS as $key) {
            $configuration[$key] = $profile[$key] ?? null;
        }

        // The published configuration is only usable when it reproduces the
        // exact specification key set with the pinned shape.
        if (array_keys($configuration) !== self::CONFIGURATION_KEYS) {
            throw self::invalidConfiguration();
        }

        if (! is_string($configuration['algorithm']) || $configuration['algorithm'] === ''
            || ! is_string($configuration['algorithm_version']) || $configuration['algorithm_version'] === ''
            || ! is_string($configuration['projection_version']) || $configuration['projection_version'] === ''
            || ! is_string($configuration['query_version']) || $configuration['query_version'] === ''
            || ! is_string($configuration['prototype_query']) || $configuration['prototype_query'] === ''
            || ! is_string($configuration['model_id']) || $configuration['model_id'] === ''
            || ! is_string($configuration['model_revision']) || $configuration['model_revision'] === ''
            || ! is_string($configuration['runtime_profile']) || $configuration['runtime_profile'] === ''
            || ! is_string($configuration['normalization']) || $configuration['normalization'] === ''
            || ! is_string($configuration['truncation']) || $configuration['truncation'] === ''
            || ! is_int($configuration['max_tokens'])
            || ! is_int($configuration['batch_size'])
        ) {
            throw self::invalidConfiguration();
        }

        return $configuration;
    }

    /**
     * The full provider identity of the selected profile, distinct from the
     * provider selector carried in the request.
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function providerName(mixed $selector = null): string
    {
        $configuration = self::configuration($selector);
        $identity = self::profileValue($configuration['provider'], 'provider_name');

        if (! is_string($identity) || $identity === '') {
            throw self::invalidConfiguration();
        }

        return $identity;
    }

    /**
     * Whether the selected profile performs real inference.
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function inferencePerformed(mixed $selector = null): bool
    {
        $configuration = self::configuration($selector);

        return self::profileValue($configuration['provider'], 'inference_performed') === true;
    }

    /**
     * The static ranking criteria metadata of the selected profile.
     *
     * @return list<string>
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function criteria(mixed $selector = null): array
    {
        $configuration = self::configuration($selector);

        return (array) self::profileValue($configuration['provider'], 'criteria');
    }

    /**
     * The strict operational worker timeout in seconds.
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function timeoutSeconds(): int
    {
        $timeout = config('media.clip_ranking_timeout_seconds', self::TIMEOUT_DEFAULT);

        if (is_string($timeout) && preg_match('/\A(?:0|[1-9][0-9]*)\z/', $timeout) === 1) {
            $timeout = (int) $timeout;
        }

        if (! is_int($timeout) || is_bool($timeout)) {
            throw self::invalidConfiguration();
        }

        if ($timeout < self::TIMEOUT_MIN || $timeout > self::TIMEOUT_MAX) {
            throw self::invalidConfiguration();
        }

        return $timeout;
    }

    /**
     * The derived lock wait: the captured timeout plus the fixed offset.
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function lockWaitSeconds(): int
    {
        return self::timeoutSeconds() + self::LOCK_WAIT_OFFSET_SECONDS;
    }

    /**
     * The exact parameters object for a validated worker result or for a
     * validated local outcome of the selected profile.
     *
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function parameters(array $configuration, bool $inferencePerformed, bool $transcriptUsed): array
    {
        $parameters = $configuration;
        unset($parameters['algorithm'], $parameters['algorithm_version']);

        $selector = $configuration['provider'] ?? null;

        return $parameters + [
            'provider_name' => self::profileValue($selector, 'provider_name'),
            'inference_performed' => $inferencePerformed,
            'transcript_used' => $transcriptUsed,
        ];
    }

    /**
     * The exact parameters key set of a worker result or local outcome.
     *
     * @return list<string>
     */
    public static function parameterKeys(): array
    {
        return [
            ...array_values(array_diff(self::CONFIGURATION_KEYS, ['algorithm', 'algorithm_version'])),
            'provider_name',
            'inference_performed',
            'transcript_used',
        ];
    }

    /**
     * Read one pinned profile value, failing closed when it is absent.
     */
    private static function profileValue(mixed $selector, string $key): mixed
    {
        $profiles = (array) config('media.clip_ranking.profiles', []);

        if (! is_string($selector) || ! isset($profiles[$selector]) || ! is_array($profiles[$selector])
            || ! array_key_exists($key, $profiles[$selector])) {
            throw self::invalidConfiguration();
        }

        return $profiles[$selector][$key];
    }

    private static function invalidConfiguration(): ProcessMediaException
    {
        return new ProcessMediaException('invalid_configuration', 1, '');
    }
}
