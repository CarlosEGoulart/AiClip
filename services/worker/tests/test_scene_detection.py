"""Tests for the scene detection engine abstraction."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path
from unittest.mock import MagicMock, patch

import pytest

from aiclip_worker.scene_detection import (
    DeterministicSceneDetector,
    Scene,
    SceneDetector,
    SceneResult,
    get_scene_detector,
    validate_scene_result,
)


class TestScene:
    """Test Scene dataclass."""

    def test_scene_creation_with_valid_data(self) -> None:
        """Scene stores index, start_ms, and end_ms correctly."""
        scene = Scene(index=0, start_ms=0, end_ms=3120)
        assert scene.index == 0
        assert scene.start_ms == 0
        assert scene.end_ms == 3120

    def test_scene_index_is_integer(self) -> None:
        """Scene index is an integer."""
        scene = Scene(index=1, start_ms=100, end_ms=200)
        assert isinstance(scene.index, int)

    def test_scene_start_ms_is_integer(self) -> None:
        """Scene start_ms is an integer."""
        scene = Scene(index=0, start_ms=100, end_ms=200)
        assert isinstance(scene.start_ms, int)

    def test_scene_end_ms_is_integer(self) -> None:
        """Scene end_ms is an integer."""
        scene = Scene(index=0, start_ms=100, end_ms=200)
        assert isinstance(scene.end_ms, int)

    def test_scene_rejects_negative_start_ms(self) -> None:
        """Scene rejects negative start_ms."""
        with pytest.raises(ValueError, match="start_ms"):
            Scene(index=0, start_ms=-1, end_ms=1000)

    def test_scene_rejects_end_ms_less_than_start_ms(self) -> None:
        """Scene rejects end_ms < start_ms."""
        with pytest.raises(ValueError, match="end_ms"):
            Scene(index=0, start_ms=1000, end_ms=500)

    def test_scene_rejects_end_ms_equal_to_start_ms(self) -> None:
        """Scene rejects end_ms == start_ms (must be strictly greater)."""
        with pytest.raises(ValueError, match="end_ms"):
            Scene(index=0, start_ms=1000, end_ms=1000)


class TestSceneResult:
    """Test SceneResult dataclass."""

    def test_scene_result_creation_with_valid_data(self) -> None:
        """SceneResult stores all fields correctly."""
        scenes = [Scene(index=0, start_ms=0, end_ms=3120)]
        result = SceneResult(
            detector="deterministic",
            detector_version="0.0.0",
            parameters={},
            scenes=scenes,
        )
        assert result.detector == "deterministic"
        assert result.detector_version == "0.0.0"
        assert result.parameters == {}
        assert len(result.scenes) == 1
        assert isinstance(result.scenes[0], Scene)

    def test_scene_result_scenes_is_list_of_scene(self) -> None:
        """SceneResult scenes is a list of Scene objects."""
        scenes = [
            Scene(index=0, start_ms=0, end_ms=3120),
            Scene(index=1, start_ms=3120, end_ms=8400),
        ]
        result = SceneResult(
            detector="deterministic",
            detector_version="0.0.0",
            parameters={},
            scenes=scenes,
        )
        assert isinstance(result.scenes, list)
        for scene in result.scenes:
            assert isinstance(scene, Scene)

    def test_scene_result_accepts_empty_scenes(self) -> None:
        """SceneResult accepts empty scenes list."""
        result = SceneResult(
            detector="deterministic",
            detector_version="0.0.0",
            parameters={},
            scenes=[],
        )
        assert result.scenes == []


class TestDeterministicSceneDetector:
    """Test DeterministicSceneDetector implementation."""

    def test_deterministic_detector_returns_deterministic_output(self) -> None:
        """Same video file path returns identical SceneResult."""
        detector = DeterministicSceneDetector()
        video_path = "/tmp/test_video.mp4"

        result1 = detector.detect(video_path)
        result2 = detector.detect(video_path)

        assert result1.detector == result2.detector
        assert result1.detector_version == result2.detector_version
        assert len(result1.scenes) == len(result2.scenes)
        assert result1.detector == "deterministic"
        assert result1.detector_version == "0.0.0"

        for s1, s2 in zip(result1.scenes, result2.scenes):
            assert s1.index == s2.index
            assert s1.start_ms == s2.start_ms
            assert s1.end_ms == s2.end_ms

    def test_deterministic_detector_returns_different_output_for_different_input(self) -> None:
        """Different video file paths produce different scenes."""
        detector = DeterministicSceneDetector()

        result1 = detector.detect("/tmp/video_a.mp4")
        result2 = detector.detect("/tmp/video_b.mp4")

        # At least scene count or timing should differ
        assert result1.scenes != result2.scenes or len(result1.scenes) != len(result2.scenes)

    def test_deterministic_detector_scenes_format(self) -> None:
        """Scenes are ordered by start_ms ascending with valid format."""
        detector = DeterministicSceneDetector()
        result = detector.detect("/tmp/test_video.mp4")

        assert isinstance(result.scenes, list)

        for scene in result.scenes:
            assert isinstance(scene, Scene)
            assert scene.start_ms >= 0
            assert scene.end_ms > scene.start_ms

        # Verify ordering by start_ms
        for i in range(1, len(result.scenes)):
            assert result.scenes[i].start_ms >= result.scenes[i - 1].start_ms

    def test_deterministic_detector_scenes_ordered_by_start_ms(self) -> None:
        """Scenes are strictly ordered by start_ms."""
        detector = DeterministicSceneDetector()
        result = detector.detect("/tmp/test_video.mp4")

        for i in range(1, len(result.scenes)):
            assert result.scenes[i].start_ms >= result.scenes[i - 1].start_ms

    def test_deterministic_detector_no_overlapping_scenes(self) -> None:
        """Scenes do not overlap (each scene's start >= previous scene's end)."""
        detector = DeterministicSceneDetector()
        result = detector.detect("/tmp/test_video.mp4")

        for i in range(1, len(result.scenes)):
            assert result.scenes[i].start_ms >= result.scenes[i - 1].end_ms

    def test_deterministic_detector_scenes_within_duration_bounds(self) -> None:
        """All scenes have reasonable timing (within 24-hour bound)."""
        detector = DeterministicSceneDetector()
        result = detector.detect("/tmp/test_video.mp4")

        max_duration_ms = 24 * 60 * 60 * 1000  # 24 hours
        for scene in result.scenes:
            assert scene.start_ms >= 0
            assert scene.end_ms <= max_duration_ms

    def test_deterministic_detector_get_name(self) -> None:
        """DeterministicSceneDetector returns 'deterministic' as name."""
        detector = DeterministicSceneDetector()
        assert detector.get_name() == "deterministic"

    def test_deterministic_detector_get_version(self) -> None:
        """DeterministicSceneDetector returns '0.0.0' as version."""
        detector = DeterministicSceneDetector()
        assert detector.get_version() == "0.0.0"

    def test_deterministic_detector_is_scene_detector(self) -> None:
        """DeterministicSceneDetector is a SceneDetector."""
        detector = DeterministicSceneDetector()
        assert isinstance(detector, SceneDetector)


class TestValidateSceneResult:
    """Test validate_scene_result function."""

    def test_validate_scenes_valid(self) -> None:
        """validate_scene_result accepts valid ordered, non-overlapping scenes."""
        scenes = [
            Scene(index=0, start_ms=0, end_ms=3120),
            Scene(index=1, start_ms=3120, end_ms=8400),
            Scene(index=2, start_ms=8400, end_ms=12000),
        ]
        validate_scene_result(scenes)  # Should not raise

    def test_validate_scenes_empty_list(self) -> None:
        """validate_scene_result accepts empty scenes list."""
        validate_scene_result([])  # Should not raise

    def test_validate_scenes_unordered(self) -> None:
        """validate_scene_result rejects scenes not ordered by start_ms."""
        scenes = [
            Scene(index=0, start_ms=1000, end_ms=2000),
            Scene(index=1, start_ms=0, end_ms=1000),
        ]
        with pytest.raises(ValueError, match="order"):
            validate_scene_result(scenes)

    def test_validate_scenes_overlapping(self) -> None:
        """validate_scene_result rejects overlapping scenes."""
        scenes = [
            Scene(index=0, start_ms=0, end_ms=1500),
            Scene(index=1, start_ms=1000, end_ms=2000),
        ]
        with pytest.raises(ValueError, match="overlap"):
            validate_scene_result(scenes)

    def test_validate_scenes_duplicate_indexes(self) -> None:
        """validate_scene_result rejects duplicate indexes."""
        scenes = [
            Scene(index=0, start_ms=0, end_ms=1000),
            Scene(index=0, start_ms=1000, end_ms=2000),
        ]
        with pytest.raises(ValueError, match="(?i)duplicate"):
            validate_scene_result(scenes)

    def test_validate_scenes_negative_boundary(self) -> None:
        """validate_scene_result rejects negative start_ms."""
        scene = Scene.__new__(Scene)
        scene.index = 0
        scene.start_ms = -1
        scene.end_ms = 1000
        scenes = [scene]
        with pytest.raises(ValueError, match="start_ms"):
            validate_scene_result(scenes)

    def test_validate_scenes_duration_overflow(self) -> None:
        """validate_scene_result rejects end_ms <= start_ms."""
        scene = Scene.__new__(Scene)
        scene.index = 0
        scene.start_ms = 1000
        scene.end_ms = 1000
        scenes = [scene]
        with pytest.raises(ValueError, match="end_ms"):
            validate_scene_result(scenes)

    def test_validate_scenes_rejects_end_ms_less_than_start_ms(self) -> None:
        """validate_scene_result rejects end_ms < start_ms."""
        scene = Scene.__new__(Scene)
        scene.index = 0
        scene.start_ms = 1000
        scene.end_ms = 500
        scenes = [scene]
        with pytest.raises(ValueError, match="end_ms"):
            validate_scene_result(scenes)

    def test_validate_scenes_single_scene(self) -> None:
        """validate_scene_result accepts a single valid scene."""
        scenes = [Scene(index=0, start_ms=0, end_ms=5000)]
        validate_scene_result(scenes)  # Should not raise

    def test_validate_scenes_contiguous(self) -> None:
        """validate_scene_result accepts contiguous scenes (end == next start)."""
        scenes = [
            Scene(index=0, start_ms=0, end_ms=3120),
            Scene(index=1, start_ms=3120, end_ms=6000),
        ]
        validate_scene_result(scenes)  # Should not raise


class TestGetSceneDetector:
    """Test get_scene_detector factory function."""

    def test_get_scene_detector_returns_deterministic(self) -> None:
        """get_scene_detector returns DeterministicSceneDetector for 'deterministic'."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "deterministic"}):
            detector = get_scene_detector()
            assert isinstance(detector, DeterministicSceneDetector)

    def test_get_scene_detector_defaults_to_deterministic(self) -> None:
        """get_scene_detector defaults to DeterministicSceneDetector."""
        with patch.dict(os.environ, {}, clear=True):
            detector = get_scene_detector()
            assert isinstance(detector, DeterministicSceneDetector)

    def test_get_scene_detector_raises_for_unknown(self) -> None:
        """get_scene_detector raises ValueError for unknown engine."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "unknown_engine"}):
            with pytest.raises((ValueError, ImportError)):
                get_scene_detector()

    def test_get_scene_detector_explicit_name(self) -> None:
        """get_scene_detector respects explicit engine parameter."""
        detector = get_scene_detector("deterministic")
        assert isinstance(detector, DeterministicSceneDetector)

    def test_get_scene_detector_explicit_name_overrides_env(self) -> None:
        """get_scene_detector explicit parameter overrides env var."""
        with patch.dict(os.environ, {"SCENE_DETECTION_ENGINE": "unknown"}):
            detector = get_scene_detector("deterministic")
            assert isinstance(detector, DeterministicSceneDetector)
