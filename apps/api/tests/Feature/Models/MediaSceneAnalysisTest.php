<?php

namespace Tests\Feature\Models;

use App\Models\MediaAsset;
use App\Models\MediaSceneAnalysis;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaSceneAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_scene_analysis_creation_with_valid_data(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('media_scene_analyses', [
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);
    }

    public function test_media_scene_analysis_belongs_to_media_asset(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);

        $this->assertEquals($mediaAsset->id, $sceneAnalysis->mediaAsset->id);
    }

    public function test_media_scene_analysis_default_status_is_pending(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
        ]);

        $this->assertEquals(MediaSceneAnalysis::STATUS_PENDING, $sceneAnalysis->fresh()->status);
    }

    public function test_status_constants(): void
    {
        $this->assertEquals('pending', MediaSceneAnalysis::STATUS_PENDING);
        $this->assertEquals('detecting', MediaSceneAnalysis::STATUS_DETECTING);
        $this->assertEquals('completed', MediaSceneAnalysis::STATUS_COMPLETED);
        $this->assertEquals('failed', MediaSceneAnalysis::STATUS_FAILED);
    }

    public function test_scene_analysis_pending_to_detecting(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);

        $sceneAnalysis->markDetecting();
        $this->assertEquals(MediaSceneAnalysis::STATUS_DETECTING, $sceneAnalysis->fresh()->status);
    }

    public function test_scene_analysis_detecting_to_completed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_DETECTING,
        ]);

        $scenes = [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
            ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
        ];

        $sceneAnalysis->markCompleted(
            'deterministic',
            '0.0.0',
            [],
            $scenes,
            5000,
        );

        $this->assertEquals(MediaSceneAnalysis::STATUS_COMPLETED, $sceneAnalysis->fresh()->status);
        $this->assertEquals('deterministic', $sceneAnalysis->fresh()->detector);
        $this->assertEquals('0.0.0', $sceneAnalysis->fresh()->detector_version);
        $this->assertEquals($scenes, $sceneAnalysis->fresh()->scenes);
    }

    public function test_scene_analysis_detecting_to_failed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_DETECTING,
        ]);

        $sceneAnalysis->markFailed('Scene detection engine timeout');

        $this->assertEquals(MediaSceneAnalysis::STATUS_FAILED, $sceneAnalysis->fresh()->status);
        $this->assertEquals('Scene detection engine timeout', $sceneAnalysis->fresh()->error);
    }

    public function test_scene_analysis_failed_to_detecting_retry(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_FAILED,
            'error' => 'Previous error',
        ]);

        $sceneAnalysis->markDetecting();
        $this->assertEquals(MediaSceneAnalysis::STATUS_DETECTING, $sceneAnalysis->fresh()->status);
        $this->assertNull($sceneAnalysis->fresh()->error);
    }

    public function test_scene_analysis_completed_is_idempotent(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_COMPLETED,
            'detector' => 'deterministic',
            'detector_version' => '0.0.0',
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 3120]],
        ]);

        // Trying to transition from completed should be a no-op
        $sceneAnalysis->markDetecting();
        $this->assertEquals(MediaSceneAnalysis::STATUS_COMPLETED, $sceneAnalysis->fresh()->status);
    }

    public function test_invalid_transition_rejected(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);

        // pending -> completed is invalid, should be noop
        $sceneAnalysis->markCompleted('engine', '1.0', [], [], 5000);
        $this->assertEquals(MediaSceneAnalysis::STATUS_PENDING, $sceneAnalysis->fresh()->status);
    }

    public function test_reject_negative_start_ms(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => -1, 'end_ms' => 3120],
        ], 5000);
    }

    public function test_reject_end_ms_less_than_start_ms(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 3120, 'end_ms' => 1000],
        ], 5000);
    }

    public function test_reject_unordered_scenes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 1000, 'end_ms' => 2000],
            ['index' => 1, 'start_ms' => 0, 'end_ms' => 1000],
        ], 5000);
    }

    public function test_reject_overlapping_scenes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1500],
            ['index' => 1, 'start_ms' => 1000, 'end_ms' => 2000],
        ], 5000);
    }

    public function test_reject_duplicate_indexes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
            ['index' => 0, 'start_ms' => 1000, 'end_ms' => 2000],
        ], 5000);
    }

    public function test_reject_empty_scenes_array(): void
    {
        // Empty scenes array should be valid (no exception)
        MediaSceneAnalysis::validateScenes([], 5000);
        // If we get here without exception, the test passes
        $this->assertTrue(true);
    }

    public function test_scenes_cast_to_array(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $scenes = [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3120],
            ['index' => 1, 'start_ms' => 3120, 'end_ms' => 5000],
        ];

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_COMPLETED,
            'scenes' => $scenes,
        ]);

        $this->assertIsArray($sceneAnalysis->scenes);
        $this->assertCount(2, $sceneAnalysis->scenes);
    }

    public function test_parameters_cast_to_array(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $parameters = ['threshold' => 27.0];

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_COMPLETED,
            'parameters' => $parameters,
        ]);

        $this->assertIsArray($sceneAnalysis->parameters);
        $this->assertEquals($parameters, $sceneAnalysis->parameters);
    }

    public function test_cascade_deletion_from_media_asset(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_COMPLETED,
        ]);

        $mediaAsset->delete();

        $this->assertDatabaseMissing('media_scene_analyses', [
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_duplicate_scene_analysis_prevented(): void
    {
        $this->expectException(QueryException::class);

        $mediaAsset = MediaAsset::factory()->create();

        MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);

        // Try to create another scene analysis for the same media asset
        MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Duration Boundary Tests (12 tests)
    |--------------------------------------------------------------------------
    */

    public function test_validate_scenes_rejects_zero_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration must be > 0');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
        ], 0);
    }

    public function test_validate_scenes_rejects_negative_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration must be > 0');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
        ], -1);
    }

    public function test_validate_scenes_accepts_exact_duration_boundary(): void
    {
        // Scene end_ms exactly equals duration should be valid
        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 5000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_scene_exceeding_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds media duration');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 5001],
        ], 5000);
    }

    public function test_validate_scenes_rejects_multiple_scenes_exceeding_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds media duration');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 3000],
            ['index' => 1, 'start_ms' => 3000, 'end_ms' => 6000],
        ], 5000);
    }

    public function test_validate_scenes_accepts_scene_at_duration_boundary(): void
    {
        // Last scene ends exactly at duration
        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 2500],
            ['index' => 1, 'start_ms' => 2500, 'end_ms' => 5000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_start_ms_beyond_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds media duration');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 5000, 'end_ms' => 6000],
        ], 5000);
    }

    public function test_validate_scenes_accepts_single_scene_at_zero_duration(): void
    {
        // Duration 0 with no scenes should be valid
        MediaSceneAnalysis::validateScenes([], 0);
    }

    public function test_validate_scenes_rejects_negative_start_ms_with_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('start_ms must be >= 0');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => -1, 'end_ms' => 1000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_end_ms_equal_start_ms_with_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be > start_ms');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 1000, 'end_ms' => 1000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_unordered_with_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not ordered');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 1000, 'end_ms' => 2000],
            ['index' => 1, 'start_ms' => 0, 'end_ms' => 1000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_overlapping_with_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overlap');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1500],
            ['index' => 1, 'start_ms' => 1000, 'end_ms' => 2000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_duplicate_indexes_with_duration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate scene index');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
            ['index' => 0, 'start_ms' => 1000, 'end_ms' => 2000],
        ], 5000);
    }

    /*
    |--------------------------------------------------------------------------
    | Index Invariant Tests (4 tests)
    |--------------------------------------------------------------------------
    */

    public function test_validate_scenes_rejects_first_scene_not_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential 0-based indexes');

        MediaSceneAnalysis::validateScenes([
            ['index' => 1, 'start_ms' => 0, 'end_ms' => 1000],
            ['index' => 2, 'start_ms' => 1000, 'end_ms' => 2000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_gaps_in_indexes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential 0-based indexes');

        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
            ['index' => 2, 'start_ms' => 1000, 'end_ms' => 2000],
        ], 5000);
    }

    public function test_validate_scenes_rejects_reversed_indexes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sequential 0-based indexes');

        MediaSceneAnalysis::validateScenes([
            ['index' => 1, 'start_ms' => 0, 'end_ms' => 1000],
            ['index' => 0, 'start_ms' => 1000, 'end_ms' => 2000],
        ], 5000);
    }

    public function test_validate_scenes_accepts_valid_sequential_indexes(): void
    {
        // Should not raise
        MediaSceneAnalysis::validateScenes([
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
            ['index' => 1, 'start_ms' => 1000, 'end_ms' => 2000],
            ['index' => 2, 'start_ms' => 2000, 'end_ms' => 3000],
        ], 5000);
    }

    /*
    |--------------------------------------------------------------------------
    | Retry Overflow Tests (3 tests)
    |--------------------------------------------------------------------------
    */

    public function test_mark_completed_rejects_zero_duration(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_DETECTING,
        ]);

        $scenes = [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration must be > 0');

        $sceneAnalysis->markCompleted('deterministic', '0.0.0', [], $scenes, 0);
    }

    public function test_mark_completed_rejects_negative_duration(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_DETECTING,
        ]);

        $scenes = [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 1000],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration must be > 0');

        $sceneAnalysis->markCompleted('deterministic', '0.0.0', [], $scenes, -1);
    }

    public function test_mark_completed_rejects_scene_exceeding_duration_on_retry(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        // First attempt fails
        $sceneAnalysis = MediaSceneAnalysis::create([
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaSceneAnalysis::STATUS_FAILED,
            'error' => 'Previous error',
        ]);

        // Retry with invalid scene exceeding duration
        $sceneAnalysis->markDetecting();

        $scenes = [
            ['index' => 0, 'start_ms' => 0, 'end_ms' => 6000],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds media duration');

        $sceneAnalysis->markCompleted('deterministic', '0.0.0', [], $scenes, 5000);
    }
}
