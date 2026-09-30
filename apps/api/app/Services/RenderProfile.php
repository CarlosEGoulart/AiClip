<?php

namespace App\Services;

use App\Exceptions\ProcessMediaException;

/**
 * Trusted server configuration for the M6.1 baseline vertical clip render stage.
 *
 * The specification pins the whole rendering profile. Only the operational
 * timeout is environment driven, and it is validated strictly.
 */
final class RenderProfile
{
    public const ALGORITHM = 'vertical';
    public const ALGORITHM_VERSION = 'vertical_v1';
    public const RENDER_PROFILE_VERSION = 'vertical_v1';

    public const TIMEOUT_MIN = 30;
    public const TIMEOUT_MAX = 1800;
    public const TIMEOUT_DEFAULT = 300;
    public const LOCK_WAIT_OFFSET_SECONDS = 10;

    public const MAX_DURATION_MS = 2147483647;
    public const MAX_RECOMMENDATIONS = 1000;
    public const MAX_INPUT_BYTES = 8388608;

    /**
     * The exact render configuration key set, in the specification's order.
     *
     * @return list<string>
     */
    public const CONFIGURATION_KEYS = [
        'target_width',
        'target_height',
        'target_fps',
        'video_codec',
        'video_bitrate_kbps',
        'audio_codec',
        'audio_bitrate_kbps',
    ];

    public const VALID_VIDEO_CODECS = [
        'libx264',
        'libx265',
        'h264_videotoolbox',
        'hevc_videotoolbox',
    ];

    public const VALID_AUDIO_CODECS = [
        'aac',
        'libfdk_aac',
        'copy',
    ];

    /**
     * The exact render configuration for the baseline profile.
     *
     * @return array<string, mixed>
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function configuration(): array
    {
        $configuration = [
            'target_width' => (int) config('media.render_target_width', 1080),
            'target_height' => (int) config('media.render_target_height', 1920),
            'target_fps' => (int) config('media.render_target_fps', 30),
            'video_codec' => config('media.render_video_codec', 'libx264'),
            'video_bitrate_kbps' => (int) config('media.render_video_bitrate_kbps', 5000),
            'audio_codec' => config('media.render_audio_codec', 'aac'),
            'audio_bitrate_kbps' => (int) config('media.render_audio_bitrate_kbps', 128),
        ];

        // Validate configuration matches exact key set
        if (array_keys($configuration) !== self::CONFIGURATION_KEYS) {
            throw self::invalidConfiguration();
        }

        // Validate each field
        self::validateConfiguration($configuration);

        return $configuration;
    }

    /**
     * Validate the render configuration.
     *
     * @param  array<string, mixed>  $configuration
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function validateConfiguration(array $configuration): void
    {
        // target_width: even integer 1..4096
        $targetWidth = $configuration['target_width'] ?? null;
        if (! is_int($targetWidth) || $targetWidth < 1 || $targetWidth > 4096 || $targetWidth % 2 !== 0) {
            throw self::invalidConfiguration();
        }

        // target_height: even integer 1..4096
        $targetHeight = $configuration['target_height'] ?? null;
        if (! is_int($targetHeight) || $targetHeight < 1 || $targetHeight > 4096 || $targetHeight % 2 !== 0) {
            throw self::invalidConfiguration();
        }

        // target_fps: integer 1..120
        $targetFps = $configuration['target_fps'] ?? null;
        if (! is_int($targetFps) || $targetFps < 1 || $targetFps > 120) {
            throw self::invalidConfiguration();
        }

        // video_codec: enum
        $videoCodec = $configuration['video_codec'] ?? null;
        if (! is_string($videoCodec) || ! in_array($videoCodec, self::VALID_VIDEO_CODECS, true)) {
            throw self::invalidConfiguration();
        }

        // video_bitrate_kbps: integer 500..50000
        $videoBitrate = $configuration['video_bitrate_kbps'] ?? null;
        if (! is_int($videoBitrate) || $videoBitrate < 500 || $videoBitrate > 50000) {
            throw self::invalidConfiguration();
        }

        // audio_codec: enum
        $audioCodec = $configuration['audio_codec'] ?? null;
        if (! is_string($audioCodec) || ! in_array($audioCodec, self::VALID_AUDIO_CODECS, true)) {
            throw self::invalidConfiguration();
        }

        // audio_bitrate_kbps: integer 32..320
        $audioBitrate = $configuration['audio_bitrate_kbps'] ?? null;
        if (! is_int($audioBitrate) || $audioBitrate < 32 || $audioBitrate > 320) {
            throw self::invalidConfiguration();
        }
    }

    /**
     * The strict operational worker timeout in seconds.
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function timeoutSeconds(): int
    {
        $timeout = config('media.render_timeout_seconds', self::TIMEOUT_DEFAULT);

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
     * The derived lock wait: the captured timeout plus the fixed offset (10 seconds).
     *
     * @throws ProcessMediaException invalid_configuration
     */
    public static function lockWaitSeconds(): int
    {
        return self::timeoutSeconds() + self::LOCK_WAIT_OFFSET_SECONDS;
    }

    /**
     * The exact parameters key set for a completed render.
     *
     * @return list<string>
     */
    public static function parameterKeys(): array
    {
        return [
            'configuration',
            'source_media',
            'ffmpeg_version',
            'filter_graph',
            'limits',
        ];
    }

    private static function invalidConfiguration(): ProcessMediaException
    {
        return new ProcessMediaException('invalid_configuration', 1, '');
    }
}