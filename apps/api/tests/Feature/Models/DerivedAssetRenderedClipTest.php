<?php

namespace Tests\Feature\Models;

use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DerivedAssetRenderedClipTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_derived_asset_with_type_clip_rendered_persists_correctly(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $render = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
        ]);

        $this->assertDatabaseHas('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
        ]);

        expect($render->type)->toBe(DerivedAsset::TYPE_RENDERED_CLIP);
        expect($render->candidate_index)->toBe(0);
        expect($render->render_profile_version)->toBe('ffmpeg_vertical_baseline:1.0.0');
        expect($render->status)->toBe('pending');
    }

    public function test_unique_constraint_same_asset_type_candidate_index_profile_version_conflicts(): void
    {
        $this->expectException(QueryException::class);

        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);
    }

    public function test_unique_constraint_different_profile_version_allowed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        $second = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:2.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        $this->assertDatabaseHas('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
            'render_profile_version' => 'ffmpeg_vertical_baseline:2.0.0',
        ]);
    }

    public function test_unique_constraint_different_candidate_index_allowed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        $second = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 1,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        $this->assertDatabaseHas('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
            'candidate_index' => 1,
        ]);
    }

    public function test_rendered_clips_scope_returns_only_clip_rendered_type(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
        ]);

        $renderedClips = $mediaAsset->renderedClips()->get();

        expect($renderedClips->count())->toBe(1);
        expect($renderedClips->first()->type)->toBe(DerivedAsset::TYPE_RENDERED_CLIP);
    }

    public function test_render_columns_cast_to_array(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $renderConfig = [
            'target_width' => 1080,
            'target_height' => 1920,
            'target_fps' => 30,
            'video_codec' => 'libx264',
            'video_bitrate_kbps' => 5000,
            'audio_codec' => 'aac',
            'audio_bitrate_kbps' => 128,
        ];

        $renderParams = [
            'configuration' => $renderConfig,
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
        ];

        $render = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'completed',
            'storage_disk' => 'media',
            'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024000,
            'duration_ms' => 10000,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'libx264',
            'render_configuration' => $renderConfig,
            'render_parameters' => $renderParams,
            'render_error' => null,
        ]);

        $fresh = $render->fresh();

        expect($fresh->render_configuration)->toBeArray()
            ->and($fresh->render_configuration)->toBe($renderConfig)
            ->and($fresh->render_parameters)->toBeArray()
            ->and($fresh->render_parameters)->toBe($renderParams)
            ->and($fresh->render_error)->toBeNull();
    }

    public function test_cascade_delete_media_asset_removes_rendered_clips(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'completed',
            'storage_disk' => 'media',
            'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024000,
            'duration_ms' => 10000,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'libx264',
        ]);

        $mediaAsset->delete();

        $this->assertDatabaseMissing('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_status_transitions_pending_to_rendering_to_completed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $render = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        $render->markRendering();
        $fresh = $render->fresh();
        expect($fresh->status)->toBe('rendering');

        $render->markCompleted([
            'storage_disk' => 'media',
            'storage_key' => 'renders/1/1/0_20260101T000000Z.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024000,
            'duration_ms' => 10000,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'libx264',
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'render_configuration' => RenderProfile::configuration(),
            'render_parameters' => ['configuration' => RenderProfile::configuration()],
        ]);

        $fresh = $render->fresh();
        expect($fresh->status)->toBe('completed');
    }

    public function test_status_transitions_pending_to_rendering_to_failed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $render = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        $render->markRendering();
        $fresh = $render->fresh();
        expect($fresh->status)->toBe('rendering');

        $render->markFailed('invalid_candidate_index');

        $fresh = $render->fresh();
        expect($fresh->status)->toBe('failed');
        expect($fresh->render_error)->toBe('invalid_candidate_index');
    }

    public function test_is_terminal_returns_true_for_completed_and_failed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $completed = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'completed',
            'storage_disk' => 'media',
        ]);

        $failed = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 1,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'failed',
            'storage_disk' => 'media',
        ]);

        $pending = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 2,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'pending',
            'storage_disk' => 'media',
        ]);

        $rendering = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 3,
            'render_profile_version' => 'ffmpeg_vertical_baseline:1.0.0',
            'status' => 'rendering',
            'storage_disk' => 'media',
        ]);

        expect($completed->isTerminal())->toBeTrue();
        expect($failed->isTerminal())->toBeTrue();
        expect($pending->isTerminal())->toBeFalse();
        expect($rendering->isTerminal())->toBeFalse();
    }

    public function test_type_constant_rendered_clip(): void
    {
        expect(DerivedAsset::TYPE_RENDERED_CLIP)->toBe('clip_rendered');
    }

    public function test_status_constants(): void
    {
        expect(DerivedAsset::STATUS_PENDING)->toBe('pending');
        expect(DerivedAsset::STATUS_RENDERING)->toBe('rendering');
        expect(DerivedAsset::STATUS_COMPLETED)->toBe('completed');
        expect(DerivedAsset::STATUS_FAILED)->toBe('failed');
    }
}