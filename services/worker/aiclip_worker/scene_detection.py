"""Scene detection engine abstraction for video scene boundary detection."""

from __future__ import annotations

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


def get_scene_detector(name: str | None = None) -> SceneDetector:
    """Factory function to get a scene detector by name."""
    raise NotImplementedError("get_scene_detector not yet implemented")


def validate_scene_result(scenes: list[Scene]) -> None:
    """Validate that scenes are ordered, non-overlapping, and have valid timing.

    Args:
        scenes: List of Scene objects to validate.

    Raises:
        ValueError: If scenes are not ordered, overlap, or have invalid timing.
    """
    raise NotImplementedError("validate_scene_result not yet implemented")
