<?php

namespace App\Services;

use App\Contracts\ClipRankingProvider;

/**
 * Deterministic PHP fake ranking provider for the CI/testing context,
 * mirroring the Python FakeRankingProvider's role. Fixed explicitly
 * non-cross-encoder identity, deterministic M4-rank-descending scores, and
 * input-derived transcript_used; no process, network, database, or media-file
 * access.
 */
final class FakeRankingProvider implements ClipRankingProvider
{
    private const FAKE_MODEL_ID = 'fake-ranking-v1';

    private const FAKE_MODEL_REVISION = 'v1.0.0';

    private const FAKE_PROVIDER_NAME = 'fake_ranking_provider';

    /**
     * Rank candidates with deterministic descending scores by M4 rank.
     *
     * @param  array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>  $candidates
     * @return array{
     *     recommendations: array<int, array{m4_candidate_index: int, semantic_score: float, combined_rank: int}>,
     *     model_id: string,
     *     model_revision: string,
     *     provider_name: string,
     *     transcript_used: bool
     * }
     */
    public function rank(array $candidates, string $prototypeQuery): array
    {
        $scored = [];
        foreach ($candidates as $candidate) {
            $m4Rank = $candidate['rank'];
            $scored[] = [
                'm4_candidate_index' => $candidate['index'],
                'semantic_score' => self::scoreForM4Rank($m4Rank),
                'm4_rank' => $m4Rank,
            ];
        }

        // Descending semantic_score, tie-break ascending M4 rank.
        usort($scored, fn (array $a, array $b): int => $b['semantic_score'] <=> $a['semantic_score']
            ?: $a['m4_rank'] <=> $b['m4_rank']);

        $recommendations = [];
        foreach ($scored as $position => $entry) {
            $recommendations[] = [
                'm4_candidate_index' => $entry['m4_candidate_index'],
                'semantic_score' => $entry['semantic_score'],
                'combined_rank' => $position + 1,
            ];
        }

        return [
            'recommendations' => $recommendations,
            'model_id' => self::FAKE_MODEL_ID,
            'model_revision' => self::FAKE_MODEL_REVISION,
            'provider_name' => self::FAKE_PROVIDER_NAME,
            'transcript_used' => self::hasTranscriptText($candidates),
        ];
    }

    /**
     * Get the fixed fake model identity.
     *
     * @return array{model_id: string, model_revision: string, provider_name: string}
     */
    public function getModelIdentity(): array
    {
        return [
            'model_id' => self::FAKE_MODEL_ID,
            'model_revision' => self::FAKE_MODEL_REVISION,
            'provider_name' => self::FAKE_PROVIDER_NAME,
        ];
    }

    /**
     * Deterministic score by M4 rank: 1.0 - (m4_rank - 1) * 0.1, clamped to
     * the inclusive range [0,1].
     */
    private static function scoreForM4Rank(int $m4Rank): float
    {
        return max(0.0, min(1.0, 1.0 - ($m4Rank - 1) * 0.1));
    }

    /**
     * Input-derived transcript availability: true iff any candidate carries
     * non-empty transcript_text, mirroring the worker action's rule.
     *
     * @param  array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>  $candidates
     */
    private static function hasTranscriptText(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (($candidate['transcript_text'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
