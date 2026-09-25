<?php

namespace App\Contracts;

/**
 * PHP interface mirroring the Python ClipRankingProvider abstraction.
 * Implementations must provide semantic ranking of clip candidates.
 */
interface ClipRankingProvider
{
    /**
     * Rank candidates by semantic relevance.
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
    public function rank(array $candidates, string $prototypeQuery): array;

    /**
     * Get model identity information.
     *
     * @return array{model_id: string, model_revision: string, provider_name: string}
     */
    public function getModelIdentity(): array;
}
