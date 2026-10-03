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
        'captions' => [
            'enabled' => true,
            'font_file' => '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            'font_size' => 72,
            'font_color' => 'ffffff',
            'outline_color' => '000000',
            'outline_width' => 3,
            'background_color' => '000000',
            'background_opacity' => 0.5,
            'box_padding' => 10,
            'margin_bottom' => 100,
            'max_chars_per_line' => 32,
        ],
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

/*
|--------------------------------------------------------------------------
| Caption Configuration Tests (M6.2 Stage A)
|--------------------------------------------------------------------------
*/

it('configuration() includes captions object with all spec defaults', function () {
    $config = RenderProfile::configuration();

    expect($config)->toHaveKey('captions');
    expect($config['captions'])->toBe([
        'enabled' => true,
        'font_file' => '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        'font_size' => 72,
        'font_color' => 'ffffff',
        'outline_color' => '000000',
        'outline_width' => 3,
        'background_color' => '000000',
        'background_opacity' => 0.5,
        'box_padding' => 10,
        'margin_bottom' => 100,
        'max_chars_per_line' => 32,
    ]);
});

it('captions.enabled default is true', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['enabled'])->toBe(true);
});

it('captions.font_file default is DejaVuSans-Bold.ttf path', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['font_file'])->toBe('/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf');
});

it('captions.font_size default is 72', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['font_size'])->toBe(72);
});

it('captions.font_color default is ffffff', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['font_color'])->toBe('ffffff');
});

it('captions.outline_color default is 000000', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['outline_color'])->toBe('000000');
});

it('captions.outline_width default is 3', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['outline_width'])->toBe(3);
});

it('captions.background_color default is 000000', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['background_color'])->toBe('000000');
});

it('captions.background_opacity default is 0.5', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['background_opacity'])->toBe(0.5);
});

it('captions.box_padding default is 10', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['box_padding'])->toBe(10);
});

it('captions.margin_bottom default is 100', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['margin_bottom'])->toBe(100);
});

it('captions.max_chars_per_line default is 32', function () {
    $config = RenderProfile::configuration();
    expect($config['captions']['max_chars_per_line'])->toBe(32);
});

it('validateConfiguration rejects font_size < 12', function () {
    $config = RenderProfile::configuration();
    $config['captions']['font_size'] = 11;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects font_size > 200', function () {
    $config = RenderProfile::configuration();
    $config['captions']['font_size'] = 201;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects outline_width < 0', function () {
    $config = RenderProfile::configuration();
    $config['captions']['outline_width'] = -1;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects outline_width > 10', function () {
    $config = RenderProfile::configuration();
    $config['captions']['outline_width'] = 11;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects background_opacity < 0.0', function () {
    $config = RenderProfile::configuration();
    $config['captions']['background_opacity'] = -0.1;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects background_opacity > 1.0', function () {
    $config = RenderProfile::configuration();
    $config['captions']['background_opacity'] = 1.1;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects margin_bottom < 0', function () {
    $config = RenderProfile::configuration();
    $config['captions']['margin_bottom'] = -1;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects margin_bottom > 500', function () {
    $config = RenderProfile::configuration();
    $config['captions']['margin_bottom'] = 501;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects max_chars_per_line < 10', function () {
    $config = RenderProfile::configuration();
    $config['captions']['max_chars_per_line'] = 9;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects max_chars_per_line > 80', function () {
    $config = RenderProfile::configuration();
    $config['captions']['max_chars_per_line'] = 81;

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects invalid hex color format (not 6 chars)', function () {
    $config = RenderProfile::configuration();
    $config['captions']['font_color'] = 'fff';

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration rejects invalid hex color format (non-hex chars)', function () {
    $config = RenderProfile::configuration();
    $config['captions']['font_color'] = 'zzzzzz';

    try {
        RenderProfile::validateConfiguration($config);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('invalid_configuration');
    }
});

it('validateConfiguration accepts valid hex color without #', function () {
    $config = RenderProfile::configuration();
    $config['captions']['font_color'] = 'abcdef';
    $config['captions']['outline_color'] = '123456';
    $config['captions']['background_color'] = 'abc123';
    RenderProfile::validateConfiguration($config);
});

it('CONFIGURATION_KEYS includes captions key', function () {
    expect(RenderProfile::CONFIGURATION_KEYS)->toContain('captions');
});