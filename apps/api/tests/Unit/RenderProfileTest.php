<?php

namespace Tests\Unit;

use App\Services\RenderProfile;
use App\Exceptions\ProcessMediaException;
use Tests\TestCase;

class RenderProfileTest extends TestCase
{
    public function test_configuration_returns_all_7_fields_with_spec_defaults(): void
    {
        $config = RenderProfile::configuration();

        expect($config)->toHaveKeys([
            'target_width', 'target_height', 'target_fps',
            'video_codec', 'video_bitrate_kbps',
            'audio_codec', 'audio_bitrate_kbps',
        ]);

        expect($config['target_width'])->toBe(1080);
        expect($config['target_height'])->toBe(1920);
        expect($config['target_fps'])->toBe(30);
        expect($config['video_codec'])->toBe('libx264');
        expect($config['video_bitrate_kbps'])->toBe(5000);
        expect($config['audio_codec'])->toBe('aac');
        expect($config['audio_bitrate_kbps'])->toBe(128);
    }

    public function test_timeout_seconds_returns_300_default(): void
    {
        $timeout = RenderProfile::timeoutSeconds();
        expect($timeout)->toBe(300);
        expect($timeout)->toBeInt();
    }

    public function test_timeout_seconds_rejects_non_canonical_decimal_string(): void
    {
        config(['media.render_timeout_seconds' => '300.0']);

        try {
            RenderProfile::timeoutSeconds();
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_timeout_seconds_rejects_below_min(): void
    {
        config(['media.render_timeout_seconds' => 29]);

        try {
            RenderProfile::timeoutSeconds();
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_timeout_seconds_rejects_above_max(): void
    {
        config(['media.render_timeout_seconds' => 1801]);

        try {
            RenderProfile::timeoutSeconds();
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_timeout_seconds_rejects_null(): void
    {
        config(['media.render_timeout_seconds' => null]);

        try {
            RenderProfile::timeoutSeconds();
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_timeout_seconds_rejects_float(): void
    {
        config(['media.render_timeout_seconds' => 300.5]);

        try {
            RenderProfile::timeoutSeconds();
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_timeout_seconds_rejects_bool(): void
    {
        config(['media.render_timeout_seconds' => true]);

        try {
            RenderProfile::timeoutSeconds();
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_lock_wait_seconds_equals_timeout_plus_10(): void
    {
        config(['media.render_timeout_seconds' => 300]);

        $timeout = RenderProfile::timeoutSeconds();
        $lockWait = RenderProfile::lockWaitSeconds();

        expect($lockWait)->toBe($timeout + 10);
    }

    public function test_lock_wait_seconds_with_custom_timeout(): void
    {
        config(['media.render_timeout_seconds' => 120]);

        $timeout = RenderProfile::timeoutSeconds();
        $lockWait = RenderProfile::lockWaitSeconds();

        expect($lockWait)->toBe($timeout + 10);
        expect($lockWait)->toBe(130);
    }

    public function test_configuration_validation_rejects_odd_target_width(): void
    {
        try {
            RenderProfile::validateConfiguration([
                'target_width' => 1081,
                'target_height' => 1920,
                'target_fps' => 30,
                'video_codec' => 'libx264',
                'video_bitrate_kbps' => 5000,
                'audio_codec' => 'aac',
                'audio_bitrate_kbps' => 128,
            ]);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_configuration_validation_rejects_invalid_video_codec(): void
    {
        try {
            RenderProfile::validateConfiguration([
                'target_width' => 1080,
                'target_height' => 1920,
                'target_fps' => 30,
                'video_codec' => 'invalid',
                'video_bitrate_kbps' => 5000,
                'audio_codec' => 'aac',
                'audio_bitrate_kbps' => 128,
            ]);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_configuration_validation_rejects_invalid_audio_codec(): void
    {
        try {
            RenderProfile::validateConfiguration([
                'target_width' => 1080,
                'target_height' => 1920,
                'target_fps' => 30,
                'video_codec' => 'libx264',
                'video_bitrate_kbps' => 5000,
                'audio_codec' => 'invalid',
                'audio_bitrate_kbps' => 128,
            ]);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('invalid_configuration');
        }
    }

    public function test_configuration_validation_accepts_all_valid_video_codecs(): void
    {
        foreach (['libx264', 'libx265', 'h264_videotoolbox', 'hevc_videotoolbox'] as $codec) {
            RenderProfile::validateConfiguration([
                'target_width' => 1080,
                'target_height' => 1920,
                'target_fps' => 30,
                'video_codec' => $codec,
                'video_bitrate_kbps' => 5000,
                'audio_codec' => 'aac',
                'audio_bitrate_kbps' => 128,
            ]);
        }
    }

    public function test_configuration_validation_accepts_all_valid_audio_codecs(): void
    {
        foreach (['aac', 'libfdk_aac', 'copy'] as $codec) {
            RenderProfile::validateConfiguration([
                'target_width' => 1080,
                'target_height' => 1920,
                'target_fps' => 30,
                'video_codec' => 'libx264',
                'video_bitrate_kbps' => 5000,
                'audio_codec' => $codec,
                'audio_bitrate_kbps' => 128,
            ]);
        }
    }

    public function test_algorithm_constant_is_vertical(): void
    {
        expect(RenderProfile::ALGORITHM)->toBe('vertical');
    }

    public function test_algorithm_version_constant_is_vertical_v1(): void
    {
        expect(RenderProfile::ALGORITHM_VERSION)->toBe('vertical_v1');
    }

    public function test_render_profile_version_constant_is_vertical_v1(): void
    {
        expect(RenderProfile::RENDER_PROFILE_VERSION)->toBe('vertical_v1');
    }

    public function test_lock_wait_offset_seconds_is_10(): void
    {
        expect(RenderProfile::LOCK_WAIT_OFFSET_SECONDS)->toBe(10);
    }
}