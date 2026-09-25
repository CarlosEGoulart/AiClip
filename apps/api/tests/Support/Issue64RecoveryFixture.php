<?php

namespace Tests\Support;

use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
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

    public static function ranking(bool $transcriptUsed): array
    {
        // Deliberately the valid CURRENT protocol: isolate behavior, not new-field rejection.
        return ['status' => 'success', 'ranking' => [
            'algorithm' => 'cross_encoder_reranker', 'algorithm_version' => '1.0.0',
            'parameters' => [
                'prototype_query' => config('media.clip_ranking_prototype_query'),
                'model_id' => 'cross-encoder/ms-marco-MiniLM-L-6-v2', 'model_revision' => 'main',
                'provider_name' => 'cross_encoder_ranking_provider', 'transcript_used' => $transcriptUsed,
                'normalization' => 'sigmoid', 'score_scale' => 1.0, 'tie_break' => 'm4_rank_then_chronological',
            ],
            'recommendations' => [
                ['m4_candidate_index' => 0, 'semantic_score' => 0.8, 'combined_rank' => 1],
                ['m4_candidate_index' => 1, 'semantic_score' => 0.6, 'combined_rank' => 2],
            ],
        ]];
    }

    public static function snapshot(MediaAsset $asset, bool $used): array
    {
        return [
            'duration_ms' => 40000,
            'candidates' => array_map(static fn ($c) => array_intersect_key($c, array_flip(['index', 'start_ms', 'end_ms', 'rank'])), $asset->clipAnalysis->candidates),
            'transcript_used' => $used, 'prototype_query' => config('media.clip_ranking_prototype_query'),
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
