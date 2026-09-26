"""Ranking provider interface and implementations."""

from __future__ import annotations

import hashlib
import json
import logging
import math
import os
import sys
from pathlib import Path
from types import ModuleType, SimpleNamespace

import pytest

# These imports will fail initially - this is the expected RED
from aiclip_worker.ranking import (
    MANIFEST_FILENAME,
    MANIFEST_SCHEMA,
    MODEL_ID,
    MODEL_REVISION,
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
    assert output.provider_name == "fake_ranking_provider"
    identity = provider.get_model_identity()
    assert identity["model_id"] == "fake-ranking-v1"
    assert identity["provider_name"] == "fake_ranking_provider"


def test_cross_encoder_ranking_provider_loads_model():
    """CrossEncoderRankingProvider must load the correct model (currently fails - RED)."""
    provider = CrossEncoderRankingProvider()
    identity = provider.get_model_identity()

    assert identity["model_id"] == "cross-encoder/ms-marco-MiniLM-L6-v2"
    assert identity["model_revision"] == "233902d25c440f23af6f7d6e94d2946bac0bee0a"
    assert identity["provider_name"] == "cross_encoder_ranking_provider"


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


def test_cross_encoder_provider_scores_empty_text(monkeypatch):
    """CrossEncoderRankingProvider must score empty transcript text honestly."""
    # Lightweight loader stub: this assertion is about honest empty-text
    # scoring, not about importing the heavyweight runtime in mandatory CI.
    class StubModel:
        def predict(self, pairs):
            return [0.0] * len(pairs)

    def loader(self):
        self._model = StubModel()

    monkeypatch.setattr(CrossEncoderRankingProvider, "_load_model", loader)
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

# Exact expected model identity dict for the CrossEncoder provider (W2.8):
# canonical model id plus the immutable pinned revision.
GOLDEN_IDENTITY_W2_1 = {
    "model_id": "cross-encoder/ms-marco-MiniLM-L6-v2",
    "model_revision": "233902d25c440f23af6f7d6e94d2946bac0bee0a",
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
    assert output.model_id == "cross-encoder/ms-marco-MiniLM-L6-v2"
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


# ---------------------------------------------------------------------------
# Pinned loader, artifact manifest, execution policy and raw-logit validation
# (test-plan.md "Worker unit, schema, action and CLI coverage", item 3)
# ---------------------------------------------------------------------------

SNAPSHOT_RELATIVE = (
    Path("models--cross-encoder--ms-marco-MiniLM-L6-v2")
    / "snapshots"
    / MODEL_REVISION
)

BASE_ARTIFACTS = {
    "config.json": b'{"model_type": "cross-encoder", "num_labels": 1}',
    "tokenizer_config.json": b'{"tokenizer_class": "BertTokenizer"}',
    "tokenizer.json": b'{"version": "1.0", "truncation": null}',
    "model.safetensors": b"fixed-weights\x00\x01\x02",
}


class _EvalRecordingModel:
    """Model double exposing only the evaluation mode the policy sets."""

    def __init__(self) -> None:
        self.evaluated = False

    def eval(self):
        self.evaluated = True
        return self


def digests_of(artifacts: dict) -> dict:
    return {
        name: hashlib.sha256(payload).hexdigest()
        for name, payload in artifacts.items()
    }


def manifest_text(artifacts: dict, **overrides) -> str:
    manifest = {
        "schema": MANIFEST_SCHEMA,
        "model_id": MODEL_ID,
        "model_revision": MODEL_REVISION,
        "artifacts": digests_of(artifacts),
    }
    manifest.update(overrides)
    return json.dumps(manifest, indent=2)


def provision(root: Path, artifacts: dict, manifest: str | None) -> Path:
    """Write a pinned snapshot directory, optionally with a manifest."""
    snapshot = root / SNAPSHOT_RELATIVE
    snapshot.mkdir(parents=True, exist_ok=True)
    for name, payload in artifacts.items():
        (snapshot / name).write_bytes(payload)
    if manifest is not None:
        (snapshot / MANIFEST_FILENAME).write_text(manifest, encoding="utf-8")
    return snapshot


def provider_pointing_at(root: Path, monkeypatch) -> CrossEncoderRankingProvider:
    """A provider whose model came from the given operator cache."""
    monkeypatch.setenv("SENTENCE_TRANSFORMERS_HOME", str(root))
    provider = CrossEncoderRankingProvider()
    provider._model = _EvalRecordingModel()
    provider._loaded_from_cache = True
    return provider


def install_fake_torch(monkeypatch) -> list:
    """Install a recording torch double for the execution-policy assertion."""
    calls = []

    torch = ModuleType("torch")
    torch.manual_seed = lambda seed: calls.append(("manual_seed", seed))
    torch.set_num_threads = lambda value: calls.append(("set_num_threads", value))
    torch.set_num_interop_threads = lambda value: calls.append(
        ("set_num_interop_threads", value)
    )
    torch.set_grad_enabled = lambda value: calls.append(("set_grad_enabled", value))
    torch.nn = SimpleNamespace(Identity=type("Identity", (), {}))

    numpy = ModuleType("numpy")
    numpy.random = SimpleNamespace(seed=lambda seed: calls.append(("numpy_seed", seed)))

    monkeypatch.setitem(sys.modules, "torch", torch)
    monkeypatch.setitem(sys.modules, "numpy", numpy)
    return calls


def test_manifest_missing_snapshot_fails_closed(monkeypatch, tmp_path):
    """An unprovisioned cache directory refuses to be used."""
    provider = provider_pointing_at(tmp_path / "cache", monkeypatch)
    with pytest.raises(ValueError):
        provider._ensure_ready()


def test_manifest_missing_manifest_fails_closed(monkeypatch, tmp_path):
    """A snapshot without its manifest refuses to be used."""
    root = tmp_path / "cache"
    provision(root, BASE_ARTIFACTS, None)

    provider = provider_pointing_at(root, monkeypatch)
    with pytest.raises(ValueError):
        provider._ensure_ready()


def test_manifest_mismatches_fail_closed(monkeypatch, tmp_path):
    """Every manifest defect fails closed before the runtime policy runs."""
    def declared_wrong_revision():
        artifacts = dict(BASE_ARTIFACTS)
        return artifacts, manifest_text(artifacts, model_revision="another-revision")

    def declared_wrong_model():
        artifacts = dict(BASE_ARTIFACTS)
        return artifacts, manifest_text(artifacts, model_id="cross-encoder/other-model")

    def declared_wrong_schema():
        artifacts = dict(BASE_ARTIFACTS)
        return artifacts, manifest_text(artifacts, schema="aiclip_ranking_artifacts_v2")

    def declared_unknown_key():
        artifacts = dict(BASE_ARTIFACTS)
        return artifacts, json.dumps({
            **json.loads(manifest_text(artifacts)),
            "unexpected": True,
        })

    def tampered_digest():
        artifacts = dict(BASE_ARTIFACTS)
        manifest = json.loads(manifest_text(artifacts))
        manifest["artifacts"]["config.json"] = "0" * 64
        return artifacts, json.dumps(manifest)

    def artifact_not_on_disk():
        artifacts = dict(BASE_ARTIFACTS)
        manifest = manifest_text({**artifacts, "extra.safetensors": b"missing"})
        return artifacts, manifest

    def required_file_undeclared():
        artifacts = dict(BASE_ARTIFACTS)
        manifest = json.loads(manifest_text(artifacts))
        del manifest["artifacts"]["config.json"]
        return artifacts, json.dumps(manifest)

    def no_weights_declared():
        artifacts = dict(BASE_ARTIFACTS)
        manifest = json.loads(manifest_text(artifacts))
        del manifest["artifacts"]["model.safetensors"]
        return artifacts, json.dumps(manifest)

    def pickle_artifact_declared():
        artifacts = dict(BASE_ARTIFACTS)
        artifacts["pytorch_model.bin"] = b"pickled-payload"
        return artifacts, manifest_text(artifacts)

    def non_hex_digest():
        artifacts = dict(BASE_ARTIFACTS)
        manifest = json.loads(manifest_text(artifacts))
        manifest["artifacts"]["config.json"] = "not-a-digest"
        return artifacts, json.dumps(manifest)

    def path_traversal_name():
        artifacts = dict(BASE_ARTIFACTS)
        manifest = json.loads(manifest_text(artifacts))
        manifest["artifacts"]["../config.json"] = manifest["artifacts"]["config.json"]
        return artifacts, json.dumps(manifest)

    def duplicate_manifest_key():
        artifacts = dict(BASE_ARTIFACTS)
        text = manifest_text(artifacts)
        return artifacts, text.replace('"schema":', '"schema":"dup","schema":', 1)

    def manifest_is_not_an_object():
        artifacts = dict(BASE_ARTIFACTS)
        return artifacts, json.dumps([1, 2, 3])

    def manifest_has_no_artifacts():
        artifacts = dict(BASE_ARTIFACTS)
        manifest = json.loads(manifest_text(artifacts))
        manifest["artifacts"] = {}
        return artifacts, json.dumps(manifest)

    def manifest_uses_nan():
        artifacts = dict(BASE_ARTIFACTS)
        return artifacts, manifest_text(artifacts).replace(
            f'"{MODEL_REVISION}"', "NaN", 1
        )

    scenarios = {
        "wrong_revision": declared_wrong_revision,
        "wrong_model": declared_wrong_model,
        "wrong_schema": declared_wrong_schema,
        "unknown_key": declared_unknown_key,
        "tampered_digest": tampered_digest,
        "artifact_not_on_disk": artifact_not_on_disk,
        "required_file_undeclared": required_file_undeclared,
        "no_weights_declared": no_weights_declared,
        "pickle_artifact": pickle_artifact_declared,
        "non_hex_digest": non_hex_digest,
        "path_traversal": path_traversal_name,
        "duplicate_key": duplicate_manifest_key,
        "not_an_object": manifest_is_not_an_object,
        "no_artifacts": manifest_has_no_artifacts,
        "non_finite_constant": manifest_uses_nan,
    }

    for name, builder in scenarios.items():
        artifacts, manifest = builder()
        root = tmp_path / name
        provision(root, artifacts, manifest)
        provider = provider_pointing_at(root, monkeypatch)
        try:
            provider._ensure_ready()
        except ValueError:
            continue
        raise AssertionError(
            f"manifest scenario {name!r} was accepted instead of failing closed"
        )


def test_manifest_accepts_provisioned_snapshot_and_applies_policy(monkeypatch, tmp_path):
    """A verified snapshot runs the pinned CPU execution policy once."""
    root = tmp_path / "cache"
    provision(root, BASE_ARTIFACTS, manifest_text(BASE_ARTIFACTS))
    calls = install_fake_torch(monkeypatch)

    provider = provider_pointing_at(root, monkeypatch)
    provider._ensure_ready()

    assert ("set_num_threads", 2) in calls
    assert ("set_num_interop_threads", 1) in calls
    assert ("set_grad_enabled", False) in calls
    assert provider._model.evaluated is True
    assert provider._loaded_from_cache is False, "the policy runs once"

    # A second readiness check must not repeat verification or the policy.
    before = list(calls)
    provider._ensure_ready()
    assert calls == before


def test_validated_logits_accepts_one_finite_scalar_per_candidate():
    """A 1-D vector of finite scalars of the exact cardinality is accepted."""
    assert CrossEncoderRankingProvider._validated_logits([0.0, -1.0], 2) == [0.0, -1.0]
    assert CrossEncoderRankingProvider._validated_logits([], 0) == []


@pytest.mark.parametrize(
    "raw",
    [
        [0.0],
        [0.0, 1.0, 2.0],
        [0.0, float("nan")],
        [0.0, float("inf")],
        [0.0, -float("inf")],
        [0.0, True],
        [0.0, "1.0"],
        [0.0, [1.0]],
        [[0.0, 1.0]],
        {"0": 0.0},
        "0.0",
        None,
        0.0,
    ],
    ids=[
        "short",
        "long",
        "nan",
        "inf",
        "-inf",
        "bool",
        "numeric_string",
        "nested_list",
        "row_vector",
        "mapping",
        "string",
        "null",
        "scalar",
    ],
)
def test_validated_logits_rejects_invalid_raw_output(raw):
    """Booleans, strings, wrong shapes/counts and nonfinite values reject
    the whole result before any normalization."""
    with pytest.raises(ValueError):
        CrossEncoderRankingProvider._validated_logits(raw, 2)


def test_quantization_applies_sigmoid_exactly_once():
    """Raw logit 0 yields 0.5, never sigmoid(0.5): one normalization only."""
    assert CrossEncoderRankingProvider._quantize_units(0.0) == 500000
    assert CrossEncoderRankingProvider._sigmoid(0.0) == 0.5
    # Independent expectation if the sigmoid were applied a second time.
    assert round(1.0 / (1.0 + math.exp(-0.5)), 6) == 0.622459
    assert CrossEncoderRankingProvider._quantize_units(0.0) != 622459


def test_quantization_survives_extreme_logits_without_overflow():
    """Saturating finite logits clamp to the inclusive endpoints."""
    assert CrossEncoderRankingProvider._quantize_units(1000.0) == 1000000
    assert CrossEncoderRankingProvider._quantize_units(-1000.0) == 0
    assert CrossEncoderRankingProvider._quantize_units(2.0) == 880797
    assert CrossEncoderRankingProvider._quantize_units(-2.0) == 119203
    assert CrossEncoderRankingProvider._quantize_units(-1.0) == 268941


@pytest.fixture(autouse=True)
def isolate_ranking_environment():
    """The loader switches process-level offline flags and library log
    levels; keep every such change inside the test that triggers it."""
    saved_environment = dict(os.environ)
    watched = (
        "transformers",
        "sentence_transformers",
        "huggingface_hub",
        "tokenizers",
        "torch",
        "urllib3",
    )
    saved_levels = {name: logging.getLogger(name).level for name in watched}
    yield
    os.environ.clear()
    os.environ.update(saved_environment)
    for name, level in saved_levels.items():
        logging.getLogger(name).setLevel(level)
