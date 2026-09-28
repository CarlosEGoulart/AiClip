<?php

namespace Tests\Support;

use App\Contracts\MediaProcessingContract;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

final class Issue64RecoveryFixture
{
    public static function guard(): void
    {
        $c = DB::connection();
        if (app()->environment() !== 'testing' || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'aiclip_test_issue64'
            || $c->getDatabaseName() !== 'aiclip_test_issue64') {
            throw new RuntimeException('SETUP_BLOCKER: unauthorized database configuration');
        }
        $p = $c->getPdo();
        if ($p->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || $p->query('SELECT current_database()')->fetchColumn() !== 'aiclip_test_issue64'
            || (int) $p->query('SELECT 1')->fetchColumn() !== 1 || DB::transactionLevel() !== 0) {
            throw new RuntimeException('SETUP_BLOCKER: database identity or outer transaction');
        }
    }

    public static function observer(): PDO
    {
        $c = DB::connection()->getConfig();
        $pdo = new PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}",
            $c['username'], $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
        if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'aiclip_test_issue64') {
            throw new RuntimeException('SETUP_BLOCKER: observer database mismatch');
        }

        return $pdo;
    }

    public static function row(PDO $pdo, string $table, int $assetId): ?array
    {
        if (! in_array($table, ['media_assets', 'media_clip_analyses', 'media_clip_recommendations', 'media_transcripts'], true)) {
            throw new RuntimeException('Invalid test observation table');
        }
        $key = $table === 'media_assets' ? 'id' : 'media_asset_id';
        $q = $pdo->prepare("SELECT * FROM {$table} WHERE {$key} = ?");
        $q->execute([$assetId]);

        return $q->fetch() ?: null;
    }

    public static function create(string $state = 'completed', ?string $audio = 'aac', bool $derived = true): MediaAsset
    {
        $asset = MediaAsset::factory()->create();
        $asset->markQueued((string) Str::uuid());
        $asset->markProcessing();
        $asset->markProbed(['duration_ms' => 40000, 'video_codec' => 'h264', 'audio_codec' => $audio], 40000);
        $contract = ClipAnalysisFixture::contract();
        $scene = MediaSceneAnalysis::create(['media_asset_id' => $asset->id, 'status' => 'pending']);
        $scene->markDetecting();
        $scene->markCompleted('deterministic', '0.0.0', ['threshold' => 27], $contract->scenes, 40000);
        $m4 = MediaClipAnalysis::create(['media_asset_id' => $asset->id, 'status' => 'pending']);
        $m4->markAnalyzing();
        $r = ClipAnalysisFixture::response()['analysis'];
        $m4->markCompleted($r['algorithm'], $r['algorithm_version'], $r['parameters'], $r['candidates'], [
            'duration_ms' => 40000, 'scenes' => $contract->scenes,
            'configuration' => $contract->configuration, 'transcript_segments' => $contract->transcriptSegments,
        ], ['timeout_seconds' => 30, 'lock_wait_seconds' => 35]);
        // The existing FK is mandatory. A stale transcript references an archived
        // derivative, not a current audio_normalized row that would bypass extraction.
        $audioRow = DerivedAsset::create([
            'media_asset_id' => $asset->id, 'type' => $derived ? DerivedAsset::TYPE_AUDIO_NORMALIZED : 'archived_audio',
            'storage_disk' => 'media', 'storage_key' => "issue64-test/{$asset->id}/audio.wav",
            'mime_type' => 'audio/wav', 'size_bytes' => 1024, 'duration_ms' => 40000,
            'sample_rate' => 16000, 'channels' => 1, 'codec' => 'pcm_s16le',
        ]);
        $transcript = MediaTranscript::create([
            'media_asset_id' => $asset->id, 'derived_asset_id' => $audioRow->id, 'status' => 'pending',
        ]);
        if ($state !== 'pending') {
            $transcript->markTranscribing();
        }
        if ($state === 'completed') {
            $transcript->markCompleted('en', 'SYNTHETIC_64_ONLY', self::segments(), 'test-engine', 'test-model');
        }

        return $asset;
    }

    public static function segments(): array
    {
        return [
            ['start_ms' => 500, 'end_ms' => 9000, 'text' => "  SYNTHETIC_64_A\t Café  "],
            ['start_ms' => 9000, 'end_ms' => 11000, 'text' => "\n SYNTHETIC_64_CROSS\r "],
            ['start_ms' => 11000, 'end_ms' => 19000, 'text' => "SYNTHETIC_64_B\f end"],
        ];
    }

    /**
     * The valid current-protocol worker response for the selected profile.
     *
     * Deliberately a full current protocol, not a pre-replacement fixture, so
     * these tests isolate behavior rather than failing on new-field rejection.
     * Fake identity, fake score units and the exact digest of the sent bytes
     * are all truthful: this is a PHP boundary double, never inference.
     *
     * Accepts either a MediaProcessingContract or the array returned by
     * MediaProcessingContract::rankClipsRequest().
     *
     * @param  array{version: string, action: string, media: array{duration_ms: int}, candidates: array, configuration: array}|MediaProcessingContract  $contract
     * @return array{status: string, ranking: array<string, mixed>}
     */
    public static function ranking(array|MediaProcessingContract $contract): array
    {
        // Handle array input from rankClipsRequest()
        if (is_array($contract)) {
            $configuration = $contract['configuration'];
            $candidates = $contract['candidates'];
        } else {
            $configuration = ClipRankingProfile::configuration();
            $candidates = $contract->candidates;
        }

        $recommendations = [];
        $rank = 0;
        foreach ($candidates as $candidate) {
            $rank++;
            $recommendations[] = [
                'm4_candidate_index' => $candidate['index'],
                'start_ms' => $candidate['start_ms'],
                'end_ms' => $candidate['end_ms'],
                'm4_rank' => $candidate['m4_rank'],
                'm4_score' => $candidate['m4_score'],
                'semantic_score' => (float) (max(0, 1000000 - ($candidate['m4_rank'] - 1) * 100000) / 1000000),
                'semantic_rank' => $rank,
                'reason' => null,
            ];
        }

        return [
            'status' => 'success',
            'ranking' => [
                'algorithm' => $configuration['algorithm'],
                'algorithm_version' => $configuration['algorithm_version'],
                'parameters' => ClipRankingProfile::parameters($configuration, false, true),
                'request_sha256' => self::requestDigest($contract),
                'recommendations' => $recommendations,
            ],
        ];
    }

    /**
     * SHA256 of the exact request bytes Laravel sends to the worker.
     *
     * Accepts either a MediaProcessingContract or the array returned by
     * MediaProcessingContract::rankClipsRequest().
     *
     * @param  array{version: string, action: string, media: array{duration_ms: int}, candidates: array, configuration: array}|MediaProcessingContract  $contract
     */
    public static function requestDigest(array|MediaProcessingContract $contract): string
    {
        if (is_array($contract)) {
            return hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR));
        }

        return hash('sha256', json_encode($contract->toRankClipsMetadataArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * A validated current-protocol completion of a worker result.
     *
     * Accepts either a MediaProcessingContract or the array returned by
     * MediaProcessingContract::rankClipsRequest() (which calls
     * toRankClipsMetadataArray() internally).
     *
     * @param  array{version: string, action: string, media: array{duration_ms: int}, candidates: array, configuration: array}|MediaProcessingContract  $contract
     * @return array<string, mixed>
     */
    public static function completion(array|MediaProcessingContract $contract, int $m4AnalysisId): array
    {
        // Handle array input from rankClipsRequest()
        if (is_array($contract)) {
            $metadata = $contract;
            $durationMs = $metadata['media']['duration_ms'];
            $candidates = $metadata['candidates'];
            $configuration = $metadata['configuration'];
        } else {
            $metadata = $contract->toRankClipsMetadataArray();
            $durationMs = $contract->durationMs;
            $candidates = $contract->candidates;
            $configuration = $contract->configuration;
        }

        $ranking = self::ranking($contract)['ranking'];

        return [
            'algorithm' => $ranking['algorithm'],
            'algorithm_version' => $ranking['algorithm_version'],
            'parameters' => $ranking['parameters'],
            'recommendations' => $ranking['recommendations'],
            'input_snapshot' => [
                'm4_analysis_id' => $m4AnalysisId,
                'm4_algorithm' => ClipRecommendationValidator::M4_ALGORITHM,
                'm4_algorithm_version' => ClipRecommendationValidator::M4_ALGORITHM_VERSION,
                'm4_candidates' => ClipAnalysisFixture::response()['analysis']['candidates'],
                'duration_ms' => $durationMs,
                'transcript_state' => 'completed_valid',
                'projection_version' => $configuration['projection_version'],
                'text_hashes' => array_map(static fn (array $candidate): array => [
                    'index' => (int) $candidate['index'],
                    'sha256' => hash('sha256', $candidate['transcript_text']),
                ], $candidates),
                'request_sha256' => $ranking['request_sha256'],
            ],
            'execution_parameters' => ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
        ];
    }

    public static function cleanup(MediaAsset $asset): void
    {
        $project = $asset->project;
        $user = $project->user;
        $asset->transcript()->delete();
        $asset->delete();
        $project->delete();
        $user?->delete();
    }
}
