<?php

namespace Tests\Feature\Models;

use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaTranscript;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaTranscriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_transcript_can_be_created(): void
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

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        $this->assertDatabaseHas('media_transcripts', [
            'media_asset_id' => $mediaAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);
    }

    public function test_belongs_to_media_asset(): void
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

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        $this->assertEquals($mediaAsset->id, $transcript->mediaAsset->id);
    }

    public function test_belongs_to_derived_asset(): void
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

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        $this->assertEquals($derivedAsset->id, $transcript->derivedAsset->id);
    }

    public function test_media_asset_has_one_transcript(): void
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

        MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        $this->assertNotNull($mediaAsset->transcript);
        $this->assertEquals($mediaAsset->id, $mediaAsset->transcript->media_asset_id);
    }

    public function test_unique_constraint(): void
    {
        $this->expectException(QueryException::class);

        $mediaAsset = MediaAsset::factory()->create();
        $derivedAsset = DerivedAsset::create([
            'media_asset_id' => $mediaAsset->id,
            'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media',
            'storage_key' => 'path/to/audio.wav',
            'mime_type' => 'audio/wav',
            'size_bytes' => 1024,
        ]);

        MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        // Try to create another transcript for the same media asset
        MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);
    }

    public function test_cascade_delete(): void
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

        MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        $mediaAsset->delete();

        $this->assertDatabaseMissing('media_transcripts', [
            'media_asset_id' => $mediaAsset->id,
        ]);
    }

    public function test_status_constants(): void
    {
        $this->assertEquals('pending', MediaTranscript::STATUS_PENDING);
        $this->assertEquals('transcribing', MediaTranscript::STATUS_TRANSCRIBING);
        $this->assertEquals('completed', MediaTranscript::STATUS_COMPLETED);
        $this->assertEquals('failed', MediaTranscript::STATUS_FAILED);
    }

    public function test_status_transitions(): void
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

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        // pending -> transcribing
        $transcript->markTranscribing();
        $this->assertEquals(MediaTranscript::STATUS_TRANSCRIBING, $transcript->fresh()->status);

        // transcribing -> completed
        $transcript->markCompleted(
            'en',
            'Hello world',
            [['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello world']],
            'deterministic',
            'deterministic'
        );
        $this->assertEquals(MediaTranscript::STATUS_COMPLETED, $transcript->fresh()->status);
        $this->assertEquals('en', $transcript->fresh()->language);
        $this->assertEquals('Hello world', $transcript->fresh()->full_text);
    }

    public function test_invalid_transition_is_noop(): void
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

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        // pending -> completed is invalid, should be noop
        $transcript->markCompleted('en', 'text', [], 'engine', 'model');
        $this->assertEquals(MediaTranscript::STATUS_PENDING, $transcript->fresh()->status);
    }

    public function test_mark_failed_from_transcribing(): void
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

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        $transcript->markTranscribing();
        $transcript->markFailed('Transcription engine timeout');

        $this->assertEquals(MediaTranscript::STATUS_FAILED, $transcript->fresh()->status);
        $this->assertEquals('Transcription engine timeout', $transcript->fresh()->error);
    }

    public function test_failed_to_transcribing_is_valid(): void
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

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_FAILED,
            'error' => 'Previous error',
        ]);

        // failed -> transcribing should work now
        $transcript->markTranscribing();
        $this->assertEquals(MediaTranscript::STATUS_TRANSCRIBING, $transcript->fresh()->status);
        $this->assertNull($transcript->fresh()->error);
    }

    public function test_segments_cast_to_array(): void
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

        $segments = [
            ['start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello'],
            ['start_ms' => 1000, 'end_ms' => 2000, 'text' => 'World'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => $segments,
        ]);

        $this->assertIsArray($transcript->segments);
        $this->assertCount(2, $transcript->segments);
    }
}
