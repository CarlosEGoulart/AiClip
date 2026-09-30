<?php

namespace Tests\Unit;

use App\Services\RenderValidator;
use App\Services\RenderProfile;
use App\Models\MediaAsset;
use App\Exceptions\ProcessMediaException;
use Tests\TestCase;

class RenderValidatorTest extends TestCase
{
    private function validRenderContract(array $overrides = []): array
    {
        $base = [
            'version' => '1.0.0',
            'action' => 'render_clip',
            'media' => ['duration_ms' => 30000],
            'recommendation' => [
                'candidates' => [
                    [
                        'index' => 0,
                        'start_ms' => 0,
                        'end_ms' => 10000,
                        'semantic_rank' => 1,
                        'semantic_score' => 0.95,
                    ],
                    [
                        'index' => 1,
                        'start_ms' => 10000,
                        'end_ms' => 20000,
                        'semantic_rank' => 2,
                        'semantic_score' => 0.75,
                    ],
                ],
            ],
            'candidate_index' => 0,
            'configuration' => RenderProfile::configuration(),
            'source_media' => [
                'disk' => 'media',
                'key' => 'projects/1/assets/1/source.mp4',
                'width' => 1920,
                'height' => 1080,
                'video_codec' => 'h264',
                'audio_codec' => 'aac',
            ],
            'output_key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
        ];

        return array_merge($base, $overrides);
    }

    public function test_request_accepts_valid_render_contract(): void
    {
        $contract = $this->validRenderContract();
        $result = RenderValidator::request($contract);

        expect($result)->toBeArray();
        expect($result['version'])->toBe('1.0.0');
        expect($result['action'])->toBe('render_clip');
    }

    public function test_request_rejects_missing_candidate_index(): void
    {
        $contract = $this->validRenderContract();
        unset($contract['candidate_index']);

        try {
            RenderValidator::request($contract);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Unexpected key set');
        }
    }

    public function test_request_rejects_invalid_candidate_index(): void
    {
        $contract = $this->validRenderContract(['candidate_index' => 5]); // Out of bounds

        try {
            RenderValidator::request($contract);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('candidate_index out of bounds');
        }
    }

    public function test_request_rejects_missing_configuration_fields(): void
    {
        $contract = $this->validRenderContract();
        unset($contract['configuration']['target_width']);

        try {
            RenderValidator::request($contract);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Unexpected key set');
        }
    }

    public function test_result_accepts_valid_worker_success_response(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'vertical',
                'algorithm_version' => 'vertical_v1',
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
                        'semantic_rank' => 1,
                        'semantic_score' => 0.95,
                        'start_ms' => 0,
                        'end_ms' => 10000,
                        'duration_ms' => 10000,
                        'output' => [
                            'disk' => 'media',
                            'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
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

        $validated = RenderValidator::result($result, $request, $requestSha256);
        expect($validated['status'])->toBe('success');
    }

    public function test_result_rejects_missing_algorithm(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm_version' => 'vertical_v1',
                'parameters' => [],
                'clips' => [[]],
            ],
        ];

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_result_rejects_wrong_algorithm_value(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'wrong_algorithm',
                'algorithm_version' => 'vertical_v1',
                'parameters' => [],
                'clips' => [[]],
            ],
        ];

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_result_rejects_wrong_algorithm_version(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'vertical',
                'algorithm_version' => '1.0.0',
                'parameters' => [],
                'clips' => [[]],
            ],
        ];

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_result_rejects_clips_array_length_not_1(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'vertical',
                'algorithm_version' => 'vertical_v1',
                'parameters' => [],
                'clips' => [], // Empty array
            ],
        ];

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_result_rejects_clip_candidate_index_mismatch(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'vertical',
                'algorithm_version' => 'vertical_v1',
                'parameters' => [
                    'configuration' => RenderProfile::configuration(),
                    'source_media' => $contract['source_media'],
                    'ffmpeg_version' => 'ffmpeg version 6.0',
                    'filter_graph' => 'test',
                    'limits' => [
                        'max_recommendations' => 1000,
                        'max_input_bytes' => 8388608,
                        'max_duration_ms' => 2147483647,
                    ],
                    'request_sha256' => $requestSha256,
                ],
                'clips' => [
                    [
                        'candidate_index' => 1, // Mismatch: request has 0
                        'semantic_rank' => 1,
                        'semantic_score' => 0.95,
                        'start_ms' => 0,
                        'end_ms' => 10000,
                        'duration_ms' => 10000,
                        'output' => [
                            'disk' => 'media',
                            'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
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

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_result_rejects_clip_bounds_mismatch(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'vertical',
                'algorithm_version' => 'vertical_v1',
                'parameters' => [
                    'configuration' => RenderProfile::configuration(),
                    'source_media' => $contract['source_media'],
                    'ffmpeg_version' => 'ffmpeg version 6.0',
                    'filter_graph' => 'test',
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
                        'semantic_rank' => 1,
                        'semantic_score' => 0.95,
                        'start_ms' => 5000, // Mismatch: request has 0
                        'end_ms' => 10000,
                        'duration_ms' => 5000,
                        'output' => [
                            'disk' => 'media',
                            'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
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

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_result_rejects_missing_output_metadata_fields(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'vertical',
                'algorithm_version' => 'vertical_v1',
                'parameters' => [
                    'configuration' => RenderProfile::configuration(),
                    'source_media' => $contract['source_media'],
                    'ffmpeg_version' => 'ffmpeg version 6.0',
                    'filter_graph' => 'test',
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
                        'semantic_rank' => 1,
                        'semantic_score' => 0.95,
                        'start_ms' => 0,
                        'end_ms' => 10000,
                        'duration_ms' => 10000,
                        'output' => [
                            'disk' => 'media',
                            'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
                            // Missing required fields
                        ],
                    ],
                ],
            ],
        ];

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_result_sha256_binding_mismatch_fails(): void
    {
        $contract = $this->validRenderContract();
        $request = RenderValidator::request($contract);
        $requestSha256 = hash('sha256', json_encode($contract));
        $wrongSha256 = hash('sha256', 'wrong');

        $result = [
            'status' => 'success',
            'render' => [
                'algorithm' => 'vertical',
                'algorithm_version' => 'vertical_v1',
                'parameters' => [
                    'configuration' => RenderProfile::configuration(),
                    'source_media' => $contract['source_media'],
                    'ffmpeg_version' => 'ffmpeg version 6.0',
                    'filter_graph' => 'test',
                    'limits' => [
                        'max_recommendations' => 1000,
                        'max_input_bytes' => 8388608,
                        'max_duration_ms' => 2147483647,
                    ],
                    'request_sha256' => $wrongSha256, // Wrong SHA
                ],
                'clips' => [
                    [
                        'candidate_index' => 0,
                        'semantic_rank' => 1,
                        'semantic_score' => 0.95,
                        'start_ms' => 0,
                        'end_ms' => 10000,
                        'duration_ms' => 10000,
                        'output' => [
                            'disk' => 'media',
                            'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
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

        try {
            RenderValidator::result($result, $request, $requestSha256);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_validate_completion_accepts_valid_completed_derived_asset(): void
    {
        $completion = [
            'algorithm' => 'vertical',
            'algorithm_version' => 'vertical_v1',
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
                'request_sha256' => hash('sha256', 'test'),
            ],
            'clips' => [
                [
                    'candidate_index' => 0,
                    'semantic_rank' => 1,
                    'semantic_score' => 0.95,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'duration_ms' => 10000,
                    'output' => [
                        'disk' => 'media',
                        'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
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

        // Should not throw
        RenderValidator::validateCompletion($completion);
    }

    public function test_validate_completion_rejects_missing_render_columns(): void
    {
        $completion = [
            'algorithm' => 'vertical',
            'algorithm_version' => 'vertical_v1',
            'parameters' => [],
            'clips' => [[]],
            // Missing execution_parameters
        ];

        try {
            RenderValidator::validateCompletion($completion);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_validate_completion_rejects_output_file_missing(): void
    {
        $completion = [
            'algorithm' => 'vertical',
            'algorithm_version' => 'vertical_v1',
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
                'filter_graph' => 'test',
                'limits' => [
                    'max_recommendations' => 1000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
                'request_sha256' => hash('sha256', 'test'),
            ],
            'clips' => [
                [
                    'candidate_index' => 0,
                    'semantic_rank' => 1,
                    'semantic_score' => 0.95,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'duration_ms' => 10000,
                    'output' => [
                        'disk' => 'media',
                        'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
                        'size_bytes' => 0, // Invalid: size must be > 0
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

        try {
            RenderValidator::validateCompletion($completion);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_validate_completion_rejects_duration_mismatch_gt_50ms(): void
    {
        $completion = [
            'algorithm' => 'vertical',
            'algorithm_version' => 'vertical_v1',
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
                'filter_graph' => 'test',
                'limits' => [
                    'max_recommendations' => 1000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
                'request_sha256' => hash('sha256', 'test'),
            ],
            'clips' => [
                [
                    'candidate_index' => 0,
                    'semantic_rank' => 1,
                    'semantic_score' => 0.95,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'duration_ms' => 10000, // Expected duration
                    'output' => [
                        'disk' => 'media',
                        'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
                        'size_bytes' => 1024000,
                        'duration_ms' => 10100, // 100ms off - exceeds 50ms tolerance
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

        try {
            RenderValidator::validateCompletion($completion);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }

    public function test_validate_completion_accepts_duration_within_50ms(): void
    {
        $completion = [
            'algorithm' => 'vertical',
            'algorithm_version' => 'vertical_v1',
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
                'filter_graph' => 'test',
                'limits' => [
                    'max_recommendations' => 1000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
                'request_sha256' => hash('sha256', 'test'),
            ],
            'clips' => [
                [
                    'candidate_index' => 0,
                    'semantic_rank' => 1,
                    'semantic_score' => 0.95,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'duration_ms' => 10000, // Expected duration
                    'output' => [
                        'disk' => 'media',
                        'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
                        'size_bytes' => 1024000,
                        'duration_ms' => 10040, // 40ms off - within 50ms tolerance
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

        // Should not throw - within ±50ms tolerance
        RenderValidator::validateCompletion($completion);
    }

    public function test_validate_completion_rejects_resolution_mismatch(): void
    {
        $completion = [
            'algorithm' => 'vertical',
            'algorithm_version' => 'vertical_v1',
            'parameters' => [
                'configuration' => RenderProfile::configuration(), // 1080x1920
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
                'filter_graph' => 'test',
                'limits' => [
                    'max_recommendations' => 1000,
                    'max_input_bytes' => 8388608,
                    'max_duration_ms' => 2147483647,
                ],
                'request_sha256' => hash('sha256', 'test'),
            ],
            'clips' => [
                [
                    'candidate_index' => 0,
                    'semantic_rank' => 1,
                    'semantic_score' => 0.95,
                    'start_ms' => 0,
                    'end_ms' => 10000,
                    'duration_ms' => 10000,
                    'output' => [
                        'disk' => 'media',
                        'key' => 'projects/1/renders/1/0_20260101T000000Z.mp4',
                        'size_bytes' => 1024000,
                        'duration_ms' => 10000,
                        'width' => 720, // Mismatch: config says 1080
                        'height' => 1280, // Mismatch: config says 1920
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

        try {
            RenderValidator::validateCompletion($completion);
            $this->fail('Expected ProcessMediaException');
        } catch (ProcessMediaException $e) {
            expect($e->getMessage())->toBe('Render validation failed');
        }
    }
}