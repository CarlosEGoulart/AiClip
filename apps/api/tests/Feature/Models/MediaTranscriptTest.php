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

    /*
    |--------------------------------------------------------------------------
    | Caption Segment Projection Tests (M6.2 Stage A)
    |--------------------------------------------------------------------------
    */

    public function test_project_segments_to_candidate_overlapping(): void
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
            ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First segment'],
            ['start_ms' => 10000, 'end_ms' => 20000, 'text' => 'Second segment'],
            ['start_ms' => 20000, 'end_ms' => 30000, 'text' => 'Third segment'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => $segments,
        ]);

        // Candidate: 5000..15000 (duration 10000)
        $projected = $transcript->projectSegmentsToCandidate(5000, 15000);

        $this->assertCount(2, $projected);
        // First segment: 0-10000 -> local 0-5000 (clipped to candidate start)
        $this->assertEquals(0, $projected[0]['start_ms']);
        $this->assertEquals(5000, $projected[0]['end_ms']);
        $this->assertEquals('First segment', $projected[0]['text']);
        // Second segment: 10000-20000 -> local 5000-10000 (clipped to candidate end)
        $this->assertEquals(5000, $projected[1]['start_ms']);
        $this->assertEquals(10000, $projected[1]['end_ms']);
        $this->assertEquals('Second segment', $projected[1]['text']);
    }

    public function test_project_segments_entirely_before_candidate(): void
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
            ['start_ms' => 0, 'end_ms' => 5000, 'text' => 'Before candidate'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => $segments,
        ]);

        // Candidate: 10000..20000
        $projected = $transcript->projectSegmentsToCandidate(10000, 20000);

        $this->assertEmpty($projected);
    }

    public function test_project_segments_entirely_after_candidate(): void
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
            ['start_ms' => 30000, 'end_ms' => 40000, 'text' => 'After candidate'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => $segments,
        ]);

        // Candidate: 10000..20000
        $projected = $transcript->projectSegmentsToCandidate(10000, 20000);

        $this->assertEmpty($projected);
    }

    public function test_project_segments_zero_length_overlap_excluded(): void
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
            ['start_ms' => 5000, 'end_ms' => 10000, 'text' => 'Exact boundary'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => $segments,
        ]);

        // Candidate: 10000..20000 (segment ends exactly at candidate start)
        $projected = $transcript->projectSegmentsToCandidate(10000, 20000);

        $this->assertEmpty($projected);
    }

    public function test_project_segments_sorted_by_local_start(): void
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

        // Segments out of order
        $segments = [
            ['start_ms' => 15000, 'end_ms' => 20000, 'text' => 'Second'],
            ['start_ms' => 5000, 'end_ms' => 10000, 'text' => 'First'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => $segments,
        ]);

        $projected = $transcript->projectSegmentsToCandidate(0, 30000);

        $this->assertCount(2, $projected);
        $this->assertEquals('First', $projected[0]['text']);
        $this->assertEquals('Second', $projected[1]['text']);
    }

    public function test_project_segments_non_completed_status_returns_empty(): void
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
            ['start_ms' => 0, 'end_ms' => 10000, 'text' => 'First segment'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_PENDING,
            'segments' => $segments,
        ]);

        $projected = $transcript->projectSegmentsToCandidate(0, 10000);

        $this->assertEmpty($projected);
    }

    public function test_project_segments_empty_segments_returns_empty(): void
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
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => [],
        ]);

        $projected = $transcript->projectSegmentsToCandidate(0, 10000);

        $this->assertEmpty($projected);
    }

    public function test_project_segments_segment_at_candidate_start_maps_to_zero(): void
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
            ['start_ms' => 10000, 'end_ms' => 15000, 'text' => 'At candidate start'],
        ];

        $transcript = MediaTranscript::create([
            'media_asset_id' => $mediaAsset->id,
            'derived_asset_id' => $derivedAsset->id,
            'status' => MediaTranscript::STATUS_COMPLETED,
            'segments' => $segments,
        ]);

        // Candidate starts at 10000
        $projected = $transcript->projectSegmentsToCandidate(10000, 20000);

        $this->assertCount(1, $projected);
        $this->assertEquals(0, $projected[0]['start_ms']);
        $this->assertEquals(5000, $projected[0]['end_ms']);
    }
}
