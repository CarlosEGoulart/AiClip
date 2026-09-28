"""Clip ranking provider interface and implementations.

Provider boundaries (spec.md "Provider and model decision"):

* `FakeRankingProvider` is the deterministic CI/test profile. It performs no
  inference and its metadata must never name the real model.
* `CrossEncoderRankingProvider` is the pinned real profile `minilm_cpu_v1`.
  It loads the exact model id/revision from the operator-owned local cache
  only (`local_files_only=True`, no remote code, safetensors, CPU), verifies
  the artifact manifest before the first inference and never falls back to a
  fabricated result.

Runtime, cache, dependency and output failures propagate to the action, which
reports a sanitized `ranking_failed`; there is no offline/fabricated fallback
and no environment-based profile detection.

The module is import-safe without any machine-learning dependency: torch,
numpy and sentence-transformers are imported lazily inside the real loader.
"""

from __future__ import annotations

import hashlib
import json
import math
import os
from abc import ABC, abstractmethod
from dataclasses import dataclass
from pathlib import Path
from typing import Sequence

# Deterministic seed for model inference
os.environ.setdefault("PYTHONHASHSEED", "0")

# ---------------------------------------------------------------------------
# Versioned scoring configuration (spec.md "Versioned scoring configuration")
# ---------------------------------------------------------------------------

ALGORITHM = "transcript_semantic_recommendation"
ALGORITHM_VERSION = "1.0.0"
PROJECTION_VERSION = "1.0.0"
QUERY_VERSION = "1.0.0"

# The single fixed query. It is never user controlled and never varies by
# profile; the request configuration must carry exactly this string.
PROTOTYPE_QUERY = (
    "Engaging, self-contained short-form video clip highlight with a clear "
    "narrative or punchline."
)

# Exact request configuration key set, in the specification's order.
CONFIGURATION_KEYS = (
    "provider",
    "algorithm",
    "algorithm_version",
    "projection_version",
    "query_version",
    "prototype_query",
    "model_id",
    "model_revision",
    "runtime_profile",
    "normalization",
    "max_tokens",
    "batch_size",
    "truncation",
)

# Static provider criteria metadata. It is provider metadata only: the worker
# protocol never serializes criteria across the boundary.
CRITERIA = (
    "query_passage_relevance",
    "quantized_score_desc",
    "m4_rank_asc",
    "candidate_index_asc",
)

SELECTOR_FAKE = "fake"
SELECTOR_CROSS_ENCODER = "cross_encoder"
SELECTORS = (SELECTOR_FAKE, SELECTOR_CROSS_ENCODER)

# Pinned real profile identity: canonical model id + immutable revision.
MODEL_ID = "cross-encoder/ms-marco-MiniLM-L6-v2"
MODEL_REVISION = "233902d25c440f23af6f7d6e94d2946bac0bee0a"
PROVIDER_NAME = "cross_encoder_ranking_provider"
RUNTIME_PROFILE = "minilm_cpu_v1"
NORMALIZATION = "stable_sigmoid_half_up_6"
MAX_TOKENS = 512
BATCH_SIZE = 8
TRUNCATION = "right_longest_first_512"

# Deterministic fake profile identity (never names the real model).
FAKE_MODEL_ID = "fake-ranking-v1"
FAKE_MODEL_REVISION = "1.0.0"
FAKE_PROVIDER_NAME = "fake_ranking_provider"
FAKE_RUNTIME_PROFILE = "fake_v1"
FAKE_NORMALIZATION = "fixture_units_6"
FAKE_MAX_TOKENS = 0
FAKE_BATCH_SIZE = 0
FAKE_TRUNCATION = "none"

# Scores are quantized to integer units before ordering (half-up).
SCORE_UNITS = 1000000

# Artifact manifest of the operator-provisioned local cache.
MANIFEST_FILENAME = "aiclip_ranking_manifest.json"
MANIFEST_SCHEMA = "aiclip_ranking_artifacts_v1"

_REQUIRED_MANIFEST_FILES = ("config.json", "tokenizer_config.json")
_REQUIRED_MANIFEST_FILE_GROUPS = (("tokenizer.json", "vocab.txt"),)
_FORBIDDEN_MANIFEST_SUFFIXES = (
    ".bin",
    ".pt",
    ".pth",
    ".pkl",
    ".pickle",
    ".ckpt",
    ".h5",
    ".msgpack",
    ".onnx",
)


def profile(selector: object) -> dict:
    """Return the pinned profile values of a trusted selector.

    Unknown or unset selection fails closed; there is no environment
    detection and no fallback profile.
    """
    if selector == SELECTOR_CROSS_ENCODER:
        return {
            "provider_name": PROVIDER_NAME,
            "model_id": MODEL_ID,
            "model_revision": MODEL_REVISION,
            "runtime_profile": RUNTIME_PROFILE,
            "normalization": NORMALIZATION,
            "max_tokens": MAX_TOKENS,
            "batch_size": BATCH_SIZE,
            "truncation": TRUNCATION,
            "inference_performed": True,
        }
    if selector == SELECTOR_FAKE:
        return {
            "provider_name": FAKE_PROVIDER_NAME,
            "model_id": FAKE_MODEL_ID,
            "model_revision": FAKE_MODEL_REVISION,
            "runtime_profile": FAKE_RUNTIME_PROFILE,
            "normalization": FAKE_NORMALIZATION,
            "max_tokens": FAKE_MAX_TOKENS,
            "batch_size": FAKE_BATCH_SIZE,
            "truncation": FAKE_TRUNCATION,
            "inference_performed": False,
        }
    raise ValueError("Unknown ranking provider selection")


def expected_configuration(selector: object) -> dict:
    """The exact request configuration of the selected pinned profile."""
    values = profile(selector)
    return {
        "provider": selector,
        "algorithm": ALGORITHM,
        "algorithm_version": ALGORITHM_VERSION,
        "projection_version": PROJECTION_VERSION,
        "query_version": QUERY_VERSION,
        "prototype_query": PROTOTYPE_QUERY,
        "model_id": values["model_id"],
        "model_revision": values["model_revision"],
        "runtime_profile": values["runtime_profile"],
        "normalization": values["normalization"],
        "max_tokens": values["max_tokens"],
        "batch_size": values["batch_size"],
        "truncation": values["truncation"],
    }


def _same_value(left: object, right: object) -> bool:
    """Type-strict value equality.

    Python treats `True == 1` and `1 == 1.0`; the wire contract does not, so
    booleans are only equal to booleans and the runtime types must match
    exactly before the values are compared.
    """
    if isinstance(left, bool) or isinstance(right, bool):
        return isinstance(left, bool) and isinstance(right, bool) and left is right
    if type(left) is not type(right):
        return False
    return bool(left == right)


def configuration_matches_profile(configuration: object, selector: object) -> bool:
    """Whether a configuration object is exactly the pinned selected profile."""
    if not isinstance(configuration, dict):
        return False
    try:
        expected = expected_configuration(selector)
    except ValueError:
        return False

    if set(configuration.keys()) != set(expected.keys()):
        return False

    return all(_same_value(configuration[key], expected[key]) for key in expected)


@dataclass(frozen=True)
class RankingInput:
    """Input for clip ranking.

    Each candidate is a typed projection of the validated request carrying
    `index`, `start_ms`, `end_ms`, `m4_rank`, `m4_score` and
    `transcript_text`. Direct provider callers may use the pre-migration
    internal spelling `rank` for the M4 rank; the wire contract only ever
    carries `m4_rank`.
    """

    candidates: Sequence[dict]
    prototype_query: str


@dataclass(frozen=True)
class Recommendation:
    """Single clip recommendation (provider-level, scored entries only)."""

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


def _m4_rank(candidate: dict) -> int:
    """Read the original M4 rank of a typed provider candidate."""
    if "m4_rank" in candidate:
        return candidate["m4_rank"]
    return candidate["rank"]


class ClipRankingProvider(ABC):
    """Abstract base class for clip ranking providers."""

    # Selection identifier serialized as `parameters.provider`.
    provider_key: str
    # Normalization label serialized as `parameters.normalization`.
    normalization: str
    # Algorithm label serialized as `ranking.algorithm`.
    algorithm: str = ALGORITHM
    # Whether real model inference was performed for this profile.
    inference_performed: bool
    # Static criteria metadata (never serialized across the boundary).
    criteria: Sequence[str] = CRITERIA

    @abstractmethod
    def rank(self, input: RankingInput) -> RankingOutput:
        """Rank candidates by semantic relevance."""

    @abstractmethod
    def get_model_identity(self) -> dict:
        """Return {model_id, model_revision, provider_name}."""


class FakeRankingProvider(ClipRankingProvider):
    """Deterministic fake provider for CI and testing.

    Scores are fixture units `max(0, 1000000 - (m4_rank - 1) * 100000)`
    divided by 1000000. This is deliberately not semantic inference and the
    metadata never names the real model.
    """

    provider_key = SELECTOR_FAKE
    normalization = FAKE_NORMALIZATION
    inference_performed = False

    def rank(self, input: RankingInput) -> RankingOutput:
        """Return deterministic descending scores by M4 rank."""
        units_by_index = {}
        m4_rank_by_index = {}
        for candidate in input.candidates:
            m4_rank = _m4_rank(candidate)
            m4_rank_by_index[candidate["index"]] = m4_rank
            units_by_index[candidate["index"]] = max(
                0, SCORE_UNITS - (m4_rank - 1) * 100000
            )

        # Ordering: quantized units descending, then ascending M4 rank, then
        # stable candidate index.
        ordered_indexes = sorted(
            (candidate["index"] for candidate in input.candidates),
            key=lambda index: (
                -units_by_index[index],
                m4_rank_by_index[index],
                index,
            ),
        )

        recommendations = [
            Recommendation(
                m4_candidate_index=index,
                semantic_score=units_by_index[index] / float(SCORE_UNITS),
                combined_rank=position + 1,
            )
            for position, index in enumerate(ordered_indexes)
        ]

        return RankingOutput(
            recommendations=recommendations,
            model_id=FAKE_MODEL_ID,
            model_revision=FAKE_MODEL_REVISION,
            provider_name=FAKE_PROVIDER_NAME,
            transcript_used=False,
        )

    def get_model_identity(self) -> dict:
        return {
            "model_id": FAKE_MODEL_ID,
            "model_revision": FAKE_MODEL_REVISION,
            "provider_name": FAKE_PROVIDER_NAME,
        }


class CrossEncoderRankingProvider(ClipRankingProvider):
    """Pinned cross-encoder provider (`minilm_cpu_v1`) using sentence-transformers."""

    provider_key = SELECTOR_CROSS_ENCODER
    normalization = NORMALIZATION
    inference_performed = True

    PROTOTYPE_QUERY = PROTOTYPE_QUERY
    MODEL_ID = MODEL_ID
    MODEL_REVISION = MODEL_REVISION
    PROVIDER_NAME = PROVIDER_NAME

    def __init__(self) -> None:
        self._model = None
        # Set only when this provider loaded the artifacts itself, so that the
        # artifact manifest and the runtime policy are always verified before
        # the first inference on a locally loaded model. A model injected
        # directly by a test double carries no cache artifacts to verify.
        self._loaded_from_cache = False

    def _load_model(self) -> None:
        """Lazy-load the pinned cross-encoder from the local cache only.

        Loading contract (spec.md pinned real profile `minilm_cpu_v1`):
        exact model id, immutable revision, `local_files_only=True`,
        `trust_remote_code=False`, safetensors-only weights, CPU device,
        eager attention and the configured operator cache. Identity activation
        is forced so the raw classifier logits are normalized exactly once by
        this module. Inference network access and telemetry are disabled for
        the process. Any import, cache, artifact or dependency failure
        propagates to the caller; there is no download, revision or offline
        fallback.

        Artifact manifest verification and the execution policy run in
        `_ensure_ready()` before the first prediction.
        """
        if self._model is not None:
            return

        # Inference must never reach the network, including telemetry, and
        # must never emit library progress or logging.
        os.environ["HF_HUB_OFFLINE"] = "1"
        os.environ["TRANSFORMERS_OFFLINE"] = "1"
        os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"
        os.environ["TQDM_DISABLE"] = "1"
        os.environ["TOKENIZERS_PARALLELISM"] = "false"

        import logging

        for logger_name in (
            "transformers",
            "sentence_transformers",
            "huggingface_hub",
            "tokenizers",
            "torch",
            "urllib3",
        ):
            logging.getLogger(logger_name).setLevel(logging.ERROR)

        # Ensure deterministic inference
        import torch
        import numpy as np

        torch.manual_seed(0)
        np.random.seed(0)

        from sentence_transformers import CrossEncoder

        self._model = CrossEncoder(
            self.MODEL_ID,
            revision=self.MODEL_REVISION,
            local_files_only=True,
            trust_remote_code=False,
            device="cpu",
            cache_dir=os.environ.get("SENTENCE_TRANSFORMERS_HOME"),
            automodel_args={
                "use_safetensors": True,
                "attn_implementation": "eager",
            },
            default_activation_function=torch.nn.Identity(),
        )
        self._loaded_from_cache = True

    def _ensure_ready(self) -> None:
        """Verify the loaded artifacts and apply the execution policy.

        Runs before the first prediction on a model this provider loaded:
        the pinned artifact manifest of the exact revision is checked
        (safetensors only, digests over config/tokenizer/weights), then the
        CPU execution policy (two intra-op threads, one inter-op thread,
        no-grad, evaluation mode) is applied. Any mismatch, missing artifact
        or missing runtime propagates as a sanitized ranking failure.
        """
        if not self._loaded_from_cache:
            return

        self._verify_artifact_manifest()

        import torch

        torch.set_num_threads(2)
        try:
            torch.set_num_interop_threads(1)
        except RuntimeError:
            # The inter-op pool is process global and can only be configured
            # before the first inter-op work; a second provider instance in
            # the same process keeps the policy already applied.
            pass
        torch.set_grad_enabled(False)
        self._model.eval()

        self._loaded_from_cache = False

    def _snapshot_dir(self) -> Path:
        cache = os.environ.get("SENTENCE_TRANSFORMERS_HOME")
        if not cache:
            raise ValueError("Operator ranking cache is not configured")
        snapshot = (
            Path(cache)
            / ("models--" + self.MODEL_ID.replace("/", "--"))
            / "snapshots"
            / self.MODEL_REVISION
        )
        if not snapshot.is_dir():
            raise ValueError("Pinned ranking snapshot is not provisioned")
        return snapshot

    @staticmethod
    def _strict_object(pairs):
        result = {}
        for key, value in pairs:
            if key in result:
                raise ValueError("Duplicate manifest key")
            result[key] = value
        return result

    @staticmethod
    def _reject_constant(value):
        raise ValueError("Non-finite manifest constant")

    @staticmethod
    def _sha256(path: Path) -> str:
        digest = hashlib.sha256()
        with open(path, "rb") as stream:
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                digest.update(chunk)
        return digest.hexdigest()

    @staticmethod
    def _is_digest(value: object) -> bool:
        return (
            isinstance(value, str)
            and len(value) == 64
            and all(character in "0123456789abcdef" for character in value)
        )

    def _verify_artifact_manifest(self) -> None:
        """Verify the operator manifest of the pinned snapshot before use."""
        snapshot = self._snapshot_dir()
        manifest_path = snapshot / MANIFEST_FILENAME
        if not manifest_path.is_file():
            raise ValueError("Ranking artifact manifest is missing")

        with open(manifest_path, encoding="utf-8") as stream:
            manifest = json.loads(
                stream.read(),
                object_pairs_hook=self._strict_object,
                parse_constant=self._reject_constant,
            )

        if not isinstance(manifest, dict):
            raise ValueError("Ranking artifact manifest must be an object")
        if set(manifest.keys()) != {
            "schema",
            "model_id",
            "model_revision",
            "artifacts",
        }:
            raise ValueError("Ranking artifact manifest key set mismatch")
        if manifest["schema"] != MANIFEST_SCHEMA:
            raise ValueError("Unsupported ranking artifact manifest schema")
        if manifest["model_id"] != self.MODEL_ID:
            raise ValueError("Ranking artifact manifest model mismatch")
        if manifest["model_revision"] != self.MODEL_REVISION:
            raise ValueError("Ranking artifact manifest revision mismatch")

        artifacts = manifest["artifacts"]
        if not isinstance(artifacts, dict) or not artifacts:
            raise ValueError("Ranking artifact manifest has no artifacts")

        names = []
        for name, expected in artifacts.items():
            if not isinstance(name, str) or not name:
                raise ValueError("Invalid artifact name")
            if Path(name).name != name or name in (".", ".."):
                raise ValueError("Artifact name must be a plain file name")
            if not self._is_digest(expected):
                raise ValueError("Artifact digest must be lowercase sha256")
            if name.lower().endswith(_FORBIDDEN_MANIFEST_SUFFIXES):
                raise ValueError("Pickle or non-safetensors artifacts are forbidden")
            artifact = snapshot / name
            if not artifact.is_file():
                raise ValueError("Pinned ranking artifact is missing")
            if self._sha256(artifact) != expected:
                raise ValueError("Ranking artifact digest mismatch")
            names.append(name)

        for required in _REQUIRED_MANIFEST_FILES:
            if required not in names:
                raise ValueError("Required ranking artifact is not pinned")
        if not any(name.endswith(".safetensors") for name in names):
            raise ValueError("No safetensors weight artifact is pinned")
        for group in _REQUIRED_MANIFEST_FILE_GROUPS:
            if not any(name in names for name in group):
                raise ValueError("Required tokenizer artifact is not pinned")

    @staticmethod
    def _validated_logits(raw, expected: int) -> list[float]:
        """Validate the raw inference output before any normalization.

        Real inference must return a 1-D vector of exactly one finite scalar
        logit per eligible candidate. Booleans, strings, sequences, wrong
        shapes/counts and any non-finite value reject the whole result.
        """
        if isinstance(raw, (bool, str, bytes, bytearray, dict)):
            raise ValueError("Inference output must be a sequence of logits")

        try:
            values = list(raw)
        except TypeError as exc:  # scalar / non-iterable output
            raise ValueError("Inference output must be a sequence of logits") from exc

        if len(values) != expected:
            raise ValueError("Inference output cardinality mismatch")

        logits = []
        for value in values:
            if isinstance(value, (bool, str, bytes, bytearray, list, tuple, dict, set)):
                raise ValueError("Non-scalar logit not allowed")
            if hasattr(value, "__len__"):
                # e.g. a numpy row of shape (1,) or (N, 1): not a scalar.
                raise ValueError("Non-scalar logit not allowed")
            try:
                number = float(value)
            except (TypeError, ValueError) as exc:
                raise ValueError("Non-numeric logit not allowed") from exc
            if not math.isfinite(number):
                raise ValueError("Non-finite logit not allowed")
            logits.append(number)

        return logits

    @staticmethod
    def _sigmoid(logit: float) -> float:
        """Numerically stable binary sigmoid, applied exactly once."""
        if logit >= 0.0:
            return 1.0 / (1.0 + math.exp(-logit))
        exp = math.exp(logit)
        return exp / (1.0 + exp)

    @classmethod
    def _quantize_units(cls, logit: float) -> int:
        """Half-up quantization of the sigmoid score to integer units."""
        score = cls._sigmoid(logit)
        units = math.floor(score * SCORE_UNITS + 0.5)

        return max(0, min(SCORE_UNITS, units))

    def _predict_logits(self, pairs: list) -> list[float]:
        """Run inference in pinned batches of 8 and validate every batch."""
        logits: list[float] = []
        batch_size = BATCH_SIZE
        for start in range(0, len(pairs), batch_size):
            chunk = pairs[start:start + batch_size]
            logits.extend(self._validated_logits(self._model.predict(chunk), len(chunk)))
        return logits

    def rank(self, input: RankingInput) -> RankingOutput:
        """Rank candidates using the pinned cross-encoder model.

        Quantization happens before ordering, so ties are resolved by the
        quantized units, then ascending M4 rank, then stable candidate index.
        """
        if not input.candidates:
            return RankingOutput(
                recommendations=[],
                model_id=self.MODEL_ID,
                model_revision=self.MODEL_REVISION,
                provider_name=self.PROVIDER_NAME,
                transcript_used=False,
            )

        transcript_used = any(c.get("transcript_text", "") for c in input.candidates)

        # Runtime/dependency/cache failures propagate: the action reports a
        # sanitized `ranking_failed` instead of fabricating semantic success.
        self._load_model()
        self._ensure_ready()

        pairs = [
            (input.prototype_query, candidate.get("transcript_text", ""))
            for candidate in input.candidates
        ]

        logits = self._predict_logits(pairs)

        scored = [
            (candidate, self._quantize_units(logit))
            for candidate, logit in zip(input.candidates, logits, strict=True)
        ]

        # Ordering: quantized units descending, ascending M4 rank, stable index.
        scored.sort(key=lambda entry: (-entry[1], _m4_rank(entry[0]), entry[0]["index"]))

        recommendations = [
            Recommendation(
                m4_candidate_index=candidate["index"],
                semantic_score=units / float(SCORE_UNITS),
                combined_rank=position + 1,
            )
            for position, (candidate, units) in enumerate(scored)
        ]

        return RankingOutput(
            recommendations=recommendations,
            model_id=self.MODEL_ID,
            model_revision=self.MODEL_REVISION,
            provider_name=self.PROVIDER_NAME,
            transcript_used=transcript_used,
        )

    def get_model_identity(self) -> dict:
        return {
            "model_id": self.MODEL_ID,
            "model_revision": self.MODEL_REVISION,
            "provider_name": self.PROVIDER_NAME,
        }
