<?php

namespace Tests\Unit;

use App\Services\RenderValidator;
use App\Services\RenderProfile;
use App\Exceptions\ProcessMediaException;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Fixtures — hand-derived from spec.md (singular render_clip action)
|--------------------------------------------------------------------------
*/

function renderRequest(): array
{
    return [
        'version' => RenderValidator::CONTRACT_VERSION,
        'action' => RenderValidator::ACTION,
        'media' => ['duration_ms' => 30000],
        'candidate_index' => 0,
        'candidate' => [
            'start_ms' => 0,
            'end_ms' => 10000,
        ],
        'configuration' => RenderProfile::configuration(),
        'source_media' => [
            'disk' => 'media',
            'key' => 'projects/1/assets/1/source.mp4',
            'width' => 1920,
            'height' => 1080,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
        ],
        'output_storage' => [
            'disk' => 'media',
            'key' => 'projects/1/renders/1/0/vertical_v1/550e8400-e29b-41d4-a716-446655440000.mp4',
            'mime_type' => 'video/mp4',
        ],
    ];
}

function renderRequestWithCandidateIndex(int $index): array
{
    $request = renderRequest();
    $request['candidate_index'] = $index;
    return $request;
}

function renderResult(array $overrides = []): array
{
    $request = renderRequest();
    $requestSha256 = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

    $result = [
        'status' => 'success',
        'render' => [
            'algorithm' => RenderValidator::ALGORITHM,
            'algorithm_version' => RenderValidator::ALGORITHM_VERSION,
            'parameters' => [
                'configuration' => RenderProfile::configuration(),
                'source_media' => [
                    'disk' => 'media',
                    'key' => 'projects/1/assets/1/source.mp4',
                    'duration_ms' => 30000,
                    'width' => 1920,
                    'height' => 1080,
                    'video_codec' => 'h264',
                    'audio_codec' => 'aac',
                ],
                'ffmpeg_version' => 'ffmpeg version 6.0',
                'filter_graph' => 'crop=ih*9/16:ih:(iw-ih*9/16)/2:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30',
                'limits' => [
                    'max_recommendations' => 1000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
                'request_sha256' => $requestSha256,
            ],
            'clips' => [
                [
                    'candidate_index' => 0,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'duration_ms' => 10000,
                    'output' => [
                        'disk' => 'media',
                        'key' => 'renders/1/1/0_20260101T000000Z.mp4',
                        'size_bytes' => 1024000,
                        'duration_ms' => 10000,
                        'width' => 1080,
                        'height' => 1920,
                        'video_codec' => 'libx264',
                        'audio_codec' => 'aac',
                        'video_bitrate_kbps' => 5000,
                        'audio_bitrate_kbps' => 128,
                    ],
                ],
            ],
        ],
    ];

    foreach ($overrides as $path => $value) {
        $segments = explode('.', (string) $path);
        $target = &$result;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $target = &$target[$segment];
        }
        if ($value === '__REMOVE__') {
            unset($target[array_pop($segments)]);
        } else {
            $target[array_pop($segments)] = $value;
        }
        unset($target);
    }

    return $result;
}

function requestDigest(array $request): string
{
    return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));
}

/*
|--------------------------------------------------------------------------
| Request validation
|--------------------------------------------------------------------------
*/

it('accepts valid render_clip contract request', function () {
    $request = renderRequest();

    expect(RenderValidator::request($request))->toBe($request);
});

it('rejects missing version', function () {
    $request = renderRequest();
    unset($request['version']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid version', function () {
    $request = renderRequest();
    $request['version'] = '2.0.0';

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unsupported render contract version');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid action', function () {
    $request = renderRequest();
    $request['action'] = 'rank_clips';

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unsupported render action');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing media.duration_ms', function () {
    $request = renderRequest();
    unset($request['media']['duration_ms']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Expected an object');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid media.duration_ms (non-integer)', function () {
    $request = renderRequest();
    $request['media']['duration_ms'] = '30000';

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Expected an integer inside the allowed range');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects media.duration_ms out of bounds (zero)', function () {
    $request = renderRequest();
    $request['media']['duration_ms'] = 0;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Expected an integer inside the allowed range');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects media.duration_ms above maximum', function () {
    $request = renderRequest();
    $request['media']['duration_ms'] = 2147483648;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Expected an integer inside the allowed range');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing candidate_index', function () {
    $request = renderRequest();
    unset($request['candidate_index']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects candidate_index negative', function () {
    $request = renderRequestWithCandidateIndex(-1);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('candidate_index must be non-negative');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing candidate object', function () {
    $request = renderRequest();
    unset($request['candidate']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing candidate.start_ms', function () {
    $request = renderRequest();
    unset($request['candidate']['start_ms']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing candidate.end_ms', function () {
    $request = renderRequest();
    unset($request['candidate']['end_ms']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects candidate.start_ms negative', function () {
    $request = renderRequest();
    $request['candidate']['start_ms'] = -1;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Expected an integer inside the allowed range');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects candidate.end_ms <= start_ms', function () {
    $request = renderRequest();
    $request['candidate']['end_ms'] = 0; // start_ms is 0

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Expected an integer inside the allowed range');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects candidate.end_ms > media.duration_ms', function () {
    $request = renderRequest();
    $request['candidate']['end_ms'] = 40000; // media.duration_ms is 30000

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Expected an integer inside the allowed range');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing configuration', function () {
    $request = renderRequest();
    unset($request['configuration']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid target_width (odd)', function () {
    $request = renderRequest();
    $request['configuration']['target_width'] = 1081;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid target_width (> 4096)', function () {
    $request = renderRequest();
    $request['configuration']['target_width'] = 4097;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid target_width (< 1)', function () {
    $request = renderRequest();
    $request['configuration']['target_width'] = 0;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid target_height (odd)', function () {
    $request = renderRequest();
    $request['configuration']['target_height'] = 1921;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid target_fps out of bounds', function () {
    $request = renderRequest();
    $request['configuration']['target_fps'] = 121;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid video_codec not in enum', function () {
    $request = renderRequest();
    $request['configuration']['video_codec'] = 'invalid_codec';

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid video_bitrate_kbps out of bounds', function () {
    $request = renderRequest();
    $request['configuration']['video_bitrate_kbps'] = 499;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid audio_codec not in enum', function () {
    $request = renderRequest();
    $request['configuration']['audio_codec'] = 'invalid_codec';

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects invalid audio_bitrate_kbps out of bounds', function () {
    $request = renderRequest();
    $request['configuration']['audio_bitrate_kbps'] = 31;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render configuration is not the selected profile');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing source_media', function () {
    $request = renderRequest();
    unset($request['source_media']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing output_storage', function () {
    $request = renderRequest();
    unset($request['output_storage']);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects output_storage.mime_type not video/mp4', function () {
    $request = renderRequest();
    $request['output_storage']['mime_type'] = 'video/webm';

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('output_storage.mime_type must be video/mp4');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects unknown field in request', function () {
    $request = renderRequest();
    $request['unknown_field'] = 'PRIVATE_SENTINEL';

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects input size > 8MB', function () {
    $request = renderRequest();
    // Add a large field to exceed 8MB
    $request['huge_field'] = str_repeat('x', 9000000);

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects recommendation in worker request', function () {
    $request = renderRequest();
    $request['recommendation'] = [];

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects recommendation_id in worker request', function () {
    $request = renderRequest();
    $request['recommendation_id'] = 1;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects media_asset_id in worker request', function () {
    $request = renderRequest();
    $request['media_asset_id'] = 1;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects project_id in worker request', function () {
    $request = renderRequest();
    $request['project_id'] = 1;

    try {
        RenderValidator::request($request);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Unexpected key set');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

/*
|--------------------------------------------------------------------------
| Response validation (worker result)
|--------------------------------------------------------------------------
*/

it('accepts valid worker success response', function () {
    $request = renderRequest();
    $result = renderResult();

    expect(RenderValidator::result($result, $request, requestDigest($request)))->toBe($result);
});

it('rejects missing algorithm', function () {
    $request = renderRequest();
    $result = renderResult(['render.algorithm' => '__REMOVE__']);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects wrong algorithm value', function () {
    $request = renderRequest();
    $result = renderResult(['render.algorithm' => 'wrong_algorithm']);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects wrong algorithm_version', function () {
    $request = renderRequest();
    $result = renderResult(['render.algorithm_version' => '2.0.0']);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects clips array length != 1', function () {
    $request = renderRequest();
    $result = renderResult(['render.clips' => []]);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects clip candidate_index mismatch', function () {
    $request = renderRequest();
    $result = renderResult(['render.clips.0.candidate_index' => 1]);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects clip bounds mismatch (start_ms)', function () {
    $request = renderRequest();
    $result = renderResult(['render.clips.0.start_ms' => 5000]);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects clip bounds mismatch (end_ms)', function () {
    $request = renderRequest();
    $result = renderResult(['render.clips.0.end_ms' => 15000]);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects missing output metadata fields', function () {
    $request = renderRequest();
    $result = renderResult(['render.clips.0.output.size_bytes' => '__REMOVE__']);

    try {
        RenderValidator::result($result, $request, requestDigest($request));
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

it('rejects SHA256 binding mismatch', function () {
    $request = renderRequest();
    $result = renderResult();
    $wrongDigest = hash('sha256', 'wrong bytes');

    try {
        RenderValidator::result($result, $request, $wrongDigest);
    } catch (ProcessMediaException $e) {
        expect($e->getMessage())->toBe('Render validation failed');
        return;
    }
    throw new \Exception('Expected ProcessMediaException');
});

/*
|--------------------------------------------------------------------------
| Completion validation (model boundary)
|--------------------------------------------------------------------------
*/

function renderCompletion(array $overrides = []): array
{
    $request = renderRequest();
    $requestSha256 = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR));

    $result = [
        'algorithm' => RenderValidator::ALGORITHM,
        'algorithm_version' => RenderValidator::ALGORITHM_VERSION,
        'parameters' => [
            'configuration' => RenderProfile::configuration(),
            'source_media' => [
                'disk' => 'media',
                'key' => 'projects/1/assets/1/source.mp4',
                'duration_ms' => 30000,
                'width' => 1920,
                'height' => 1080,
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
            ],
            'ffmpeg_version' => 'ffmpeg version 6.0',
            'filter_graph' => 'crop=ih*9/16:ih:(iw-ih*9/16)/2:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30',
            'limits' => [
                'max_recommendations' => 1000,
                'max_input_bytes' => 8388608,
                'max_duration_ms' => 2147483647,
            ],
            'request_sha256' => $requestSha256,
        ],
        'clips' => [
            [
                'candidate_index' => 0,
                'start_ms' => 0,
                'end_ms' => 10000,
                'duration_ms' => 10000,
                'output' => [
                    'disk' => 'media',
                    'key' => 'renders/1/1/0_20260101T000000Z.mp4',
                    'size_bytes' => 1024000,
                    'duration_ms' => 10000,
                    'width' => 1080,
                    'height' => 1920,
                    'video_codec' => 'libx264',
                    'audio_codec' => 'aac',
                    'video_bitrate_kbps' => 5000,
                    'audio_bitrate_kbps' => 128,
                ],
            ],
        ],
        'execution_parameters' => [
            'timeout_seconds' => 300,
            'lock_wait_seconds' => 310,
        ],
    ];

    foreach ($overrides as $path => $value) {
        $segments = explode('.', (string) $path);
        $target = &$result;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $target = &$target[$segment];
        }
        if ($value === '__REMOVE__') {
            unset($target[array_pop($segments)]);
        } else {
            $target[array_pop($segments)] = $value;
        }
        unset($target);
    }

    return $result;
}

function assertCompletionRejected(array $completion, string $label = ''): void
{
    $failure = null;

    try {
        RenderValidator::validateCompletion($completion);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect(get_class($failure ?? new \stdClass))->toBe(ProcessMediaException::class, 'expected completion rejection: '.$label);
    expect($failure->getMessage())->toBe('Render validation failed')
        ->and($failure->getPrevious())->toBeNull();
}

function assertCompletionAccepted(array $completion): void
{
    $failure = null;

    try {
        RenderValidator::validateCompletion($completion);
    } catch (\Throwable $exception) {
        $failure = $exception;
    }

    expect($failure)->toBeNull();
}

it('accepts valid completed DerivedAsset at model boundary', function () {
    assertCompletionAccepted(renderCompletion());
});

it('rejects missing render columns in completion', function () {
    assertCompletionRejected(renderCompletion(['parameters.configuration' => '__REMOVE__']), 'missing configuration');
    assertCompletionRejected(renderCompletion(['parameters.source_media' => '__REMOVE__']), 'missing source_media');
    assertCompletionRejected(renderCompletion(['parameters.ffmpeg_version' => '__REMOVE__']), 'missing ffmpeg_version');
    assertCompletionRejected(renderCompletion(['parameters.filter_graph' => '__REMOVE__']), 'missing filter_graph');
    assertCompletionRejected(renderCompletion(['parameters.limits' => '__REMOVE__']), 'missing limits');
    assertCompletionRejected(renderCompletion(['parameters.request_sha256' => '__REMOVE__']), 'missing request_sha256');
    assertCompletionRejected(renderCompletion(['clips' => '__REMOVE__']), 'missing clips');
    assertCompletionRejected(renderCompletion(['execution_parameters' => '__REMOVE__']), 'missing execution_parameters');
});

it('rejects output file missing (size_bytes <= 0)', function () {
    assertCompletionRejected(renderCompletion(['clips.0.output.size_bytes' => 0]), 'output size zero');
    assertCompletionRejected(renderCompletion(['clips.0.output.size_bytes' => -1]), 'output size negative');
});

it('rejects duration mismatch >50ms (output duration vs clip duration)', function () {
    // Output duration differs by more than 50ms from clip duration
    assertCompletionRejected(renderCompletion(['clips.0.output.duration_ms' => 9900]), 'duration mismatch >50ms');
});

it('rejects resolution mismatch', function () {
    assertCompletionRejected(renderCompletion(['clips.0.output.width' => 720]), 'width mismatch');
    assertCompletionRejected(renderCompletion(['clips.0.output.height' => 1280]), 'height mismatch');
});

it('rejects missing filter_graph', function () {
    assertCompletionRejected(renderCompletion(['parameters.filter_graph' => '']), 'empty filter_graph');
});

it('rejects missing ffmpeg_version', function () {
    assertCompletionRejected(renderCompletion(['parameters.ffmpeg_version' => '']), 'empty ffmpeg_version');
});

it('rejects invalid execution_parameters timeout', function () {
    assertCompletionRejected(renderCompletion(['execution_parameters.timeout_seconds' => 29]), 'timeout below minimum');
    assertCompletionRejected(renderCompletion(['execution_parameters.timeout_seconds' => 301]), 'timeout above maximum');
    assertCompletionRejected(renderCompletion(['execution_parameters.timeout_seconds' => 300.0]), 'timeout float');
});

it('rejects invalid execution_parameters lock_wait_seconds', function () {
    assertCompletionRejected(renderCompletion(['execution_parameters.lock_wait_seconds' => 311]), 'lock_wait mismatch');
});