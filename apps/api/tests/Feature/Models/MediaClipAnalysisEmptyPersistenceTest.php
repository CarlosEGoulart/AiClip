<?php

namespace Tests\Feature\Models;

use App\Exceptions\ProcessMediaException;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Issue60DbGuard;
use Tests\TestCase;

/*
 * Issue #60 eligible-empty real PostgreSQL persistence proof.
 *
 * Uses the authorized disposable target through the existing persistence
 * suite convention (RefreshDatabase). Identity guards fail closed before
 * any destructive write. Valid empties must persist completed with full
 * snapshots; fabricated empties must reject with the prior row untouched.
 */
class MediaClipAnalysisEmptyPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function guardIssue60Target(): void
    {
        $expectedDb = Issue60DbGuard::expectedDatabase();
        $this->assertTrue(extension_loaded('pdo_pgsql'), 'pdo_pgsql must be loaded');
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('pgsql', config('database.connections.pgsql.driver'));
        $this->assertSame($expectedDb, config('database.connections.pgsql.database'));
        $this->assertSame(1, DB::select('SELECT 1 AS one')[0]->one);
        $this->assertSame($expectedDb, DB::select('SELECT current_database() AS db')[0]->db);
        $this->assertSame($expectedDb, DB::connection()->getDatabaseName());
    }

    /**
     * @return array{configuration: array<string, mixed>}
     */
    private function configuration(): array
    {
        return [
            'min_duration_ms' => 5000, 'target_duration_ms' => 30000,
            'max_duration_ms' => 60000, 'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function provenance(bool $transcriptUsed): array
    {
        return [
            'configuration' => $this->configuration(),
            'effective_weights' => $transcriptUsed
                ? ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20]
                : ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
            'transcript_used' => $transcriptUsed,
            'candidate_policy' => 'whole_scene_non_overlapping',
            'timing_policy' => 'original_media_ms',
            'transcript_policy' => 'optional_strict_unshifted',
            'boundary_policy' => 'strict_interior_speech_cut',
            'score_scale' => 1000000, 'rounding' => 'half_up',
            'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
        ];
    }

    public function test_empty_scenes_persist_completed_with_full_snapshots(): void
    {
        $this->guardIssue60Target();

        $asset = MediaAsset::factory()->create();
        $analysis = MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $analysis->markAnalyzing();

        $snapshot = ['duration_ms' => 30000, 'scenes' => [], 'configuration' => $this->configuration()];
        $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

        $analysis->markCompleted('scene_timing_baseline', '1.0.0', $this->provenance(false), [], $snapshot, $execution);

        $stored = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame(MediaClipAnalysis::STATUS_COMPLETED, $stored->status);
        $this->assertSame([], $stored->candidates);
        $this->assertNull($stored->error);
        $this->assertSame($snapshot, $stored->input_snapshot);
        $this->assertSame($execution, $stored->execution_parameters);
        $this->assertSame($this->provenance(false), $stored->parameters);
        $this->assertDatabaseHas('media_clip_analyses', ['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_COMPLETED]);
    }

    public function test_all_ineligible_scenes_persist_completed_empty(): void
    {
        $this->guardIssue60Target();

        $asset = MediaAsset::factory()->create();
        $analysis = MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $analysis->markAnalyzing();

        $snapshot = [
            'duration_ms' => 120000,
            'scenes' => [
                ['index' => 0, 'start_ms' => 0, 'end_ms' => 3000],
                ['index' => 1, 'start_ms' => 3000, 'end_ms' => 70000],
            ],
            'transcript_segments' => [['start_ms' => 500, 'end_ms' => 1500]],
            'configuration' => $this->configuration(),
        ];
        $execution = ['timeout_seconds' => 30, 'lock_wait_seconds' => 35];

        $analysis->markCompleted('scene_timing_baseline', '1.0.0', $this->provenance(true), [], $snapshot, $execution);

        $stored = MediaClipAnalysis::where('media_asset_id', $asset->id)->first();
        $this->assertNotNull($stored);
        $this->assertSame(MediaClipAnalysis::STATUS_COMPLETED, $stored->status);
        $this->assertSame([], $stored->candidates);
        $this->assertNull($stored->error);
        $this->assertSame($snapshot, $stored->input_snapshot);
    }

    public function test_eligible_scenes_with_empty_candidates_reject_without_update(): void
    {
        $this->guardIssue60Target();

        $asset = MediaAsset::factory()->create();
        $analysis = MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => MediaClipAnalysis::STATUS_PENDING]);
        $analysis->markAnalyzing();
        $before = MediaClipAnalysis::where('media_asset_id', $asset->id)->first()->getRawOriginal();

        $snapshot = [
            'duration_ms' => 30000,
            'scenes' => [['index' => 0, 'start_ms' => 0, 'end_ms' => 30000]],
            'configuration' => $this->configuration(),
        ];

        $failure = null;
        try {
            $analysis->markCompleted(
                'scene_timing_baseline', '1.0.0', $this->provenance(false), [],
                $snapshot, ['timeout_seconds' => 30, 'lock_wait_seconds' => 35]
            );
        } catch (\Throwable $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(ProcessMediaException::class, $failure);
        $this->assertNull($failure->getPrevious());
        $this->assertSame($before, MediaClipAnalysis::where('media_asset_id', $asset->id)->first()->getRawOriginal());
    }
}
