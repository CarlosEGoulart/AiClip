"""Versioned, metadata-only whole-scene timing analysis. No provider or I/O work."""

from __future__ import annotations

import json
import math
from abc import ABC, abstractmethod
from dataclasses import asdict, dataclass, replace
from typing import Any

SCALE = 1000000
MAX_DURATION = 2147483647
MAX_INPUT_BYTES = 8388608
MAX_SCENES = 10000
MAX_SEGMENTS = 50000


class ClipValidationError(ValueError):
    def __init__(self):
        super().__init__("Invalid clip analysis contract")


def require(condition: bool) -> None:
    if not condition:
        raise ClipValidationError()


def integer(value: Any, low: int, high: int) -> None:
    require(type(value) is int and low <= value <= high)


def fields(value: Any, required: set[str], optional: set[str] = frozenset()) -> None:
    require(type(value) is dict)
    require(required <= value.keys() and value.keys() <= required | optional)


def quantize(numerator: int, denominator: int) -> int:
    """Exact round-half-up to integer score units."""
    return (2 * numerator * SCALE + denominator) // (2 * denominator)


@dataclass(frozen=True)
class Weights:
    duration_fit: int
    speech_coverage: int
    boundary_alignment: int

    def __post_init__(self):
        integer(self.duration_fit, 1, 10000)
        integer(self.speech_coverage, 0, 10000)
        integer(self.boundary_alignment, 0, 10000)


@dataclass(frozen=True)
class ClipConfiguration:
    min_duration_ms: int
    target_duration_ms: int
    max_duration_ms: int
    max_candidates: int
    weights: Weights

    def __post_init__(self):
        for value in (self.min_duration_ms, self.target_duration_ms, self.max_duration_ms):
            integer(value, 1, MAX_DURATION)
        require(self.min_duration_ms <= self.target_duration_ms <= self.max_duration_ms)
        integer(self.max_candidates, 1, 1000)
        require(type(self.weights) is Weights)

    @classmethod
    def from_dict(cls, value: Any) -> ClipConfiguration:
        fields(value, {"min_duration_ms", "target_duration_ms", "max_duration_ms", "max_candidates", "weights"})
        fields(value["weights"], {"duration_fit", "speech_coverage", "boundary_alignment"})
        return cls(**{**value, "weights": Weights(**value["weights"])})


@dataclass(frozen=True)
class Timing:
    start_ms: int
    end_ms: int

    def __post_init__(self):
        integer(self.start_ms, 0, MAX_DURATION)
        integer(self.end_ms, self.start_ms, MAX_DURATION)


@dataclass(frozen=True)
class Scene(Timing):
    index: int

    def __post_init__(self):
        super().__post_init__()
        integer(self.index, 0, MAX_SCENES - 1)
        require(self.start_ms < self.end_ms)


@dataclass(frozen=True)
class ClipAnalysisInput:
    duration_ms: int
    scenes: tuple[Scene, ...]
    transcript_segments: tuple[Timing, ...] | None

    def __post_init__(self):
        integer(self.duration_ms, 1, MAX_DURATION)
        require(type(self.scenes) is tuple and len(self.scenes) <= MAX_SCENES)
        self._ordered(self.scenes, Scene)
        for index, scene in enumerate(self.scenes):
            require(scene.index == index)
        if self.transcript_segments is not None:
            require(type(self.transcript_segments) is tuple and len(self.transcript_segments) <= MAX_SEGMENTS)
            self._ordered(self.transcript_segments, Timing)

    def _ordered(self, intervals, kind):
        end = 0
        for interval in intervals:
            require(type(interval) is kind)
            require(end <= interval.start_ms and interval.end_ms <= self.duration_ms)
            end = interval.end_ms

    @classmethod
    def from_contract(cls, value: Any) -> tuple[ClipAnalysisInput, ClipConfiguration]:
        fields(value, {"version", "action", "media", "scenes", "configuration"}, {"transcript_segments"})
        require(value["version"] == "1.0.0" and value["action"] == "analyze_clips")
        fields(value["media"], {"duration_ms"})
        require(type(value["scenes"]) is list and len(value["scenes"]) <= MAX_SCENES)
        scenes = []
        for scene in value["scenes"]:
            fields(scene, {"index", "start_ms", "end_ms"})
            scenes.append(Scene(**scene))
        segments = None
        if "transcript_segments" in value:
            raw = value["transcript_segments"]
            require(type(raw) is list and len(raw) <= MAX_SEGMENTS)
            segments = []
            for segment in raw:
                fields(segment, {"start_ms", "end_ms"})
                segments.append(Timing(**segment))
            segments = tuple(segments)
        configuration = ClipConfiguration.from_dict(value["configuration"])
        data = cls(value["media"]["duration_ms"], tuple(scenes), segments)
        try:
            size = len(json.dumps(value, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8"))
        except (ValueError, TypeError, UnicodeError, RecursionError):
            raise ClipValidationError() from None
        require(size <= MAX_INPUT_BYTES)
        return data, configuration


@dataclass(frozen=True)
class ClipCandidate:
    index: int
    start_ms: int
    end_ms: int
    rank: int
    score_units: int
    duration_fit_units: int
    speech_coverage_units: int
    boundary_alignment_units: int
    source_scene_index: int

    def __post_init__(self):
        integer(self.index, 0, MAX_SCENES - 1)
        integer(self.start_ms, 0, MAX_DURATION)
        integer(self.end_ms, self.start_ms + 1, MAX_DURATION)
        integer(self.rank, 1, MAX_SCENES)
        integer(self.source_scene_index, 0, MAX_SCENES - 1)
        for score in (self.score_units, self.duration_fit_units, self.speech_coverage_units, self.boundary_alignment_units):
            integer(score, 0, SCALE)

    def to_dict(self) -> dict:
        return {
            "index": self.index, "start_ms": self.start_ms, "end_ms": self.end_ms,
            "rank": self.rank, "score": self.score_units / SCALE,
            "criteria": {"duration_fit": self.duration_fit_units / SCALE,
                         "speech_coverage": self.speech_coverage_units / SCALE,
                         "boundary_alignment": self.boundary_alignment_units / SCALE},
            "source_scene_indexes": [self.source_scene_index],
        }


def exact(actual: Any, expected: Any) -> None:
    """Structural comparison retaining bool/int, object/list and score distinctions."""
    if type(expected) is dict:
        fields(actual, set(expected))
        for key in expected:
            exact(actual[key], expected[key])
    elif type(expected) is list:
        require(type(actual) is list and len(actual) == len(expected))
        for left, right in zip(actual, expected):
            exact(left, right)
    elif type(expected) is float:
        require(type(actual) in (int, float) and math.isfinite(actual) and 0 <= actual <= 1)
        require(actual == expected)
    else:
        require(type(actual) is type(expected) and actual == expected)


@dataclass(frozen=True)
class ClipAnalysisResult:
    parameters: dict[str, Any]
    candidates: tuple[ClipCandidate, ...]
    algorithm: str = "scene_timing_baseline"
    algorithm_version: str = "1.0.0"

    def to_dict(self) -> dict:
        return {"algorithm": self.algorithm, "algorithm_version": self.algorithm_version,
                "parameters": self.parameters, "candidates": [c.to_dict() for c in self.candidates]}

    @classmethod
    def from_dict(cls, value: Any, data: ClipAnalysisInput, config: ClipConfiguration) -> ClipAnalysisResult:
        expected = _construct(data, config)
        exact(value, expected.to_dict())
        return expected


class ClipCandidateAnalyzer(ABC):
    @abstractmethod
    def analyze(self, validated_input: ClipAnalysisInput, configuration: ClipConfiguration) -> ClipAnalysisResult:
        """Analyze validated original-media timing metadata."""


class _CoverageSweep:
    """Prefix coverage and strict interior membership at ascending scene boundaries."""

    def __init__(self, segments: tuple[Timing, ...]):
        self.segments = segments
        self.position = 0
        self.covered = 0

    def at(self, boundary: int) -> tuple[int, bool]:
        while self.position < len(self.segments) and self.segments[self.position].end_ms <= boundary:
            segment = self.segments[self.position]
            self.covered += segment.end_ms - segment.start_ms
            self.position += 1
        if self.position == len(self.segments):
            return self.covered, True
        segment = self.segments[self.position]
        return (self.covered + max(0, boundary - segment.start_ms),
                not (segment.start_ms < boundary < segment.end_ms))


def _construct(data: ClipAnalysisInput, config: ClipConfiguration) -> ClipAnalysisResult:
    require(type(data) is ClipAnalysisInput and type(config) is ClipConfiguration)
    used = data.transcript_segments is not None
    weights = asdict(config.weights) if used else {"duration_fit": config.weights.duration_fit,
                                                   "speech_coverage": 0, "boundary_alignment": 0}
    parameters = {
        "configuration": asdict(config), "effective_weights": weights, "transcript_used": used,
        "candidate_policy": "whole_scene_non_overlapping", "timing_policy": "original_media_ms",
        "transcript_policy": "optional_strict_unshifted", "boundary_policy": "strict_interior_speech_cut",
        "score_scale": SCALE, "rounding": "half_up",
        "limits": {"max_scenes": MAX_SCENES, "max_transcript_segments": MAX_SEGMENTS,
                   "max_input_bytes": MAX_INPUT_BYTES, "max_duration_ms": MAX_DURATION},
    }
    sweep = _CoverageSweep(data.transcript_segments or ())
    candidates = []
    for scene in data.scenes:
        length = scene.end_ms - scene.start_ms
        if not config.min_duration_ms <= length <= config.max_duration_ms:
            continue
        duration = quantize(min(length, config.target_duration_ms), max(length, config.target_duration_ms))
        speech = boundary = 0
        if used:
            start_coverage, start_safe = sweep.at(scene.start_ms)
            end_coverage, end_safe = sweep.at(scene.end_ms)
            speech = quantize(end_coverage - start_coverage, length)
            boundary = quantize(int(start_safe) + int(end_safe), 2)
        total = weights["duration_fit"] * duration + weights["speech_coverage"] * speech + weights["boundary_alignment"] * boundary
        denominator = sum(weights.values())
        score = (2 * total + denominator) // (2 * denominator)
        candidates.append(ClipCandidate(scene.index, scene.start_ms, scene.end_ms, 1, score,
                                        duration, speech, boundary, scene.index))
    ranked = sorted(candidates, key=lambda c: (-c.score_units, c.start_ms, c.end_ms, c.source_scene_index))[:config.max_candidates]
    ranked = [replace(candidate, rank=rank) for rank, candidate in enumerate(ranked, 1)]
    chronological = sorted(ranked, key=lambda c: (c.start_ms, c.end_ms, c.source_scene_index))
    return ClipAnalysisResult(parameters, tuple(replace(c, index=i) for i, c in enumerate(chronological)))


class DeterministicClipCandidateAnalyzer(ClipCandidateAnalyzer):
    def analyze(self, validated_input: ClipAnalysisInput, configuration: ClipConfiguration) -> ClipAnalysisResult:
        return _construct(validated_input, configuration)
