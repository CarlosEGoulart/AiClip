<?php

namespace Tests\Feature\Models;

use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DerivedAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_derived_asset_can_be_created(): void
    {
        $mediaAsset = MediaAsset::factory()->create();
        $derivedAsset = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
            'duration_ms' => 5000,
            'sample_rate' => 16000,
            'channels' => 1,
            'codec' => 'pcm_s16le',
        ]);

        $this->assertDatabaseHas('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
        ]);
    }

    public function test_derived_asset_belongs_to_media_asset(): void
    {
        $mediaAsset = MediaAsset::factory()->create();
        $derivedAsset = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
        ]);

        $this->assertEquals($mediaAsset->id, $derivedAsset->mediaAsset->id);
    }

    public function test_media_asset_has_many_derived_assets(): void
    {
        $mediaAsset = MediaAsset::factory()->create();
        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
        ]);

        $this->assertCount(1, $mediaAsset->derivedAssets);
    }

    public function test_unique_constraint_on_media_asset_id_and_type(): void
    {
        $this->expectException(QueryException::class);

        $mediaAsset = MediaAsset::factory()->create();
        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
        ]);

        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio2.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 2048,
        ]);
    }

    public function test_different_types_allowed_for_same_media_asset(): void
    {
        $mediaAsset = MediaAsset::factory()->create();
        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
        ]);

        $thumbnail = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => 'thumbnail',
            'storage_disk' => 'media',
            'storage_key' => 'path/to/thumb.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 512,
        ]);

        $this->assertDatabaseHas('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
            'type' => 'thumbnail',
        ]);
    }

    public function test_cascade_delete_removes_derived_assets(): void
    {
        $mediaAsset = MediaAsset::factory()->create();
        DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
        ]);

        $mediaAsset->delete();

        $this->assertDatabaseMissing('derived_assets', [
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_audio_normalized_type_constant(): void
    {
        $this->assertEquals('audio_normalized', DerivedAsset::TYPE_AUDIO_NORMALIZED);
    }
}
