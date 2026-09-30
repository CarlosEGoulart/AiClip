<?php

namespace Tests\Feature\Models;

use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Services\RenderProfile;
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
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION, // 'vertical_v1'
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
        ]);

        $this->assertDatabaseHas('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
        ]);

        expect($render->type)->toBe(DerivedAsset::TYPE_RENDERED_CLIP);
        expect($render->candidate_index)->toBe(0);
        expect($render->render_profile_version)->toBe(RenderProfile::RENDER_PROFILE_VERSION);
        expect($render->render_status)->toBe(DerivedAsset::RENDER_STATUS_PENDING);
    }

    public function test_unique_constraint_same_asset_type_candidate_index_profile_version_conflicts(): void
    {
        $this->expectException(QueryException::class);

        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_2.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);
    }

    public function test_unique_constraint_different_profile_version_allowed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $second = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => 'vertical_v2', // Different version
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_2.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $this->assertDatabaseHas('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
            'render_profile_version' => 'vertical_v2',
        ]);
    }

    public function test_unique_constraint_different_candidate_index_allowed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_0.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $second = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 1,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
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
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_0.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
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
            'request_sha256' => hash('sha256', 'test'),
        ];

        $render = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_COMPLETED,
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
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_COMPLETED,
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

    public function test_render_status_transitions_pending_to_rendering_to_completed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $render = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $render->markRendering();
        $fresh = $render->fresh();
        expect($fresh->render_status)->toBe(DerivedAsset::RENDER_STATUS_RENDERING);
        expect($fresh->render_started_at)->not->toBeNull();

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
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_configuration' => RenderProfile::configuration(),
            'render_parameters' => ['configuration' => RenderProfile::configuration()],
        ]);

        $fresh = $render->fresh();
        expect($fresh->render_status)->toBe(DerivedAsset::RENDER_STATUS_COMPLETED);
        expect($fresh->render_completed_at)->not->toBeNull();
    }

    public function test_render_status_transitions_pending_to_rendering_to_failed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $render = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $render->markRendering();
        $fresh = $render->fresh();
        expect($fresh->render_status)->toBe(DerivedAsset::RENDER_STATUS_RENDERING);

        $render->markFailed('invalid_candidate_index');

        $fresh = $render->fresh();
        expect($fresh->render_status)->toBe(DerivedAsset::RENDER_STATUS_FAILED);
        expect($fresh->render_error)->toBe('invalid_candidate_index');
        expect($fresh->render_completed_at)->not->toBeNull();
    }

    public function test_is_terminal_returns_true_for_completed_and_failed(): void
    {
        $mediaAsset = MediaAsset::factory()->create();

        $completed = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 0,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_COMPLETED,
            'storage_disk' => 'media',
            'storage_key' => 'renders/completed/placeholder_0.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024000,
            'duration_ms' => 10000,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $failed = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 1,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_FAILED,
            'storage_disk' => 'media',
            'storage_key' => 'renders/failed/placeholder_1.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $pending = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 2,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_PENDING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/pending/placeholder_2.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
        ]);

        $rendering = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_RENDERED_CLIP,
            'candidate_index' => 3,
            'render_profile_version' => RenderProfile::RENDER_PROFILE_VERSION,
            'render_status' => DerivedAsset::RENDER_STATUS_RENDERING,
            'storage_disk' => 'media',
            'storage_key' => 'renders/rendering/placeholder_3.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'duration_ms' => 0,
            'width' => 1080,
            'height' => 1920,
            'codec' => 'h264',
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

    public function test_render_status_constants(): void
    {
        expect(DerivedAsset::RENDER_STATUS_PENDING)->toBe('pending');
        expect(DerivedAsset::RENDER_STATUS_RENDERING)->toBe('rendering');
        expect(DerivedAsset::RENDER_STATUS_COMPLETED)->toBe('completed');
        expect(DerivedAsset::RENDER_STATUS_FAILED)->toBe('failed');
    }
}