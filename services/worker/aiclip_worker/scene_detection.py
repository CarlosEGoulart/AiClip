"""Scene detection engine abstraction for video scene boundary detection."""

from __future__ import annotations

import hashlib
import os
from abc import ABC, abstractmethod
from dataclasses import dataclass
from typing import List


@dataclass
class Scene:
    """A single scene boundary with timing information."""

    index: int
    start_ms: int
    end_ms: int

    def __post_init__(self) -> None:
        if not isinstance(self.index, int):
            raise TypeError("index must be an integer")
        if not isinstance(self.start_ms, int):
            raise TypeError("start_ms must be an integer")
        if not isinstance(self.end_ms, int):
            raise TypeError("end_ms must be an integer")
        if self.start_ms < 0:
            raise ValueError("start_ms must be >= 0")
        if self.end_ms <= self.start_ms:
            raise ValueError("end_ms must be > start_ms")


@dataclass
class SceneResult:
    """Result of a scene detection operation."""

    detector: str
    detector_version: str
    parameters: dict
    scenes: List[Scene]


class SceneDetector(ABC):
    """Abstract base class for scene detection engines."""

    @abstractmethod
    def detect(self, video_path: str, options: dict | None = None) -> SceneResult:
        """Detect scenes in a video file."""
        ...

    @abstractmethod
    def get_name(self) -> str:
        """Return the detector name."""
        ...

    @abstractmethod
    def get_version(self) -> str:
        """Return the detector version."""
        ...


class DeterministicSceneDetector(SceneDetector):
    """Deterministic scene detector for CI/testing. No model downloads."""

    def get_name(self) -> str:
        return "deterministic"

    def get_version(self) -> str:
        return "0.0.0"

    def detect(self, video_path: str, options: dict | None = None) -> SceneResult:
        """Return deterministic scenes based on video file path hash."""
        path_hash = hashlib.sha256(video_path.encode()).hexdigest()[:32]

        # Generate 2-5 deterministic scenes based on hash
        num_scenes = 2 + (int(path_hash[:4], 16) % 4)  # 2-5 scenes

        duration_ms = 30000  # default 30s bound
        if options and "duration_ms" in options:
            duration_ms = options["duration_ms"]

        # Divide duration evenly across scenes with some variance
        base_scene_duration = max(duration_ms // num_scenes, 100)

        scenes: list[Scene] = []
        current_ms = 0
        for i in range(num_scenes):
            # Deterministic variance per scene
            variance = int(path_hash[i * 4 : i * 4 + 4], 16) % 2000 - 1000
            scene_duration = max(100, base_scene_duration + variance)

            end_ms = min(current_ms + scene_duration, duration_ms)
            if i == num_scenes - 1:
                end_ms = duration_ms  # last scene extends to duration

            scenes.append(Scene(
                index=i,
                start_ms=current_ms,
                end_ms=end_ms,
            ))
            current_ms = end_ms

        result = SceneResult(
            detector="deterministic",
            detector_version="0.0.0",
            parameters={},
            scenes=scenes,
        )

        validate_scene_result(result.scenes)

        return result


def get_scene_detector(name: str | None = None) -> SceneDetector:
    """Factory function to get a scene detector by name.

    Args:
        name: Detector name ('deterministic' or 'pyscenedetect').
              If None, uses SCENE_DETECTION_ENGINE env var.
              Defaults to 'pyscenedetect'.

    Returns:
        SceneDetector instance.

    Raises:
        ValueError: If detector name is unknown.
        ImportError: If pyscenedetect is requested but not installed.
    """
    if name is None:
        name = os.environ.get("SCENE_DETECTION_ENGINE", "pyscenedetect")

    if name == "deterministic":
        return DeterministicSceneDetector()
    elif name == "pyscenedetect":
        try:
            from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter
            return PySceneDetectAdapter()
        except ImportError as e:
            raise ImportError(
                "scenedetect is required for PySceneDetectAdapter. "
                "Install it with: pip install scenedetect[opencv-headless]"
            ) from e
    else:
        raise ValueError(f"Unknown scene detection engine: {name}")


def validate_scene_result(scenes: list[Scene]) -> None:
    """Validate that scenes are ordered, non-overlapping, and have valid timing.

    Args:
        scenes: List of Scene objects to validate.

    Raises:
        ValueError: If scenes are not ordered, overlap, have invalid timing,
                    or contain duplicate indexes.
    """
    if not scenes:
        return

    seen_indexes: set[int] = set()

    for i, scene in enumerate(scenes):
        # Validate individual scene fields
        if scene.start_ms < 0:
            raise ValueError(
                f"Scene {i} has negative start_ms: {scene.start_ms}"
            )
        if scene.end_ms <= scene.start_ms:
            raise ValueError(
                f"Scene {i} has end_ms ({scene.end_ms}) <= start_ms ({scene.start_ms})"
            )

        # Check ordering
        if i > 0 and scene.start_ms < scenes[i - 1].start_ms:
            raise ValueError(
                f"Scenes not ordered: scene {i} start_ms={scene.start_ms} < "
                f"scene {i - 1} start_ms={scenes[i - 1].start_ms}"
            )

        # Check overlap
        if i > 0 and scene.start_ms < scenes[i - 1].end_ms:
            raise ValueError(
                f"Scenes overlap: scene {i} start_ms={scene.start_ms} < "
                f"scene {i - 1} end_ms={scenes[i - 1].end_ms}"
            )

        # Check duplicate indexes
        if scene.index in seen_indexes:
            raise ValueError(
                f"Duplicate scene index: {scene.index}"
            )
        seen_indexes.add(scene.index)

    # Validate sequential 0-based indexes
    for i, scene in enumerate(scenes):
        if scene.index != i:
            raise ValueError(
                f"Scenes must have sequential 0-based indexes: "
                f"scene at position {i} has index {scene.index}, expected {i}"
            )
