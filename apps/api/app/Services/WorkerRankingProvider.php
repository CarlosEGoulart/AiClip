<?php

namespace App\Services;

use App\Contracts\ClipRankingProvider;
use App\Contracts\MediaProcessingContract;
use App\Exceptions\ProcessMediaException;

/**
 * Production clip ranking provider: a thin adapter that projects the
 * metadata-only rank_clips request from the given candidates and the fixed
 * prototype query, then delegates through the single existing
 * ProcessMediaAction::rankClips path (stdin transport, configured timeout,
 * ClipRecommendationValidator trust boundary). No duplicated process logic
 * and no second process path.
 */
final class WorkerRankingProvider implements ClipRankingProvider
{
    /**
     * Fixed cross-encoder identity of the production model selection, enforced
     * by the validator at the worker trust boundary.
     */
    private const MODEL_ID = 'cross-encoder/ms-marco-MiniLM-L-6-v2';

    private const MODEL_REVISION = 'main';

    private const PROVIDER_NAME = 'cross_encoder_ranking_provider';

    public function __construct(private ProcessMediaAction $action) {}

    /**
     * Rank candidates by delegating through the single rankClips path.
     *
     * Only the fixed configured prototype query is contract-valid; anything
     * else is rejected by the validator at the worker trust boundary.
     *
     * @param  array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>  $candidates
     * @return array{
     *     recommendations: array<int, array{m4_candidate_index: int, semantic_score: float, combined_rank: int}>,
     *     model_id: string,
     *     model_revision: string,
     *     provider_name: string,
     *     transcript_used: bool
     * }
     *
     * @throws ProcessMediaException
     */
    public function rank(array $candidates, string $prototypeQuery): array
    {
        $result = $this->action->rankClips($this->projectRequest($candidates, $prototypeQuery));

        $ranking = $result['ranking'];
        $parameters = $ranking['parameters'];

        return [
            'recommendations' => $ranking['recommendations'],
            'model_id' => $parameters['model_id'],
            'model_revision' => $parameters['model_revision'],
            'provider_name' => $parameters['provider_name'],
            'transcript_used' => $parameters['transcript_used'],
        ];
    }

    /**
     * Get the fixed cross-encoder model identity.
     *
     * @return array{model_id: string, model_revision: string, provider_name: string}
     */
    public function getModelIdentity(): array
    {
        return [
            'model_id' => self::MODEL_ID,
            'model_revision' => self::MODEL_REVISION,
            'provider_name' => self::PROVIDER_NAME,
        ];
    }

    /**
     * Project the metadata-only rank_clips request from the given candidates
     * and prototype query. The provider interface carries no media context,
     * so the media duration is derived from the candidates' timing.
     *
     * @param  array<int, array{index: int, start_ms: int, end_ms: int, rank: int, transcript_text: string}>  $candidates
     */
    private function projectRequest(array $candidates, string $prototypeQuery): MediaProcessingContract
    {
        $durationMs = 0;
        foreach ($candidates as $candidate) {
            if ($candidate['end_ms'] > $durationMs) {
                $durationMs = $candidate['end_ms'];
            }
        }

        $contract = new MediaProcessingContract;
        $contract->action = 'rank_clips';
        $contract->durationMs = $durationMs;
        $contract->candidates = $candidates;
        $contract->configuration = ['prototype_query' => $prototypeQuery];

        return $contract;
    }
}
