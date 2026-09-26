"""Rank clips action: envelope, provenance, provider boundary and privacy.

The fixtures are hand-built from spec.md ("Strict worker protocol") rather
than derived from the production profile helpers, so a defect in the
production constants cannot validate its own request.

Coverage follows test-plan.md, "Worker unit, schema, action and CLI
coverage": item 2 (provider selection with no environment fallback), item 4
(K>0 and usable text, exact output, whole-result rejection) and item 6 (no
network, storage, database or heavyweight import on the fake path).
"""

from __future__ import annotations

import builtins
import hashlib
import json
import math
import socket
import subprocess
import urllib.request

import pytest

from aiclip_worker.actions.rank_clips import error, rank_clips
from aiclip_worker.ranking import (
    CrossEncoderRankingProvider,
    FakeRankingProvider,
    RankingOutput,
    Recommendation,
)

PROTOTYPE_QUERY = (
    "Engaging, self-contained short-form video clip highlight with a clear "
    "narrative or punchline."
)

FAKE_CONFIGURATION = {
    "provider": "fake",
    "algorithm": "transcript_semantic_recommendation",
    "algorithm_version": "1.0.0",
    "projection_version": "1.0.0",
    "query_version": "1.0.0",
    "prototype_query": PROTOTYPE_QUERY,
    "model_id": "fake-ranking-v1",
    "model_revision": "1.0.0",
    "runtime_profile": "fake_v1",
    "normalization": "fixture_units_6",
    "max_tokens": 0,
    "batch_size": 0,
    "truncation": "none",
}

REAL_CONFIGURATION = {
    "provider": "cross_encoder",
    "algorithm": "transcript_semantic_recommendation",
    "algorithm_version": "1.0.0",
    "projection_version": "1.0.0",
    "query_version": "1.0.0",
    "prototype_query": PROTOTYPE_QUERY,
    "model_id": "cross-encoder/ms-marco-MiniLM-L6-v2",
    "model_revision": "233902d25c440f23af6f7d6e94d2946bac0bee0a",
    "runtime_profile": "minilm_cpu_v1",
    "normalization": "stable_sigmoid_half_up_6",
    "max_tokens": 512,
    "batch_size": 8,
    "truncation": "right_longest_first_512",
}

# Every module root the fake path must never import.
HEAVYWEIGHT_ROOTS = frozenset(
    {
        "torch",
        "sentence_transformers",
        "transformers",
        "numpy",
        "scipy",
        "sklearn",
        "PIL",
        "psycopg2",
        "sqlalchemy",
        "boto3",
        "minio",
        "requests",
        "httpx",
    }
)


class StubModel:
    """Lightweight inference double: one zero logit per pair."""

    def predict(self, pairs):
        return [0.0] * len(pairs)


def stub_loader(self):
    """Stand in for the real loader without importing any ML runtime."""
    if self._model is None:
        self._model = StubModel()


def configuration(provider: str = "fake") -> dict:
    source = FAKE_CONFIGURATION if provider == "fake" else REAL_CONFIGURATION
    return {key: value for key, value in source.items()}


def candidate(index: int, start_ms: int, end_ms: int, m4_rank: int, text: str,
              m4_score: float = 0.5) -> dict:
    return {
        "index": index,
        "start_ms": start_ms,
        "end_ms": end_ms,
        "m4_rank": m4_rank,
        "m4_score": m4_score,
        "transcript_text": text,
    }


def valid_rank_clips_contract(provider: str = "fake") -> dict:
    """K=2 with usable text on both candidates."""
    return {
        "version": "1.0.0",
        "action": "rank_clips",
        "media": {"duration_ms": 40000},
        "candidates": [
            candidate(0, 0, 10000, 1, "engaging content", 0.91),
            candidate(1, 10000, 20000, 2, "second passage", 0.72),
        ],
        "configuration": configuration(provider),
    }


def recovery_two_text_contract(provider: str = "cross_encoder") -> dict:
    """Two usable candidates on the selected profile."""
    return valid_rank_clips_contract(provider=provider)


def request_digest(contract: dict) -> str:
    """Digest of a canonical serialization, used as the transport echo."""
    payload = json.dumps(contract, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def call(contract: dict, digest: str | None = None) -> dict:
    """Invoke the action with an explicit request digest."""
    return rank_clips(contract, digest if digest is not None else request_digest(contract))


@pytest.fixture(autouse=True)
def stub_real_model_loader(monkeypatch):
    """Keep this suite heavyweight-free: these tests assert transport,
    serialization and provenance, never real-model quality.

    Tests that assert the real loader boundary replace this stub with their
    own loader spy or raising loader, so the boundary stays observable.
    Mandatory CI must never import torch/sentence-transformers or read a
    model cache.
    """
    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", stub_loader)


# ---------------------------------------------------------------------------
# Contract gating before provider construction
# ---------------------------------------------------------------------------


def test_analyze_clips_action_rejects_invalid_contract():
    """Malformed candidates must return the exact invalid_contract envelope."""
    contract = valid_rank_clips_contract()
    contract["candidates"] = "not a list"
    result = call(contract)
    assert result == error("invalid_contract")
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"
    assert result["error"] == "Invalid ranking contract"
    assert result["stderr"] == ""


def test_rank_clips_action_missing_required_fields():
    """A request missing required fields must return invalid_contract."""
    contract = {"version": "1.0.0", "action": "rank_clips"}
    assert call(contract) == error("invalid_contract")


def test_rank_clips_action_empty_candidates():
    """K=0 is a local Laravel outcome; the worker refuses it."""
    contract = valid_rank_clips_contract()
    contract["candidates"] = []
    assert call(contract) == error("invalid_contract")


def test_rank_clips_action_rejects_candidates_without_usable_text():
    """A direct worker request needs at least one nonempty candidate text."""
    contract = valid_rank_clips_contract()
    for item in contract["candidates"]:
        item["transcript_text"] = ""
    assert call(contract) == error("invalid_contract")


def test_invalid_contract_never_constructs_a_provider(monkeypatch):
    """No provider may be built for an input failure, so no provider switch
    can occur after the request is already known to be invalid."""

    def forbidden(selector):
        raise AssertionError("provider construction is forbidden before validation")

    monkeypatch.setattr("aiclip_worker.actions.rank_clips._select_provider", forbidden)

    contract = valid_rank_clips_contract()
    contract["candidates"] = "not a list"
    assert call(contract) == error("invalid_contract")

    contract = valid_rank_clips_contract()
    contract["configuration"]["provider"] = "auto"
    assert call(contract) == error("invalid_contract")


def test_request_digest_must_be_lowercase_64_hex():
    """The echoed digest must be the lowercase SHA256 of the request bytes."""
    contract = valid_rank_clips_contract()
    for bad in ("", "not-a-digest", "A" * 64, "0" * 63, "0" * 65, None):
        assert rank_clips(contract, bad) == error("invalid_contract"), bad


def test_request_sha256_is_echoed_verbatim():
    """The result binds to the exact digest the caller computed."""
    contract = valid_rank_clips_contract()
    digest = "ab" * 32
    result = call(contract, digest)
    assert result["status"] == "success"
    assert result["ranking"]["request_sha256"] == digest


# ---------------------------------------------------------------------------
# Success envelope and provenance
# ---------------------------------------------------------------------------


def test_rank_clips_action_returns_success_for_valid_input():
    """Valid input must return the success envelope with the pinned labels."""
    result = call(valid_rank_clips_contract(provider="cross_encoder"))
    assert result["status"] == "success"
    assert set(result.keys()) == {"status", "ranking"}
    ranking = result["ranking"]
    assert set(ranking.keys()) == {
        "algorithm",
        "algorithm_version",
        "parameters",
        "request_sha256",
        "recommendations",
    }
    assert ranking["algorithm"] == "transcript_semantic_recommendation"
    assert ranking["algorithm_version"] == "1.0.0"
    assert len(ranking["recommendations"]) == 2


def test_rank_clips_action_returns_ranking_with_provenance():
    """Parameters carry the full selected profile and truthful flags."""
    contract = valid_rank_clips_contract(provider="cross_encoder")
    result = call(contract)
    params = result["ranking"]["parameters"]

    expected_keys = (
        set(contract["configuration"]) - {"algorithm", "algorithm_version"}
    ) | {"provider_name", "inference_performed", "transcript_used"}
    assert set(params.keys()) == expected_keys, sorted(params)

    assert params["provider"] == "cross_encoder"
    assert params["provider_name"] == "cross_encoder_ranking_provider"
    assert params["model_id"] == "cross-encoder/ms-marco-MiniLM-L6-v2"
    assert params["model_revision"] == "233902d25c440f23af6f7d6e94d2946bac0bee0a"
    assert params["runtime_profile"] == "minilm_cpu_v1"
    assert params["prototype_query"] == PROTOTYPE_QUERY
    assert params["normalization"] == "stable_sigmoid_half_up_6"
    assert params["max_tokens"] == 512
    assert params["batch_size"] == 8
    assert params["truncation"] == "right_longest_first_512"
    assert params["inference_performed"] is True
    assert params["transcript_used"] is True
    assert "algorithm" not in params and "algorithm_version" not in params


def test_rank_clips_action_recommendations_have_exact_keys():
    """Every recommendation carries exactly the eight specified fields."""
    contract = valid_rank_clips_contract(provider="cross_encoder")
    contract["candidates"].append(candidate(2, 20000, 30000, 3, ""))
    contract["candidates"][1]["transcript_text"] = ""
    contract["media"]["duration_ms"] = 40000
    result = call(contract)
    recommendations = result["ranking"]["recommendations"]

    assert len(recommendations) == 3
    for entry in recommendations:
        assert set(entry.keys()) == {
            "m4_candidate_index",
            "start_ms",
            "end_ms",
            "m4_rank",
            "m4_score",
            "semantic_score",
            "semantic_rank",
            "reason",
        }


def test_rank_clips_action_recommendations_sorted_by_semantic_score():
    """Recommendations must be sorted by descending semantic_score."""
    result = call(valid_rank_clips_contract(provider="cross_encoder"))
    scores = [entry["semantic_score"] for entry in result["ranking"]["recommendations"]]
    assert scores == sorted(scores, reverse=True)


def test_rank_clips_action_recommendations_have_valid_m4_candidate_index():
    """Each recommendation must reference a valid m4_candidate_index."""
    result = call(valid_rank_clips_contract(provider="cross_encoder"))
    indexes = [entry["m4_candidate_index"] for entry in result["ranking"]["recommendations"]]
    assert sorted(indexes) == [0, 1]


def test_rank_clips_action_recommendations_have_contiguous_semantic_rank():
    """Semantic ranks must be unique and contiguous 1..N for scored entries."""
    result = call(valid_rank_clips_contract(provider="cross_encoder"))
    ranks = [
        entry["semantic_rank"]
        for entry in result["ranking"]["recommendations"]
        if entry["semantic_rank"] is not None
    ]
    assert ranks == list(range(1, len(ranks) + 1))


def test_rank_clips_action_semantic_score_finite_bounds():
    """All semantic_scores must be finite and inside [0,1]."""
    result = call(valid_rank_clips_contract(provider="cross_encoder"))
    for entry in result["ranking"]["recommendations"]:
        score = entry["semantic_score"]
        assert isinstance(score, (int, float))
        assert isinstance(score, bool) is False
        assert 0 <= score <= 1
        assert math.isfinite(score)
        assert score == round(score, 6)


def test_rank_clips_action_tie_break_by_m4_rank():
    """Equal scores are ordered by ascending M4 rank."""
    result = call(valid_rank_clips_contract(provider="fake"))
    recommendations = result["ranking"]["recommendations"]
    assert recommendations[0]["m4_candidate_index"] == 0
    assert recommendations[1]["m4_candidate_index"] == 1
    scores = [entry["semantic_score"] for entry in recommendations]
    assert scores == [1.0, 0.9]


def test_all_equal_scores_still_return_every_candidate():
    """All-equal scores still require K entries, never empty output."""
    contract = valid_rank_clips_contract(provider="cross_encoder")
    result = call(contract)
    recommendations = result["ranking"]["recommendations"]
    assert len(recommendations) == 2
    assert [entry["semantic_score"] for entry in recommendations] == [0.5, 0.5]
    assert [entry["m4_candidate_index"] for entry in recommendations] == [0, 1]
    assert [entry["semantic_rank"] for entry in recommendations] == [1, 2]


def test_success_output_carries_no_raw_text():
    """No transcript text or free-form prose may leave the worker.

    The fixed query is allowlisted configuration, so it may appear only as
    the `prototype_query` parameter.
    """
    contract = valid_rank_clips_contract(provider="fake")
    contract["candidates"][0]["transcript_text"] = "PRIVATE_TRANSCRIPT_SENTINEL"
    contract["candidates"][1]["transcript_text"] = "SECOND_PRIVATE_SENTINEL"
    result = call(contract)
    serialized = json.dumps(result, allow_nan=False)
    assert "PRIVATE_TRANSCRIPT_SENTINEL" not in serialized
    assert "SECOND_PRIVATE_SENTINEL" not in serialized
    parameters = result["ranking"]["parameters"]
    assert parameters["prototype_query"] == PROTOTYPE_QUERY
    assert "PRIVATE" not in serialized


def test_k_1000_success_output_stays_under_one_mib():
    """The response bound is 1 MiB even at the candidate cap."""
    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = 1000 * 1000
    contract["candidates"] = [
        candidate(position, position * 1000, (position + 1) * 1000,
                  position + 1, f"candidate {position}")
        for position in range(1000)
    ]
    result = call(contract)
    assert result["status"] == "success"
    recommendations = result["ranking"]["recommendations"]
    assert len(recommendations) == 1000
    assert [entry["semantic_rank"] for entry in recommendations] == list(range(1, 1001))
    payload = json.dumps(result, allow_nan=False, separators=(",", ":"))
    assert len(payload.encode("utf-8")) < 1024 * 1024


# ---------------------------------------------------------------------------
# Mixed input (item 4)
# ---------------------------------------------------------------------------


def test_mixed_input_scores_only_nonempty_candidates(monkeypatch):
    """Only nonempty candidates reach the provider; every candidate still
    gets exactly one result entry."""
    calls = []
    original_rank = FakeRankingProvider.rank

    def recording_rank(self, input):
        calls.append([item["index"] for item in input.candidates])
        return original_rank(self, input)

    monkeypatch.setattr(FakeRankingProvider, "rank", recording_rank)

    contract = valid_rank_clips_contract()
    contract["media"]["duration_ms"] = 40000
    contract["candidates"] = [
        candidate(0, 0, 10000, 1, "first usable"),
        candidate(1, 10000, 20000, 2, ""),
        candidate(2, 20000, 30000, 3, "third usable"),
    ]
    result = call(contract)

    assert calls == [[0, 2]], "the provider must receive only usable candidates"

    recommendations = result["ranking"]["recommendations"]
    assert len(recommendations) == 3

    scored = recommendations[:2]
    unscored = recommendations[2]

    assert [entry["m4_candidate_index"] for entry in scored] == [0, 2]
    assert [entry["semantic_rank"] for entry in scored] == [1, 2]
    for entry in scored:
        assert entry["semantic_score"] is not None
        assert entry["reason"] is None

    assert unscored["m4_candidate_index"] == 1
    assert unscored["semantic_score"] is None
    assert unscored["semantic_rank"] is None
    assert unscored["reason"] == "no_candidate_text"

    assert sorted(entry["m4_candidate_index"] for entry in recommendations) == [0, 1, 2]
    assert result["ranking"]["parameters"]["transcript_used"] is True


def test_mixed_input_keeps_authoritative_m4_values():
    """References, boundaries and M4 score/rank echo the request exactly."""
    contract = valid_rank_clips_contract()
    contract["candidates"] = [
        candidate(0, 0, 10000, 1, "usable", 0.91),
        candidate(1, 10000, 20000, 2, "", 0.72),
    ]
    result = call(contract)
    by_index = {
        entry["m4_candidate_index"]: entry
        for entry in result["ranking"]["recommendations"]
    }
    for source in contract["candidates"]:
        entry = by_index[source["index"]]
        assert entry["start_ms"] == source["start_ms"]
        assert entry["end_ms"] == source["end_ms"]
        assert entry["m4_rank"] == source["m4_rank"]
        assert entry["m4_score"] == source["m4_score"]


# ---------------------------------------------------------------------------
# Provider selection (item 2)
# ---------------------------------------------------------------------------


def test_explicit_provider_selection_selects_the_named_profile(monkeypatch):
    """The trusted request selection selects the executed provider."""

    def forbidden_loader(self):
        raise AssertionError("fake selection must never load the real runtime")

    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", forbidden_loader)

    result = call(valid_rank_clips_contract(provider="fake"))
    assert result["status"] == "success"
    params = result["ranking"]["parameters"]
    assert params["provider"] == "fake"
    assert params["provider_name"] == "fake_ranking_provider"
    assert params["model_id"] == "fake-ranking-v1"
    assert params["inference_performed"] is False
    assert "cross_encoder" not in json.dumps(result)
    assert "cross-encoder" not in json.dumps(result)


def test_real_profile_reports_real_identity(monkeypatch):
    """The cross_encoder selection reports the pinned real identity."""
    loader_calls = []

    def recording_loader(self):
        loader_calls.append(True)
        stub_loader(self)

    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", recording_loader)

    result = call(valid_rank_clips_contract(provider="cross_encoder"))
    assert result["status"] == "success"
    assert loader_calls == [True], "the real profile must reach its loader"
    params = result["ranking"]["parameters"]
    assert params["provider"] == "cross_encoder"
    assert params["provider_name"] == "cross_encoder_ranking_provider"
    assert params["model_id"] == "cross-encoder/ms-marco-MiniLM-L6-v2"
    assert params["inference_performed"] is True


def test_environment_never_selects_the_profile(monkeypatch):
    """No environment variable may override or replace the selection."""
    for value in ("1", "0", "fake", "cross_encoder", "auto", "sometimes"):
        monkeypatch.setenv("FAKE_RANKING_PROVIDER", value)
        monkeypatch.setenv("AICLIP_RANKING_PROVIDER", value)
        monkeypatch.setenv("AICLIP_RANKING_PROFILE", value)

        def forbidden_loader(self):
            raise AssertionError("environment must never reach the real loader")

        monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", forbidden_loader)

        fake_result = call(valid_rank_clips_contract(provider="fake"))
        assert fake_result["status"] == "success", value
        assert fake_result["ranking"]["parameters"]["provider"] == "fake", value

    # The trusted selection still picks the real profile regardless of env.
    monkeypatch.setenv("FAKE_RANKING_PROVIDER", "1")
    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", stub_loader)
    result = call(valid_rank_clips_contract(provider="cross_encoder"))
    assert result["status"] == "success"
    assert result["ranking"]["parameters"]["provider"] == "cross_encoder"
    assert result["ranking"]["parameters"]["provider_name"] == "cross_encoder_ranking_provider"
    assert result["ranking"]["parameters"]["inference_performed"] is True


def test_unknown_provider_selection_fails_closed(monkeypatch):
    """An unknown or unset selection never guesses a profile."""
    monkeypatch.setenv("FAKE_RANKING_PROVIDER", "sometimes")

    contract = valid_rank_clips_contract()
    contract["configuration"]["provider"] = "auto"
    assert call(contract) == error("invalid_contract")

    contract = valid_rank_clips_contract()
    del contract["configuration"]["provider"]
    assert call(contract) == error("invalid_contract")

    contract = valid_rank_clips_contract()
    contract["configuration"]["provider"] = ""
    assert call(contract) == error("invalid_contract")


# ---------------------------------------------------------------------------
# Fake path isolation (item 6)
# ---------------------------------------------------------------------------


def test_fake_path_performs_no_heavyweight_import_or_side_effect(monkeypatch):
    """The deterministic fake path must not touch ML runtimes, storage,
    databases, telemetry, FFmpeg or the network."""
    real_import = builtins.__import__

    def guarded_import(name, *args, **kwargs):
        root = name.split(".")[0]
        if root in HEAVYWEIGHT_ROOTS:
            raise AssertionError(f"forbidden import attempted: {name}")
        return real_import(name, *args, **kwargs)

    def forbidden(*_args, **_kwargs):
        raise AssertionError("the fake path must not use this entry point")

    monkeypatch.setattr(builtins, "__import__", guarded_import)
    monkeypatch.setattr(socket, "socket", forbidden)
    monkeypatch.setattr(socket, "create_connection", forbidden)
    monkeypatch.setattr(urllib.request, "urlopen", forbidden)
    monkeypatch.setattr(subprocess, "Popen", forbidden)
    monkeypatch.setattr(subprocess, "run", forbidden)
    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", forbidden)

    result = call(valid_rank_clips_contract(provider="fake"))

    monkeypatch.setattr(builtins, "__import__", real_import)

    assert result["status"] == "success"
    assert result["ranking"]["parameters"]["inference_performed"] is False
    assert len(result["ranking"]["recommendations"]) == 2


# ---------------------------------------------------------------------------
# Whole-result rejection of malformed provider output (item 4)
# ---------------------------------------------------------------------------


def fake_output(entries, provider_name="fake_ranking_provider",
                model_id="fake-ranking-v1", model_revision="1.0.0"):
    return RankingOutput(
        recommendations=[
            Recommendation(
                m4_candidate_index=index,
                semantic_score=score,
                combined_rank=rank,
            )
            for index, score, rank in entries
        ],
        model_id=model_id,
        model_revision=model_revision,
        provider_name=provider_name,
        transcript_used=True,
    )


def test_provider_identity_mismatch_rejects_the_whole_result(monkeypatch):
    """A result carrying another profile's identity must never be accepted."""
    contract = valid_rank_clips_contract(provider="fake")

    def forged(self, input):
        return fake_output(
            [(0, 1.0, 1), (1, 0.9, 2)],
            provider_name="cross_encoder_ranking_provider",
            model_id="cross-encoder/ms-marco-MiniLM-L6-v2",
            model_revision="233902d25c440f23af6f7d6e94d2946bac0bee0a",
        )

    monkeypatch.setattr(FakeRankingProvider, "rank", forged)
    assert call(contract) == error("ranking_failed")


def test_provider_result_of_unexpected_type_rejects(monkeypatch):
    """Wrong provider result type rejects the entire response."""
    contract = valid_rank_clips_contract(provider="fake")
    monkeypatch.setattr(FakeRankingProvider, "rank", lambda self, input: {"status": "ok"})
    assert call(contract) == error("ranking_failed")

    monkeypatch.setattr(FakeRankingProvider, "rank", lambda self, input: None)
    assert call(contract) == error("ranking_failed")


def test_provider_nonfinite_score_rejects_the_whole_result(monkeypatch):
    """NaN, Infinity and booleans never become semantic scores."""
    contract = valid_rank_clips_contract(provider="fake")
    for value in (math.nan, math.inf, True):
        monkeypatch.setattr(
            FakeRankingProvider,
            "rank",
            lambda self, input, value=value: fake_output(
                [(0, value, 1), (1, 0.9, 2)]
            ),
        )
        assert call(contract) == error("ranking_failed"), value


def test_provider_out_of_range_score_rejects(monkeypatch):
    """Scores outside [0,1] reject the entire result."""
    contract = valid_rank_clips_contract(provider="fake")
    for value in (-0.1, 1.1, 2):
        monkeypatch.setattr(
            FakeRankingProvider,
            "rank",
            lambda self, input, value=value: fake_output(
                [(0, value, 1), (1, 0.9, 2)]
            ),
        )
        assert call(contract) == error("ranking_failed"), value


def test_provider_missing_or_extra_reference_rejects(monkeypatch):
    """Missing, extra, duplicate and unknown references reject the whole
    result; no partial payload is ever emitted."""
    contract = valid_rank_clips_contract(provider="fake")

    mutations = {
        "missing": [(0, 1.0, 1)],
        "extra": [(0, 1.0, 1), (1, 0.9, 2), (2, 0.8, 3)],
        "duplicate": [(0, 1.0, 1), (0, 0.9, 2)],
        "unknown": [(0, 1.0, 1), (7, 0.9, 2)],
        "gap": [(0, 1.0, 1), (2, 0.9, 2)],
    }
    for name, entries in mutations.items():
        monkeypatch.setattr(
            FakeRankingProvider, "rank",
            lambda self, input, entries=entries: fake_output(entries),
        )
        assert call(contract) == error("ranking_failed"), name


def test_provider_noncontiguous_semantic_rank_rejects(monkeypatch):
    """Semantic ranks must be contiguous from 1 in the emitted order."""
    contract = valid_rank_clips_contract(provider="fake")
    monkeypatch.setattr(
        FakeRankingProvider, "rank",
        lambda self, input: fake_output([(0, 1.0, 1), (1, 0.9, 5)]),
    )
    assert call(contract) == error("ranking_failed")


def test_provider_unordered_output_rejects(monkeypatch):
    """Output must follow descending quantized units, then ascending M4
    rank; plausible-but-wrong ordering is rejected as a whole."""
    contract = valid_rank_clips_contract(provider="fake")

    # Ascending units: the higher score arrives second.
    monkeypatch.setattr(
        FakeRankingProvider, "rank",
        lambda self, input: fake_output([(0, 0.4, 1), (1, 0.9, 2)]),
    )
    assert call(contract) == error("ranking_failed")

    # Equal units must be ordered by ascending M4 rank. The M4 ranks are
    # inverted here, so the same scores in candidate order are wrong.
    tied = valid_rank_clips_contract(provider="fake")
    tied["candidates"][0]["m4_rank"] = 2
    tied["candidates"][1]["m4_rank"] = 1

    def wrong_order(self, request):
        del request
        return fake_output([(0, 0.5, 1), (1, 0.5, 2)])

    monkeypatch.setattr(FakeRankingProvider, "rank", wrong_order)
    assert call(tied) == error("ranking_failed")

    def right_order(self, request):
        del request
        return fake_output([(1, 0.5, 1), (0, 0.5, 2)])

    monkeypatch.setattr(FakeRankingProvider, "rank", right_order)
    result = call(tied)
    assert result["status"] == "success"
    assert [entry["m4_candidate_index"]
            for entry in result["ranking"]["recommendations"]] == [1, 0]
    assert [entry["semantic_rank"]
            for entry in result["ranking"]["recommendations"]] == [1, 2]


# ---------------------------------------------------------------------------
# The six accepted corrective RED cases
# ---------------------------------------------------------------------------


def test_runtime_unavailable_never_returns_semantic_success(monkeypatch, capsys, caplog):
    reached = []
    sentinel = "synthetic-private-runtime-sentinel"

    def unavailable(self):
        reached.append(True)
        raise ImportError(sentinel)

    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", unavailable)
    result = call(recovery_two_text_contract(provider="cross_encoder"))
    assert reached == [True], "The real provider loader must be reached"
    captured = capsys.readouterr()
    assert sentinel not in captured.out + captured.err + caplog.text + str(result)
    assert result == error("ranking_failed"), (
        "Runtime absence must reject the whole result, never return semantic success"
    )


def test_fake_action_preserves_fake_provenance(monkeypatch):
    calls = []
    original = FakeRankingProvider.rank

    def recording_fake(self, input):
        calls.append(len(input.candidates))
        return original(self, input)

    def forbidden_loader(self):
        raise AssertionError("Explicit fake selection must never load the real runtime")

    monkeypatch.setattr(FakeRankingProvider, "rank", recording_fake)
    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", forbidden_loader)

    contract = recovery_two_text_contract(provider="fake")
    result = call(contract)

    assert calls == [2], "The actual fake provider must execute"
    assert result["status"] == "success"
    assert [round(entry["semantic_score"] * 1000000)
            for entry in result["ranking"]["recommendations"]] == [1000000, 900000]
    params = result["ranking"]["parameters"]
    keys = ("provider", "provider_name", "model_id", "model_revision",
            "normalization", "inference_performed")
    assert {key: params.get(key) for key in keys} == {
        "provider": "fake", "provider_name": "fake_ranking_provider",
        "model_id": "fake-ranking-v1", "model_revision": "1.0.0",
        "normalization": "fixture_units_6", "inference_performed": False,
    }
    assert "cross_encoder" not in str(result)
    assert "cross-encoder" not in str(result)


@pytest.mark.parametrize("logits", [[0.0], [0.0, 1.0, 2.0]], ids=["short", "long"])
def test_wrong_logit_count_rejects_entire_result(monkeypatch, logits):
    reached = []

    class Model:
        def predict(self, pairs):
            reached.append(("predict", len(pairs)))
            return logits

    def loader(self):
        reached.append(("loader", 1))
        self._model = Model()

    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", loader)
    result = call(recovery_two_text_contract(provider="cross_encoder"))
    assert reached == [("loader", 1), ("predict", 2)]
    assert result == error("ranking_failed"), (
        "Wrong inference cardinality must reject the entire result"
    )
