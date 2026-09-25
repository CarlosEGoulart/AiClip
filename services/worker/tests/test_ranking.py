"""Ranking provider interface and implementations."""

from __future__ import annotations

import math
import sys
from types import ModuleType, SimpleNamespace

import pytest

# These imports will fail initially - this is the expected RED
from aiclip_worker.ranking import (
    ClipRankingProvider,
    FakeRankingProvider,
    CrossEncoderRankingProvider,
    RankingInput,
    RankingOutput,
)


def create_ranking_input(candidates=None, prototype_query=None):
    """Create a RankingInput for testing."""
    if candidates is None:
        candidates = [
            {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "engaging content"},
            {"index": 1, "start_ms": 10000, "end_ms": 20000, "rank": 2, "transcript_text": ""},
        ]
    if prototype_query is None:
        prototype_query = "Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline."
    return RankingInput(candidates=candidates, prototype_query=prototype_query)


def test_loader_is_pinned_local_only_and_cpu(monkeypatch, tmp_path):
    calls = []

    class Identity:
        pass

    class RecordingCrossEncoder:
        def __init__(self, model_id, **kwargs):
            calls.append((model_id, kwargs))

    torch = ModuleType("torch")
    torch.manual_seed = lambda seed: None
    torch.nn = SimpleNamespace(Identity=Identity)
    numpy = ModuleType("numpy")
    numpy.random = SimpleNamespace(seed=lambda seed: None)
    transformers = ModuleType("sentence_transformers")
    transformers.CrossEncoder = RecordingCrossEncoder
    monkeypatch.setitem(sys.modules, "torch", torch)
    monkeypatch.setitem(sys.modules, "numpy", numpy)
    monkeypatch.setitem(sys.modules, "sentence_transformers", transformers)
    cache = str(tmp_path / "operator-cache")
    monkeypatch.setenv("SENTENCE_TRANSFORMERS_HOME", cache)
    provider = CrossEncoderRankingProvider()
    provider._load_model()
    assert len(calls) == 1, "The recording constructor must actually be reached"
    model_id, kwargs = calls[0]
    observed = {
        "model_id": model_id, "revision": kwargs.get("revision"),
        "local_files_only": kwargs.get("local_files_only"),
        "trust_remote_code": kwargs.get("trust_remote_code"),
        "device": kwargs.get("device"), "cache_dir": kwargs.get("cache_dir"),
        "use_safetensors": kwargs.get("automodel_args", {}).get("use_safetensors"),
        "identity_activation": isinstance(kwargs.get("default_activation_function"), Identity),
    }
    assert observed == {
        "model_id": "cross-encoder/ms-marco-MiniLM-L6-v2",
        "revision": "233902d25c440f23af6f7d6e94d2946bac0bee0a",
        "local_files_only": True, "trust_remote_code": False,
        "device": "cpu", "cache_dir": cache,
        "use_safetensors": True, "identity_activation": True,
    }


def test_quantized_ties_use_m4_rank():
    calls = []

    class Model:
        def predict(self, pairs):
            calls.append(len(pairs))
            return [0.0000004, 0.0]

    provider = CrossEncoderRankingProvider()
    provider._model = Model()
    output = provider.rank(create_ranking_input([
        {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 2, "transcript_text": "Synthetic first passage"},
        {"index": 1, "start_ms": 10000, "end_ms": 20000, "rank": 1, "transcript_text": "Synthetic second passage"},
    ]))
    assert calls == [2], "Inference stub must receive both eligible candidates"
    assert [r.semantic_score for r in output.recommendations] == [0.5, 0.5]
    assert [r.combined_rank for r in output.recommendations] == [1, 2]
    assert [r.m4_candidate_index for r in output.recommendations] == [1, 0]


def test_clip_ranking_provider_interface_exists():
    """ClipRankingProvider abstract base class must exist (currently fails - RED)."""
    assert ClipRankingProvider is not None, "ClipRankingProvider not imported"
    assert hasattr(ClipRankingProvider, 'rank'), "Missing rank method"
    assert hasattr(ClipRankingProvider, 'get_model_identity'), "Missing get_model_identity method"


def test_fake_ranking_provider_returns_deterministic_scores():
    """FakeRankingProvider must return deterministic scores by M4 rank (currently fails - RED)."""
    provider = FakeRankingProvider()
    input_data = create_ranking_input()
    output = provider.rank(input_data)

    assert len(output.recommendations) == 2
    # First candidate (rank 1) gets highest score 1.0
    assert output.recommendations[0].semantic_score == 1.0
    # Second candidate (rank 2) gets score 0.9
    assert output.recommendations[1].semantic_score == 0.9
    # Combined rank follows semantic score order
    assert output.recommendations[0].combined_rank == 1
    assert output.recommendations[1].combined_rank == 2
    # Provenance
    assert output.transcript_used is False
    assert output.provider_name == "fake-ranking-v1"
    identity = provider.get_model_identity()
    assert identity["model_id"] == "fake-ranking-v1"
    assert identity["provider_name"] == "fake_ranking_provider"


def test_cross_encoder_ranking_provider_loads_model():
    """CrossEncoderRankingProvider must load the correct model (currently fails - RED)."""
    provider = CrossEncoderRankingProvider()
    identity = provider.get_model_identity()

    assert identity["model_id"] == "cross-encoder/ms-marco-MiniLM-L-6-v2"
    assert identity["provider_name"] == "cross_encoder_ranking_provider"
    assert "model_revision" in identity


def test_ranking_input_validation():
    """RankingInput must validate required fields."""
    # Valid input
    input_data = create_ranking_input()
    assert input_data.candidates[0]["index"] == 0
    assert input_data.prototype_query == "Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline."


def test_ranking_output_structure():
    """RankingOutput must have correct structure."""
    # This will be tested when provider is implemented
    pass


def test_fake_provider_transcript_used_false():
    """FakeRankingProvider must always return transcript_used=False."""
    provider = FakeRankingProvider()
    # Even with transcript text, fake provider returns transcript_used=False
    input_data = create_ranking_input([
        {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "some text"},
    ])
    output = provider.rank(input_data)
    assert output.transcript_used is False


def test_cross_encoder_provider_scores_empty_text():
    """CrossEncoderRankingProvider must score empty transcript text honestly."""
    provider = CrossEncoderRankingProvider()
    input_data = create_ranking_input([
        {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": ""},
        {"index": 1, "start_ms": 10000, "end_ms": 20000, "rank": 2, "transcript_text": ""},
    ])
    output = provider.rank(input_data)
    assert output.transcript_used is False
    assert len(output.recommendations) == 2
    for rec in output.recommendations:
        assert 0 <= rec.semantic_score <= 1
        assert rec.m4_candidate_index in (0, 1)


# ---------------------------------------------------------------------------
# W2.1 / W2.8 tamper assertions (test-plan.md W2.1 exact golden output and
# W2.8 plausible-wrong-result detection; clarification C3/C4 makes these the
# worker-suite score-tamper detection obligations, backed by the fixed-seed
# deterministic fixture and hand-recorded constants).
# ---------------------------------------------------------------------------


class _FixedSeedLogitModel:
    """Deterministic fixture standing in for fixed-seed model inference.

    Returns hand-recorded raw logits per candidate transcript text so the
    production sigmoid normalization runs on known inputs without downloading
    or invoking the production model (test-plan fixture rule: fixed seed,
    known inputs -> known raw scores -> known sigmoid-normalized scores,
    expectations as hand-derived constants).
    """

    def __init__(self, logits_by_text):
        self._logits_by_text = logits_by_text

    def predict(self, pairs):
        """Return raw logits aligned with the (prototype_query, text) pairs."""
        return [self._logits_by_text[text] for _query, text in pairs]


# Golden fixture candidates: M4 ranks intentionally do not follow input order
# (candidate 0 holds M4 rank 2 while candidate 1 holds M4 rank 1), so the
# golden combined_rank order proves semantic ordering rather than M4 ordering.
GOLDEN_CANDIDATES_W2_1 = [
    {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 2, "transcript_text": "engaging content"},
    {"index": 1, "start_ms": 10000, "end_ms": 20000, "rank": 1, "transcript_text": ""},
    {"index": 2, "start_ms": 20000, "end_ms": 30000, "rank": 3, "transcript_text": "punchline ending"},
]

# Hand-recorded raw logits of the fixed-seed fixture (no production model).
GOLDEN_RAW_LOGITS_W2_1 = {
    "engaging content": 2.0,
    "": 0.0,
    "punchline ending": -1.0,
}

# Hand-recorded golden output: production formula
#   semantic_score = round(1 / (1 + exp(-raw_score)), 6)
# sorted descending with combined_rank 1..K assigned afterwards:
#   sigmoid( 2.0) = 0.8807970779778823 -> 0.880797
#   sigmoid( 0.0) = 0.5                -> 0.5
#   sigmoid(-1.0) = 0.2689414213699951 -> 0.268941
GOLDEN_RECOMMENDATIONS_W2_1 = [
    (0, 0.880797, 1),
    (1, 0.5, 2),
    (2, 0.268941, 3),
]

# Exact expected model identity dict for the CrossEncoder provider (W2.8).
GOLDEN_IDENTITY_W2_1 = {
    "model_id": "cross-encoder/ms-marco-MiniLM-L-6-v2",
    "model_revision": "main",
    "provider_name": "cross_encoder_ranking_provider",
}


def run_golden_output(candidates=None, logits=None):
    """Run CrossEncoderRankingProvider against the fixed-seed fixture.

    The fixture model is assigned before rank(), so _load_model() short-circuits
    on the non-None guard and no torch/sentence-transformers import, model
    download, or network access occurs.
    """
    provider = CrossEncoderRankingProvider()
    provider._model = _FixedSeedLogitModel(
        GOLDEN_RAW_LOGITS_W2_1 if logits is None else logits
    )
    if candidates is None:
        candidates = GOLDEN_CANDIDATES_W2_1
    output = provider.rank(create_ranking_input(candidates))
    return output, provider


def recommendation_tuples(output):
    """Flatten recommendations to comparable (index, score, combined_rank)."""
    return [
        (rec.m4_candidate_index, rec.semantic_score, rec.combined_rank)
        for rec in output.recommendations
    ]


def is_plausible_recommendations(tuples):
    """Structural-only sanity: shapes/ranges/ordering/precision/ranks valid.

    Mirrors the structural rules a range/order sanity pass accepts, so the
    tamper variants used below are provably plausible-but-wrong rather than
    obviously malformed values.
    """
    if not tuples:
        return False

    scores = [entry[1] for entry in tuples]
    indices = [entry[0] for entry in tuples]
    ranks = [entry[2] for entry in tuples]

    if any(not (0.0 <= score <= 1.0) for score in scores):
        return False
    if any(score != round(score, 6) for score in scores):
        return False
    if scores != sorted(scores, reverse=True):
        return False
    if ranks != list(range(1, len(tuples) + 1)):
        return False
    if sorted(indices) != list(range(len(tuples))):
        return False

    return True


def assert_matches_golden(actual, golden):
    """Exact comparison against the hand-recorded golden constants."""
    assert actual == golden


def test_w2_1_exact_golden_output_with_sigmoid_normalized_scores():
    """W2.1: known texts + prototype query -> exact 6-decimal golden output."""
    output, _provider = run_golden_output()
    actual = recommendation_tuples(output)

    # Independent hand computation of the sigmoid constants (simple
    # calculation, not a call to the production model).
    assert round(1.0 / (1.0 + math.exp(-2.0)), 6) == 0.880797
    assert round(1.0 / (1.0 + math.exp(0.0)), 6) == 0.5
    assert round(1.0 / (1.0 + math.exp(1.0)), 6) == 0.268941

    # Exact expected semantic_score (6 decimals) and combined_rank order.
    assert_matches_golden(actual, GOLDEN_RECOMMENDATIONS_W2_1)
    assert actual[0][1] == 0.880797
    assert actual[1][1] == 0.5
    assert actual[2][1] == 0.268941

    # combined_rank follows descending semantic_score: candidate 0 carries
    # M4 rank 2 yet wins combined rank 1 because its score is highest, while
    # the M4 rank 1 candidate lands at combined rank 2.
    assert [entry[2] for entry in actual] == [1, 2, 3]
    assert [entry[0] for entry in actual] == [0, 1, 2]
    assert GOLDEN_CANDIDATES_W2_1[0]["rank"] == 2
    assert GOLDEN_CANDIDATES_W2_1[1]["rank"] == 1

    # Every score is finite, in the inclusive range, and 6-decimal exact.
    for _index, score, _combined_rank in actual:
        assert 0.0 <= score <= 1.0
        assert score == round(score, 6)

    # Provenance of the fixed-seed fixture run.
    assert output.transcript_used is True
    assert output.model_id == "cross-encoder/ms-marco-MiniLM-L-6-v2"
    assert output.provider_name == "cross_encoder_ranking_provider"


def test_w2_1_tie_break_by_m4_rank_when_scores_equal_within_1e_9():
    """W2.1: scores equal within 1e-9 tie-break by ascending M4 rank."""
    candidates = [
        {"index": 0, "start_ms": 10000, "end_ms": 20000, "rank": 2, "transcript_text": "first"},
        {"index": 1, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "second"},
    ]
    equal_logits = {"first": 1.0, "second": 1.0}
    output, _provider = run_golden_output(candidates, equal_logits)
    actual = recommendation_tuples(output)

    scores = [entry[1] for entry in actual]
    assert abs(scores[0] - scores[1]) <= 1e-9

    # sigmoid(1.0) = 0.7310585786300049 -> 0.731059 for both candidates.
    assert round(1.0 / (1.0 + math.exp(-1.0)), 6) == 0.731059

    # Equal scores: M4 rank 1 wins even though candidate 1 appears second in
    # the input list.
    assert_matches_golden(actual, [(1, 0.731059, 1), (0, 0.731059, 2)])
    assert [entry[2] for entry in actual] == [1, 2]


def test_w2_8_plausible_wrong_result_detected_against_golden_constants():
    """W2.8: plausible-but-wrong scored/ranked/selected results are detected."""
    output, provider = run_golden_output()
    actual = recommendation_tuples(output)

    # W2.8 model identity verification: exact expected dict.
    assert provider.get_model_identity() == GOLDEN_IDENTITY_W2_1

    # The real fixture output matches the hand-recorded golden constants.
    assert_matches_golden(actual, GOLDEN_RECOMMENDATIONS_W2_1)

    plausible_wrong_results = [
        # Same shapes/ranges/ordering; top score off by one unit in the
        # sixth decimal.
        [(0, 0.880796, 1), (1, 0.5, 2), (2, 0.268941, 3)],
        # Wrong top-K selection: candidate 1 selected first with different
        # but structurally valid in-range 6-decimal scores.
        [(1, 0.9, 1), (0, 0.880797, 2), (2, 0.268941, 3)],
    ]

    for wrong in plausible_wrong_results:
        # Plausible: passes every shape/range/ordering/precision/rank rule.
        assert is_plausible_recommendations(wrong)
        # ...and yet it differs from the deterministic fixture output...
        assert wrong != actual
        # ...so the exact golden comparison detects/rejects it.
        with pytest.raises(AssertionError):
            assert_matches_golden(wrong, GOLDEN_RECOMMENDATIONS_W2_1)
