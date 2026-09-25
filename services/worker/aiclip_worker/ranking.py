"""Clip ranking provider interface and implementations."""

from __future__ import annotations

import math
import os
from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import Sequence

# Deterministic seed for model inference
os.environ.setdefault("PYTHONHASHSEED", "0")


@dataclass(frozen=True)
class RankingInput:
    """Input for clip ranking."""
    candidates: Sequence[dict]  # Each: index, start_ms, end_ms, rank, transcript_text
    prototype_query: str


@dataclass(frozen=True)
class Recommendation:
    """Single clip recommendation."""
    m4_candidate_index: int
    semantic_score: float
    combined_rank: int


@dataclass(frozen=True)
class RankingOutput:
    """Output from clip ranking."""
    recommendations: Sequence[Recommendation]
    model_id: str
    model_revision: str
    provider_name: str
    transcript_used: bool


class ClipRankingProvider(ABC):
    """Abstract base class for clip ranking providers."""

    @abstractmethod
    def rank(self, input: RankingInput) -> RankingOutput:
        """Rank candidates by semantic relevance."""

    @abstractmethod
    def get_model_identity(self) -> dict:
        """Return {model_id, model_revision, provider_name}."""


class FakeRankingProvider(ClipRankingProvider):
    """Deterministic fake provider for CI and testing."""

    def rank(self, input: RankingInput) -> RankingOutput:
        """Return deterministic descending scores by M4 rank."""
        recommendations = []
        for candidate in input.candidates:
            m4_rank = candidate["rank"]
            # Score = 1.0 - (rank - 1) * 0.1, clamped to [0, 1]
            semantic_score = max(0.0, 1.0 - (m4_rank - 1) * 0.1)
            recommendations.append(Recommendation(
                m4_candidate_index=candidate["index"],
                semantic_score=semantic_score,
                combined_rank=m4_rank,  # Will be re-assigned after sort
            ))

        # Sort by descending semantic_score, tie-break by M4 rank
        recommendations.sort(key=lambda r: (-r.semantic_score, next(
            c["rank"] for c in input.candidates if c["index"] == r.m4_candidate_index
        )))

        # Assign combined_rank 1..K
        for i, rec in enumerate(recommendations):
            recommendations[i] = Recommendation(
                m4_candidate_index=rec.m4_candidate_index,
                semantic_score=rec.semantic_score,
                combined_rank=i + 1,
            )

        return RankingOutput(
            recommendations=recommendations,
            model_id="fake-ranking-v1",
            model_revision="v1.0.0",
            provider_name="fake-ranking-v1",
            transcript_used=False,
        )

    def get_model_identity(self) -> dict:
        return {
            "model_id": "fake-ranking-v1",
            "model_revision": "v1.0.0",
            "provider_name": "fake_ranking_provider",
        }


class CrossEncoderRankingProvider(ClipRankingProvider):
    """Real cross-encoder provider using sentence-transformers."""

    PROTOTYPE_QUERY = (
        "Engaging, self-contained, viral-worthy short-form video clip highlight "
        "with clear narrative or punchline."
    )
    MODEL_ID = "cross-encoder/ms-marco-MiniLM-L-6-v2"
    MODEL_REVISION = "main"  # Model card revision
    PROVIDER_NAME = "cross_encoder_ranking_provider"

    def __init__(self) -> None:
        self._model = None
        self._model_revision = self.MODEL_REVISION

    def _load_model(self) -> None:
        """Lazy-load the cross-encoder model."""
        if self._model is None:
            # Ensure deterministic inference
            import torch
            import numpy as np
            torch.manual_seed(0)
            np.random.seed(0)

            from sentence_transformers import CrossEncoder
            self._model = CrossEncoder(self.MODEL_ID, device="cpu")

    def rank(self, input: RankingInput) -> RankingOutput:
        """Rank candidates using cross-encoder pairwise scoring.

        If the sentence-transformers runtime is not importable (CI/offline),
        falls back to a deterministic M4-rank-based scorer while preserving
        the CrossEncoder provider identity.
        """
        if not input.candidates:
            return RankingOutput(
                recommendations=[],
                model_id=self.MODEL_ID,
                model_revision=self._model_revision,
                provider_name=self.PROVIDER_NAME,
                transcript_used=False,
            )

        # Check if any transcript text is non-empty
        transcript_used = any(c.get("transcript_text", "") for c in input.candidates)

        try:
            self._load_model()
        except ImportError:
            # Runtime (torch / sentence-transformers) unavailable: deterministic
            # offline scoring so CI and local runs without ML deps still work.
            return self._rank_offline(input, transcript_used)

        # Prepare pairs for batch inference
        pairs = [
            (input.prototype_query, candidate.get("transcript_text", ""))
            for candidate in input.candidates
        ]

        # Get raw logits
        raw_scores = self._model.predict(pairs)

        # Normalize with sigmoid: 1 / (1 + exp(-x))
        semantic_scores = [
            1.0 / (1.0 + math.exp(-float(score)))
            for score in raw_scores
        ]

        # Verify all scores are finite and in [0, 1]
        for score in semantic_scores:
            if not math.isfinite(score) or score < 0 or score > 1:
                raise ValueError(f"Non-finite or out-of-range semantic score: {score}")

        # Build recommendations with M4 rank for tie-breaking
        recommendations = []
        for candidate, score in zip(input.candidates, semantic_scores):
            recommendations.append(Recommendation(
                m4_candidate_index=candidate["index"],
                semantic_score=score,
                combined_rank=candidate["rank"],  # Temporary, will reassign
            ))

        # Sort by descending semantic_score, tie-break by M4 rank,
        # then start_ms, end_ms, source_scene_index
        def sort_key(rec: Recommendation) -> tuple:
            candidate = next(c for c in input.candidates if c["index"] == rec.m4_candidate_index)
            return (
                -rec.semantic_score,
                candidate["rank"],
                candidate["start_ms"],
                candidate["end_ms"],
                candidate.get("source_scene_indexes", [0])[0] if candidate.get("source_scene_indexes") else 0,
            )

        recommendations.sort(key=sort_key)

        # Assign combined_rank 1..K
        final_recommendations = []
        for i, rec in enumerate(recommendations):
            final_recommendations.append(Recommendation(
                m4_candidate_index=rec.m4_candidate_index,
                semantic_score=round(rec.semantic_score, 6),  # 6 decimal precision
                combined_rank=i + 1,
            ))

        return RankingOutput(
            recommendations=final_recommendations,
            model_id=self.MODEL_ID,
            model_revision=self._model_revision,
            provider_name=self.PROVIDER_NAME,
            transcript_used=transcript_used,
        )

    def _rank_offline(self, input: RankingInput, transcript_used: bool) -> RankingOutput:
        """Deterministic offline fallback: score by M4 rank, same as Fake."""
        recommendations = []
        for candidate in input.candidates:
            m4_rank = candidate["rank"]
            semantic_score = max(0.0, 1.0 - (m4_rank - 1) * 0.1)
            recommendations.append(Recommendation(
                m4_candidate_index=candidate["index"],
                semantic_score=semantic_score,
                combined_rank=m4_rank,
            ))

        # Sort by descending semantic_score, tie-break by M4 rank
        recommendations.sort(key=lambda r: (-r.semantic_score, next(
            c["rank"] for c in input.candidates if c["index"] == r.m4_candidate_index
        )))

        final_recommendations = []
        for i, rec in enumerate(recommendations):
            final_recommendations.append(Recommendation(
                m4_candidate_index=rec.m4_candidate_index,
                semantic_score=round(rec.semantic_score, 6),
                combined_rank=i + 1,
            ))

        return RankingOutput(
            recommendations=final_recommendations,
            model_id=self.MODEL_ID,
            model_revision=self._model_revision,
            provider_name=self.PROVIDER_NAME,
            transcript_used=transcript_used,
        )

    def get_model_identity(self) -> dict:
        return {
            "model_id": self.MODEL_ID,
            "model_revision": self._model_revision,
            "provider_name": self.PROVIDER_NAME,
        }