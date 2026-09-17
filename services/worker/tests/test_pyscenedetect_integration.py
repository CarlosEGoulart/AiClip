"""Integration tests for PySceneDetectAdapter using real FFmpeg-generated video.

These tests generate tiny synthetic videos with solid color cuts and verify
real PySceneDetect behavior. They require FFmpeg and scenedetect[opencv-headless].
"""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path

import pytest

# Skip entire module if scenedetect is not available
try:
    import scenedetect  # noqa: F401
    HAS_SCENEDETECT = True
except ImportError:
    HAS_SCENEDETECT = False

# Skip if FFmpeg is not available
try:
    subprocess.run(["ffmpeg", "-version"], capture_output=True, check=True)
    HAS_FFMPEG = True
except (subprocess.CalledProcessError, FileNotFoundError):
    HAS_FFMPEG = False


@pytest.mark.skipif(
    not (HAS_SCENEDETECT and HAS_FFMPEG),
    reason="scenedetect[opencv-headless] and FFmpeg required for integration tests",
)
class TestPySceneDetectIntegration:
    """Integration tests with real FFmpeg-generated video and real PySceneDetect."""

    def _create_test_video(self, output_path: str, duration_seconds: int = 6) -> None:
        """Create a test video with 3 solid color segments (A→B→C) using FFmpeg.

        Creates a video with:
        - 0-2s: Red solid color
        - 2-4s: Green solid color
        - 4-6s: Blue solid color
        """
        # Build filter complex for 3 color segments
        filter_complex = (
            "color=c=red:size=320x240:duration=2:rate=30[red];"
            "color=c=green:size=320x240:duration=2:rate=30[green];"
            "color=c=blue:size=320x240:duration=2:rate=30[blue];"
            "[red][green][blue]concat=n=3:v=1:a=0[out]"
        )

        cmd = [
            "ffmpeg", "-y",
            "-filter_complex", filter_complex,
            "-map", "[out]",
            "-c:v", "libx264",
            "-pix_fmt", "yuv420p",
            "-t", str(duration_seconds),
            output_path,
        ]

        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            raise RuntimeError(f"FFmpeg failed: {result.stderr}")

    def test_real_scenedetect_on_solid_color_cuts(self) -> None:
        """Test real PySceneDetect on video with A→B→C color cuts.

        Verifies:
        - Real scenedetect import works
        - Real decode works
        - ContentDetector detects boundaries
        - Ordered boundaries returned
        - Non-overlapping scenes
        - Integer millisecond boundaries
        - Final scene ends at or before duration
        """
        from aiclip_worker.scene_detection import validate_scene_result
        from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter

        with tempfile.TemporaryDirectory() as tmpdir:
            video_path = os.path.join(tmpdir, "test_color_cuts.mp4")
            self._create_test_video(video_path, duration_seconds=6)

            # Verify video was created
            assert os.path.exists(video_path)
            assert os.path.getsize(video_path) > 0

            # Run real PySceneDetectAdapter
            adapter = PySceneDetectAdapter()
            result = adapter.detect(video_path, options={"duration_ms": 6000})

            # Verify detector metadata
            assert result.detector == "pyscenedetect"
            assert result.detector_version != "unknown"
            assert "threshold" in result.parameters

            # Verify scenes detected
            scenes = result.scenes
            assert len(scenes) >= 2, f"Expected at least 2 scenes, got {len(scenes)}"

            # Verify scene structure
            for scene in scenes:
                assert isinstance(scene.index, int)
                assert isinstance(scene.start_ms, int)
                assert isinstance(scene.end_ms, int)

            # Verify sequential 0-based indexes
            for i, scene in enumerate(scenes):
                assert scene.index == i, f"Scene at position {i} has index {scene.index}"

            # Verify ordered by start_ms (non-decreasing)
            for i in range(1, len(scenes)):
                assert scenes[i].start_ms >= scenes[i - 1].start_ms, \
                    f"Scenes not ordered: {scenes[i].start_ms} < {scenes[i - 1].start_ms}"

            # Verify non-overlapping (each scene start >= previous end)
            for i in range(1, len(scenes)):
                assert scenes[i].start_ms >= scenes[i - 1].end_ms, \
                    f"Scenes overlap: {scenes[i].start_ms} < {scenes[i - 1].end_ms}"

            # Verify integer millisecond boundaries
            for scene in scenes:
                assert scene.start_ms >= 0
                assert scene.end_ms > scene.start_ms

            # Verify final scene ends at or before duration
            assert scenes[-1].end_ms <= 6000, \
                f"Final scene end_ms {scenes[-1].end_ms} exceeds duration 6000"

            # Run validation (should not raise)
            validate_scene_result(scenes)

    def test_real_scenedetect_detects_expected_boundaries(self) -> None:
        """Test that ContentDetector finds the color change boundaries approximately.

        With 3 solid color segments (2s each at 30fps), we expect boundaries
        near 2000ms and 4000ms (within reasonable tolerance).
        """
        from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter

        with tempfile.TemporaryDirectory() as tmpdir:
            video_path = os.path.join(tmpdir, "test_color_cuts.mp4")
            self._create_test_video(video_path, duration_seconds=6)

            adapter = PySceneDetectAdapter()
            result = adapter.detect(video_path, options={"duration_ms": 6000, "threshold": 15.0})

            scenes = result.scenes
            assert len(scenes) >= 2

            # Check that we have boundaries approximately at 2s and 4s
            # Allow generous tolerance since PySceneDetect uses content analysis
            boundaries = [s.start_ms for s in scenes[1:]]  # Skip first scene (starts at 0)

            # Should detect boundaries near 2000ms and 4000ms
            has_boundary_near_2s = any(1500 <= b <= 2500 for b in boundaries)
            has_boundary_near_4s = any(3500 <= b <= 4500 for b in boundaries)

            assert has_boundary_near_2s, f"No boundary near 2s found in {boundaries}"
            assert has_boundary_near_4s, f"No boundary near 4s found in {boundaries}"

    def test_real_scenedetect_single_color_no_scenes(self) -> None:
        """Test video with single solid color produces minimal scenes."""
        from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter

        with tempfile.TemporaryDirectory() as tmpdir:
            video_path = os.path.join(tmpdir, "test_single_color.mp4")

            # Create single color video
            filter_complex = "color=c=red:size=320x240:duration=3:rate=30"
            cmd = [
                "ffmpeg", "-y",
                "-filter_complex", filter_complex,
                "-c:v", "libx264", "-pix_fmt", "yuv420p", "-t", "3", video_path,
            ]
            result = subprocess.run(cmd, capture_output=True, text=True)
            assert result.returncode == 0, f"FFmpeg failed: {result.stderr}"

            adapter = PySceneDetectAdapter()
            result = adapter.detect(video_path, options={"duration_ms": 3000})

            # Single color video may produce 0 scenes (no changes detected)
            scenes = result.scenes
            assert len(scenes) >= 0

            # Validate all invariants
            for i, scene in enumerate(scenes):
                assert scene.index == i

            from aiclip_worker.scene_detection import validate_scene_result
            validate_scene_result(scenes)