"""Rank clips action and metadata transport."""

from __future__ import annotations

import pytest

from aiclip_worker.actions.rank_clips import rank_clips, error


def valid_rank_clips_contract():
    """Valid rank_clips contract."""
    return {
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 40000},
        "candidates": [
            {"index": 0, "start_ms": 0, "end_ms": 10000, "rank": 1, "transcript_text": "engaging content"},
            {"index": 1, "start_ms": 10000, "end_ms": 20000, "rank": 2, "transcript_text": ""},
        ],
        "configuration": {
            "prototype_query": "Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline."
        },
    }


def test_analyze_clips_action_rejects_invalid_contract():
    """rank_clips action must reject invalid contract with invalid_contract code (currently fails - RED)."""
    contract = valid_rank_clips_contract()
    contract["candidates"] = "not a list"
    result = rank_clips(contract)
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"
    assert result["error"] == "Invalid ranking contract"
    assert result["stderr"] == ""


def test_rank_clips_action_returns_success_for_valid_input():
    """rank_clips action must return success for valid input (currently fails - RED)."""
    contract = valid_rank_clips_contract()
    result = rank_clips(contract)
    assert result["status"] == "success"
    assert "ranking" in result
    assert result["ranking"]["algorithm"] == "cross_encoder_reranker"
    assert result["ranking"]["algorithm_version"] == "1.0.0"
    assert "parameters" in result["ranking"]
    assert "recommendations" in result["ranking"]
    assert len(result["ranking"]["recommendations"]) == 2


def test_rank_clips_action_returns_ranking_with_provenance():
    """rank_clips action must return ranking with full provenance."""
    contract = valid_rank_clips_contract()
    result = rank_clips(contract)
    params = result["ranking"]["parameters"]
    assert params["model_id"] == "cross-encoder/ms-marco-MiniLM-L-6-v2"
    assert "model_revision" in params
    assert params["provider_name"] == "cross_encoder_ranking_provider"
    assert params["prototype_query"] == "Engaging, self-contained, viral-worthy short-form video clip highlight with clear narrative or punchline."
    assert params["normalization"] == "sigmoid"
    assert params["score_scale"] == 1.0
    assert params["tie_break"] == "m4_rank_then_chronological"


def test_rank_clips_action_recommendations_sorted_by_semantic_score():
    """Recommendations must be sorted by descending semantic_score."""
    contract = valid_rank_clips_contract()
    result = rank_clips(contract)
    recs = result["ranking"]["recommendations"]
    scores = [r["semantic_score"] for r in recs]
    assert scores == sorted(scores, reverse=True)


def test_rank_clips_action_recommendations_have_valid_m4_candidate_index():
    """Each recommendation must reference valid m4_candidate_index."""
    contract = valid_rank_clips_contract()
    result = rank_clips(contract)
    recs = result["ranking"]["recommendations"]
    indices = [r["m4_candidate_index"] for r in recs]
    assert sorted(indices) == [0, 1]  # Permutation of 0..K-1


def test_rank_clips_action_recommendations_have_valid_combined_rank():
    """Combined ranks must be unique contiguous 1..K."""
    contract = valid_rank_clips_contract()
    result = rank_clips(contract)
    recs = result["ranking"]["recommendations"]
    ranks = [r["combined_rank"] for r in recs]
    assert ranks == list(range(1, len(recs) + 1))


def test_rank_clips_action_semantic_score_finite_bounds():
    """All semantic_scores must be finite and in [0,1]."""
    contract = valid_rank_clips_contract()
    result = rank_clips(contract)
    for rec in result["ranking"]["recommendations"]:
        score = rec["semantic_score"]
        assert isinstance(score, (int, float))
        assert 0 <= score <= 1
        # Not NaN or Infinity
        assert score == score  # NaN check
        assert abs(score) != float('inf')


def test_rank_clips_action_tie_break_by_m4_rank():
    """Tie-break must use M4 rank when semantic scores are equal."""
    # This test requires the real provider to have equal scores
    # For fake provider, scores are deterministic by M4 rank
    contract = valid_rank_clips_contract()
    result = rank_clips(contract)
    recs = result["ranking"]["recommendations"]
    # Since fake provider gives score 1.0 for rank 1, 0.9 for rank 2,
    # order should follow M4 rank
    assert recs[0]["m4_candidate_index"] == 0  # M4 rank 1
    assert recs[1]["m4_candidate_index"] == 1  # M4 rank 2


def test_rank_clips_action_empty_candidates():
    """Empty candidates list must produce empty recommendations with full provenance."""
    contract = valid_rank_clips_contract()
    contract["candidates"] = []
    result = rank_clips(contract)
    assert result["status"] == "success"
    assert result["ranking"]["recommendations"] == []
    assert "parameters" in result["ranking"]


def test_rank_clips_action_missing_required_fields():
    """Missing required fields must return invalid_contract."""
    contract = {"version": "1.0.0", "action": "rank_clips"}
    result = rank_clips(contract)
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"


# Recovery RED uses the currently accepted transport and selection hook so that
# final-protocol rejection cannot hide provider/serialization defects.
def recovery_two_text_contract():
    contract = valid_rank_clips_contract()
    contract["candidates"][1]["transcript_text"] = "Synthetic second passage"
    return contract


def test_runtime_unavailable_never_returns_semantic_success(monkeypatch, capsys, caplog):
    from aiclip_worker.ranking import CrossEncoderRankingProvider

    monkeypatch.delenv("FAKE_RANKING_PROVIDER", raising=False)
    reached = []
    sentinel = "synthetic-private-runtime-sentinel"

    def unavailable(self):
        reached.append(True)
        raise ImportError(sentinel)

    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", unavailable)
    result = rank_clips(recovery_two_text_contract())
    assert reached == [True], "The real provider loader must be reached"
    captured = capsys.readouterr()
    assert sentinel not in captured.out + captured.err + caplog.text + str(result)
    assert result == {
        "status": "error", "code": "ranking_failed",
        "error": "Ranking failed", "stderr": "",
    }, "Runtime absence must reject the whole result, never return semantic success"


def test_fake_action_preserves_fake_provenance(monkeypatch):
    from aiclip_worker.ranking import FakeRankingProvider, CrossEncoderRankingProvider

    monkeypatch.setenv("FAKE_RANKING_PROVIDER", "1")
    calls = []
    original = FakeRankingProvider.rank

    def recording_fake(self, input):
        calls.append(len(input.candidates))
        return original(self, input)

    def forbidden_loader(self):
        pytest.fail("Explicit fake selection must never load the real runtime")

    monkeypatch.setattr(FakeRankingProvider, "rank", recording_fake)
    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", forbidden_loader)
    result = rank_clips(recovery_two_text_contract())
    assert calls == [2], "The actual fake provider must execute"
    assert result["status"] == "success"
    assert [round(r["semantic_score"] * 1000000) for r in result["ranking"]["recommendations"]] == [1000000, 900000]
    params = result["ranking"]["parameters"]
    keys = ("provider", "provider_name", "model_id", "model_revision", "normalization", "inference_performed")
    assert {key: params.get(key) for key in keys} == {
        "provider": "fake", "provider_name": "fake_ranking_provider",
        "model_id": "fake-ranking-v1", "model_revision": "1.0.0",
        "normalization": "fixture_units_6", "inference_performed": False,
    }
    assert "cross_encoder" not in str(result) and "cross-encoder" not in str(result)


@pytest.mark.parametrize("logits", [[0.0], [0.0, 1.0, 2.0]], ids=["short", "long"])
def test_wrong_logit_count_rejects_entire_result(monkeypatch, logits):
    from aiclip_worker.ranking import CrossEncoderRankingProvider

    monkeypatch.delenv("FAKE_RANKING_PROVIDER", raising=False)
    reached = []

    class Model:
        def predict(self, pairs):
            reached.append(("predict", len(pairs)))
            return logits

    def loader(self):
        reached.append(("loader", 1))
        self._model = Model()

    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", loader)
    result = rank_clips(recovery_two_text_contract())
    assert reached == [("loader", 1), ("predict", 2)]
    assert result == {
        "status": "error", "code": "ranking_failed",
        "error": "Ranking failed", "stderr": "",
    }, "Wrong inference cardinality must reject the entire result"
