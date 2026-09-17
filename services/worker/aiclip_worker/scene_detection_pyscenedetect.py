"""PySceneDetect adapter for real scene boundary detection."""

from __future__ import annotations

import os
from typing import Any

from aiclip_worker.scene_detection import Scene, SceneDetector, SceneResult


class PySceneDetectAdapter(SceneDetector):
    """Scene detection adapter using the PySceneDetect library.

    Lazy-imports scenedetect at detect-time, not at import time.
    Uses ContentDetector with configurable threshold.
    """

    def get_name(self) -> str:
        return "pyscenedetect"

    def get_version(self) -> str:
        try:
            import scenedetect
            return scenedetect.__version__
        except ImportError:
            return "unknown"

    def detect(self, video_path: str, options: dict[str, Any] | None = None) -> SceneResult:
        """Detect scenes in a video file using PySceneDetect.

        Args:
            video_path: Path to the video file.
            options: Optional dict with keys:
                - duration_ms: Optional duration bound in milliseconds.
                - threshold: Optional ContentDetector threshold override.

        Returns:
            SceneResult with detected scenes.

        Raises:
            ImportError: If scenedetect is not installed.
            FileNotFoundError: If video_path does not exist.
            Exception: If video is corrupt/unreadable (exceptions propagate).
        """
        import scenedetect
        from scenedetect import ContentDetector

        # Determine threshold: explicit option > env var > default 27.0
        threshold = 27.0
        env_threshold = os.environ.get("SCENE_DETECT_THRESHOLD")
        if env_threshold is not None:
            try:
                threshold = float(env_threshold)
            except (ValueError, TypeError):
                pass
        if options and "threshold" in options:
            try:
                threshold = float(options["threshold"])
            except (ValueError, TypeError):
                pass

        # Build effective parameters dict for transparency
        effective_params: dict[str, Any] = {
            "threshold": threshold,
        }
        if options and "duration_ms" in options:
            effective_params["duration_ms"] = int(options["duration_ms"])

        # Build ContentDetector with the threshold
        detector = ContentDetector(threshold=threshold)

        # Run scene detection directly — corrupt/unreadable errors propagate naturally
        scene_list = scenedetect.detect(video_path, detector)

        # Convert timecodes to integer milliseconds and create Scene objects
        scenes: list[Scene] = []
        for idx, scene in enumerate(scene_list):
            start_tc = scene[0]
            end_tc = scene[1]

            start_ms = int(start_tc.get_seconds() * 1000)
            end_ms = int(end_tc.get_seconds() * 1000)

            # Ensure end_ms > start_ms (PySceneDetect can return zero-duration)
            if end_ms <= start_ms:
                end_ms = start_ms + 1

            scenes.append(Scene(
                index=idx,
                start_ms=start_ms,
                end_ms=end_ms,
            ))

        return SceneResult(
            detector="pyscenedetect",
            detector_version=scenedetect.__version__,
            parameters=effective_params,
            scenes=scenes,
        )
