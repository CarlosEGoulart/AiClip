<?php

namespace Tests\Unit;

use App\Services\RenderProfile;
use App\Exceptions\ProcessMediaException;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| RenderProfile configuration and timeout validation
|--------------------------------------------------------------------------
*/

it('returns the exact configuration with spec defaults', function () {
    $config = RenderProfile::configuration();

    expect($config)->toBe([
        'target_width' => 1080,
        'target_height' => 1920,
        'target_fps' => 30,
        'video_codec' => 'libx264',
        'video_bitrate_kbps' => 5000,
        'audio_codec' => 'aac',
        'audio_bitrate_kbps' => 128,
    ]);
});

it('returns all seven configuration keys in specification order', function () {
    $config = RenderProfile::configuration();

    expect(array_keys($config))->toBe(RenderProfile::CONFIGURATION_KEYS);
});

it('returns timeoutSeconds default of 300', function () {
    config(['media.render_timeout_seconds' => '300']);

    expect(RenderProfile::timeoutSeconds())->toBe(300);
});

it('rejects non-canonical decimal string for timeoutSeconds', function () {
    config(['media.render_timeout_seconds' => '300.0']);

    try {
        RenderProfile::timeoutSeconds();
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects timeoutSeconds below minimum (30)', function () {
    config(['media.render_timeout_seconds' => '29']);

    try {
        RenderProfile::timeoutSeconds();
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects timeoutSeconds above maximum (300)', function () {
    config(['media.render_timeout_seconds' => '301']);

    try {
        RenderProfile::timeoutSeconds();
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects null timeoutSeconds', function () {
    config(['media.render_timeout_seconds' => null]);

    try {
        RenderProfile::timeoutSeconds();
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects float timeoutSeconds', function () {
    config(['media.render_timeout_seconds' => 300.0]);

    try {
        RenderProfile::timeoutSeconds();
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects boolean timeoutSeconds', function () {
    config(['media.render_timeout_seconds' => true]);

    try {
        RenderProfile::timeoutSeconds();
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('derives lockWaitSeconds as timeout + 10', function () {
    config(['media.render_timeout_seconds' => '300']);

    expect(RenderProfile::lockWaitSeconds())->toBe(310);
});

it('exposes RENDER_PROFILE_VERSION = vertical_v1', function () {
    expect(RenderProfile::RENDER_PROFILE_VERSION)->toBe('vertical_v1');
});

it('exposes TIMEOUT_MAX = 300', function () {
    expect(RenderProfile::TIMEOUT_MAX)->toBe(300);
});

it('exposes LOCK_WAIT_OFFSET_SECONDS = 10', function () {
    expect(RenderProfile::LOCK_WAIT_OFFSET_SECONDS)->toBe(10);
});

it('validates target_width must be even integer 1..4096', function () {
    $config = RenderProfile::configuration();

    // Valid
    $config['target_width'] = 1080;
    RenderProfile::validateConfiguration($config);

    // Odd - should fail
    $config['target_width'] = 1081;
    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validates target_height must be even integer 1..4096', function () {
    $config = RenderProfile::configuration();

    // Valid
    $config['target_height'] = 1920;
    RenderProfile::validateConfiguration($config);

    // Odd - should fail
    $config['target_height'] = 1921;
    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validates target_fps must be integer 1..120', function () {
    $config = RenderProfile::configuration();

    // Valid
    $config['target_fps'] = 30;
    RenderProfile::validateConfiguration($config);

    // Out of bounds
    $config['target_fps'] = 121;
    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validates video_codec enum', function () {
    $config = RenderProfile::configuration();

    foreach (RenderProfile::VALID_VIDEO_CODECS as $codec) {
        $config['video_codec'] = $codec;
        RenderProfile::validateConfiguration($config);
    }

    $config['video_codec'] = 'invalid_codec';
    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validates video_bitrate_kbps range 500..50000', function () {
    $config = RenderProfile::configuration();

    $config['video_bitrate_kbps'] = 500;
    RenderProfile::validateConfiguration($config);

    $config['video_bitrate_kbps'] = 50000;
    RenderProfile::validateConfiguration($config);

    $config['video_bitrate_kbps'] = 499;
    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validates audio_codec enum', function () {
    $config = RenderProfile::configuration();

    foreach (RenderProfile::VALID_AUDIO_CODECS as $codec) {
        $config['audio_codec'] = $codec;
        RenderProfile::validateConfiguration($config);
    }

    $config['audio_codec'] = 'invalid_codec';
    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validates audio_bitrate_kbps range 32..320', function () {
    $config = RenderProfile::configuration();

    $config['audio_bitrate_kbps'] = 32;
    RenderProfile::validateConfiguration($config);

    $config['audio_bitrate_kbps'] = 320;
    RenderProfile::validateConfiguration($config);

    $config['audio_bitrate_kbps'] = 31;
    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('returns correct parameter keys for provenance', function () {
    expect(RenderProfile::parameterKeys())->toBe([
        'configuration',
        'source_media',
        'ffmpeg_version',
        'filter_graph',
        'limits',
    ]);
});

it('exposes algorithm constants matching spec', function () {
    expect(RenderProfile::ALGORITHM)->toBe('ffmpeg_vertical_baseline');
    expect(RenderProfile::ALGORITHM_VERSION)->toBe('1.0.0');
    expect(RenderProfile::RENDER_PROFILE_VERSION)->toBe('vertical_v1');
    expect(RenderProfile::TIMEOUT_MIN)->toBe(30);
    expect(RenderProfile::TIMEOUT_MAX)->toBe(300);
    expect(RenderProfile::TIMEOUT_DEFAULT)->toBe(300);
    expect(RenderProfile::LOCK_WAIT_OFFSET_SECONDS)->toBe(10);
});

it('exposes limits constants matching spec', function () {
    expect(RenderProfile::MAX_DURATION_MS)->toBe(2147483647);
    expect(RenderProfile::MAX_RECOMMENDATIONS)->toBe(1000);
    expect(RenderProfile::MAX_INPUT_BYTES)->toBe(8388608);
});