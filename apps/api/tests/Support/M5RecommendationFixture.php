<?php

namespace Tests\Support;

use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;
use App\Models\DerivedAsset;
use App\Models\MediaAsset;
use App\Models\MediaClipAnalysis;
use App\Models\MediaClipRecommendation;
use App\Models\MediaSceneAnalysis;
use App\Models\MediaTranscript;
use App\Services\ClipRankingProfile;
use App\Services\ClipRecommendationProjection;
use App\Services\ClipRecommendationValidator;
use App\Services\ProcessMediaAction;
use Illuminate\Support\Str;

/**
 * Shared M5 job fixtures, built from the replacement specification.
 *
 * Every upstream row is produced through production validation, so the M5
 * stage always binds a real authoritative M4 `scene_timing_baseline` v1.0.0
 * row instead of a hand-written abbreviation.
 */
final class M5RecommendationFixture
{
    public const DURATION_MS = 40000;

    /**
     * Transcript windows that overlap the golden M4 candidates.
     *
     * Candidate 0 is [0,10000], candidate 1 is [10000,20000], so the first
     * window covers only candidate 0 and the second only candidate 1.
     *
     * @return list<array{start_ms: int, end_ms: int, text: string}>
     */
    public static function segments(): array
    {
        return [
            ['start_ms' => 500, 'end_ms' => 9500, 'text' => 'first window text'],
            ['start_ms' => 10500, 'end_ms' => 19500, 'text' => 'second window text'],
        ];
    }

    /**
     * A segment that overlaps no candidate window at all.
     *
     * @return list<array{start_ms: int, end_ms: int, text: string}>
     */
    public static function disjointSegments(): array
    {
        return [['start_ms' => 25000, 'end_ms' => 30000, 'text' => 'unrelated window text']];
    }

    /**
     * Move a fresh asset to the probed state with a completed scene analysis,
     * so the job only has to resolve the M4/M5 stages.
     */
    public static function probedAsset(?string $audioCodec = 'aac'): MediaAsset
    {
        $asset = MediaAsset::factory()->create();
        $asset->markQueued((string) Str::uuid());
        $asset->markProcessing();
        $asset->markProbed([
            'duration_ms' => self::DURATION_MS,
            'video_codec' => 'h264',
            'audio_codec' => $audioCodec,
        ], self::DURATION_MS);

        $contract = ClipAnalysisFixture::contract();
        $scene = MediaSceneAnalysis::create([
            'media_asset_id' => $asset->id,
            'status' => MediaSceneAnalysis::STATUS_PENDING,
        ]);
        $scene->markDetecting();
        $scene->markCompleted('deterministic', '0.0.0', ['threshold' => 27], $contract->scenes, self::DURATION_MS);

        return $asset;
    }

    /**
     * Complete the authoritative M4 analysis for the asset.
     *
     * @param  list<array<string, mixed>>|null  $candidates  Defaults to the shared golden list.
     */
    public static function completedM4(MediaAsset $asset, ?array $candidates = null): MediaClipAnalysis
    {
        $contract = ClipAnalysisFixture::contract();
        $analysis = ClipAnalysisFixture::response()['analysis'];

        $row = MediaClipAnalysis::create([
            'media_asset_id' => $asset->id,
            'status' => MediaClipAnalysis::STATUS_PENDING,
        ]);
        $row->markAnalyzing();
        $row->markCompleted(
            $analysis['algorithm'],
            $analysis['algorithm_version'],
            $analysis['parameters'],
            $candidates ?? $analysis['candidates'],
            [
                'duration_ms' => self::DURATION_MS,
                'scenes' => $contract->scenes,
                'configuration' => $contract->configuration,
                'transcript_segments' => $contract->transcriptSegments,
            ],
            ['timeout_seconds' => 30, 'lock_wait_seconds' => 35],
        );

        return $row;
    }

    /**
     * A legitimately empty completed M4 analysis: no scene is eligible.
     */
    public static function completedEmptyM4(MediaAsset $asset): MediaClipAnalysis
    {
        $configuration = [
            'min_duration_ms' => 5000, 'target_duration_ms' => 30000,
            'max_duration_ms' => 60000, 'max_candidates' => 20,
            'weights' => ['duration_fit' => 50, 'speech_coverage' => 30, 'boundary_alignment' => 20],
        ];

        $row = MediaClipAnalysis::create([
            'media_asset_id' => $asset->id,
            'status' => MediaClipAnalysis::STATUS_PENDING,
        ]);
        $row->markAnalyzing();
        $row->markCompleted('scene_timing_baseline', '1.0.0', [
            'configuration' => $configuration,
            'effective_weights' => ['duration_fit' => 50, 'speech_coverage' => 0, 'boundary_alignment' => 0],
            'transcript_used' => false,
            'candidate_policy' => 'whole_scene_non_overlapping',
            'timing_policy' => 'original_media_ms',
            'transcript_policy' => 'optional_strict_unshifted',
            'boundary_policy' => 'strict_interior_speech_cut',
            'score_scale' => 1000000, 'rounding' => 'half_up',
            'limits' => ['max_scenes' => 10000, 'max_transcript_segments' => 50000, 'max_input_bytes' => 8388608, 'max_duration_ms' => 2147483647],
        ], [], [
            'duration_ms' => 30000, 'scenes' => [], 'configuration' => $configuration,
        ], ['timeout_seconds' => 30, 'lock_wait_seconds' => 35]);

        return $row;
    }

    /**
     * A current audio_normalized derivative with no transcript row at all.
     */
    public static function derivedAudio(MediaAsset $asset): DerivedAsset
    {
        return DerivedAsset::create([
            'media_asset_id' => $asset->id, 'type' => DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media', 'storage_key' => "m5-test/{$asset->id}/audio.wav",
            'mime_type' => 'audio/wav', 'size_bytes' => 1024, 'duration_ms' => self::DURATION_MS,
            'sample_rate' => 16000, 'channels' => 1, 'codec' => 'pcm_s16le',
        ]);
    }

    /**
     * A pending, never-completed M4 analysis: the stage is not ready.
     */
    public static function pendingM4(MediaAsset $asset): MediaClipAnalysis
    {
        return MediaClipAnalysis::create([
            'media_asset_id' => $asset->id,
            'status' => MediaClipAnalysis::STATUS_PENDING,
        ]);
    }

    /**
     * A transcript in one of the specification's upstream states.
     *
     * @param  string  $state  completed, pending, transcribing, failed or missing
     * @param  list<array{start_ms: int, end_ms: int, text: string}>|null  $segments
     * @param  bool  $stale  Reference an archived derivative instead of a current
     *                       audio_normalized row, so the authoritative audio
     *                       extraction boundary is actually reached.
     */
    public static function transcript(MediaAsset $asset, string $state = 'completed', ?array $segments = null, bool $stale = false): ?MediaTranscript
    {
        if ($state === 'missing') {
            // A resolved upstream with no transcript row: the current audio
            // derivative already exists, so no extraction is attempted.
            self::derivedAudio($asset);

            return null;
        }

        if ($state === 'empty') {
            // A completed transcript with no segments at all.
            return self::transcript($asset, 'completed', [], $stale);
        }

        $audio = DerivedAsset::create([
            'media_asset_id' => $asset->id,
            'type' => $stale ? 'archived_audio' : DerivedAsset::TYPE_AUDIO_NORMALIZED,
            'storage_disk' => 'media', 'storage_key' => "m5-test/{$asset->id}/audio.wav",
            'mime_type' => 'audio/wav', 'size_bytes' => 1024, 'duration_ms' => self::DURATION_MS,
            'sample_rate' => 16000, 'channels' => 1, 'codec' => 'pcm_s16le',
        ]);

        $transcript = MediaTranscript::create([
            'media_asset_id' => $asset->id, 'derived_asset_id' => $audio->id,
            'status' => MediaTranscript::STATUS_PENDING,
        ]);

        if ($state === 'transcribing') {
            $transcript->markTranscribing();

            return $transcript;
        }

        if ($state === 'pending') {
            return $transcript;
        }

        if ($state === 'failed') {
            $transcript->markFailed('synthetic_transcription_failure');

            return $transcript;
        }

        $segments ??= self::segments();
        $transcript->markTranscribing();
        $transcript->markCompleted(
            'en',
            'synthetic full text',
            $segments,
            'test-engine',
            'test-model',
        );

        return $transcript;
    }

    /**
     * The valid current-protocol response of the explicitly selected profile.
     *
     * The fake profile is deterministic fixture units, never neural inference,
     * and the digest binds the response to the exact bytes Laravel sent.
     *
     * @return array{status: string, ranking: array<string, mixed>}
     */
    public static function rankingResult(MediaProcessingContract $contract): array
    {
        $configuration = ClipRankingProfile::configuration();

        $recommendations = [];
        $rank = 0;
        foreach ($contract->candidates as $candidate) {
            $rank++;
            $recommendations[] = [
                'm4_candidate_index' => $candidate['index'],
                'start_ms' => $candidate['start_ms'],
                'end_ms' => $candidate['end_ms'],
                'm4_rank' => $candidate['m4_rank'],
                'm4_score' => $candidate['m4_score'],
                'semantic_score' => (float) (self::fakeUnits($candidate['m4_rank']) / ClipRecommendationValidator::SCORE_UNITS),
                'semantic_rank' => $rank,
                'reason' => null,
            ];
        }

        return [
            'status' => 'success',
            'ranking' => [
                'algorithm' => $configuration['algorithm'],
                'algorithm_version' => $configuration['algorithm_version'],
                'parameters' => ClipRankingProfile::parameters(
                    $configuration,
                    ClipRankingProfile::inferencePerformed($configuration['provider']),
                    true
                ),
                'request_sha256' => self::requestDigest($contract),
                'recommendations' => $recommendations,
            ],
        ];
    }

    /**
     * The specification's deterministic fake score units for an M4 rank.
     */
    public static function fakeUnits(int $m4Rank): int
    {
        return max(0, ClipRecommendationValidator::SCORE_UNITS - ($m4Rank - 1) * 100000);
    }

    /**
     * SHA256 of the exact canonical request bytes Laravel sends to the worker.
     * Uses canonical JSON (sorted keys, no whitespace) per spec.md Entry J.
     */
    public static function requestDigest(MediaProcessingContract $contract): string
    {
        return CanonicalJson::sha256($contract->toRankClipsMetadataArray());
    }

    /**
     * A recording action double for the whole job: only the ranking boundary is
     * implemented, and every upstream stage must be reused from its fixture.
     *
     * @param  bool  $failExtraction  Reach the authoritative audio extraction
     *                                boundary and record a real failure.
     */
    public static function recordingAction(?array $response = null, bool $failExtraction = false): ProcessMediaAction
    {
        return new class($response, $failExtraction) extends ProcessMediaAction
        {
            public int $rankCalls = 0;

            /** @var list<array<string, mixed>> */
            public array $rankRequests = [];

            public bool $textReachedWorker = false;

            public function __construct(private ?array $response, private bool $failExtraction) {}

            public function probe(MediaProcessingContract $contract): array
            {
                throw new ProcessMediaException('SETUP_BLOCKER: probed fixture was not reused');
            }

            public function detectScenes(MediaProcessingContract $contract): array
            {
                throw new ProcessMediaException('SETUP_BLOCKER: scene fixture was not reused');
            }

            public function extractAudio(MediaProcessingContract $contract): array
            {
                if ($this->failExtraction) {
                    throw new ProcessMediaException('synthetic_extraction_failed');
                }

                throw new ProcessMediaException('SETUP_BLOCKER: audio fixture was not reused');
            }

            public function transcribe(MediaProcessingContract $contract): array
            {
                throw new ProcessMediaException('SETUP_BLOCKER: transcript fixture was not reused');
            }

            public function analyzeClips(MediaProcessingContract $contract): array
            {
                throw new ProcessMediaException('SETUP_BLOCKER: M4 fixture was not reused');
            }

            public function rankClips(MediaProcessingContract $contract): array
            {
                $this->rankCalls++;
                $this->rankRequests[] = $contract->toRankClipsMetadataArray();
                $this->textReachedWorker = str_contains(json_encode($contract->candidates), 'window text');

                return $this->response ?? M5RecommendationFixture::rankingResult($contract);
            }
        };
    }

    /**
     * A validated local unavailable completion for the M4 authority and reason.
     *
     * @return array<string, mixed>
     */
    public static function localUnavailableOutcome(MediaClipAnalysis $m4, string $reason, ?string $state = null): array
    {
        $candidates = self::recordedM4Candidates($m4);

        return MediaClipRecommendation::localCompletion(
            ClipRankingProfile::configuration(),
            (int) $m4->id,
            $candidates,
            self::DURATION_MS,
            $state ?? $reason,
            ClipRecommendationProjection::emptyTextHashes(
                array_map(static fn (array $candidate): int => $candidate['index'], $candidates)
            ),
            ClipRecommendationValidator::localUnavailableRecommendations($candidates, $reason),
            ['timeout_seconds' => 60, 'lock_wait_seconds' => 65],
        );
    }

    /**
     * The M4 candidates exactly as the durable M5 snapshot records them.
     *
     * @return list<array<string, mixed>>
     */
    public static function recordedM4Candidates(MediaClipAnalysis $m4): array
    {
        return array_map(static fn (array $candidate): array => [
            'index' => (int) $candidate['index'],
            'start_ms' => (int) $candidate['start_ms'],
            'end_ms' => (int) $candidate['end_ms'],
            'rank' => (int) $candidate['rank'],
            'score' => $candidate['score'],
            'criteria' => $candidate['criteria'],
            'source_scene_indexes' => $candidate['source_scene_indexes'],
        ], $m4->fresh()->candidates);
    }

    /**
     * The persisted M5 row of the asset, or null when the stage committed none.
     */
    public static function row(MediaAsset $asset): ?MediaClipRecommendation
    {
        return MediaClipRecommendation::where('media_asset_id', $asset->id)->first();
    }
}
