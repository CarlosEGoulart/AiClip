"""Vertical clip rendering with FFmpeg (Corrected Design per spec.md)."""

from __future__ import annotations

import json
import math
import subprocess
import tempfile
import time
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


@dataclass
class RenderConfiguration:
    """Render configuration with validation."""

    target_width: int = 1080
    target_height: int = 1920
    target_fps: int = 30
    video_codec: str = "libx264"
    video_bitrate_kbps: int = 5000
    audio_codec: str = "aac"
    audio_bitrate_kbps: int = 128

    def __post_init__(self) -> None:
        if not isinstance(self.target_width, int) or self.target_width < 1 or self.target_width > 4096 or self.target_width % 2 != 0:
            raise ValueError("target_width must be an even integer between 1 and 4096")
        if not isinstance(self.target_height, int) or self.target_height < 1 or self.target_height > 4096 or self.target_height % 2 != 0:
            raise ValueError("target_height must be an even integer between 1 and 4096")
        if not isinstance(self.target_fps, int) or self.target_fps < 1 or self.target_fps > 120:
            raise ValueError("target_fps must be an integer between 1 and 120")
        if self.video_codec not in ("libx264", "libx265", "h264_videotoolbox", "hevc_videotoolbox"):
            raise ValueError("video_codec must be one of: libx264, libx265, h264_videotoolbox, hevc_videotoolbox")
        if not isinstance(self.video_bitrate_kbps, int) or self.video_bitrate_kbps < 500 or self.video_bitrate_kbps > 50000:
            raise ValueError("video_bitrate_kbps must be an integer between 500 and 50000")
        if self.audio_codec not in ("aac", "libfdk_aac", "copy"):
            raise ValueError("audio_codec must be one of: aac, libfdk_aac, copy")
        if not isinstance(self.audio_bitrate_kbps, int) or self.audio_bitrate_kbps < 32 or self.audio_bitrate_kbps > 320:
            raise ValueError("audio_bitrate_kbps must be an integer between 32 and 320")

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> RenderConfiguration:
        return cls(
            target_width=data.get("target_width", 1080),
            target_height=data.get("target_height", 1920),
            target_fps=data.get("target_fps", 30),
            video_codec=data.get("video_codec", "libx264"),
            video_bitrate_kbps=data.get("video_bitrate_kbps", 5000),
            audio_codec=data.get("audio_codec", "aac"),
            audio_bitrate_kbps=data.get("audio_bitrate_kbps", 128),
        )

    def to_dict(self) -> dict[str, Any]:
        return {
            "target_width": self.target_width,
            "target_height": self.target_height,
            "target_fps": self.target_fps,
            "video_codec": self.video_codec,
            "video_bitrate_kbps": self.video_bitrate_kbps,
            "audio_codec": self.audio_codec,
            "audio_bitrate_kbps": self.audio_bitrate_kbps,
        }


# Fixed limits and policies (spec.md)
MAX_RECOMMENDATIONS = 1000
MAX_INPUT_BYTES = 8 * 1024 * 1024  # 8 MiB
MAX_DURATION_MS = 2147483647
DEFAULT_FFMPEG_TIMEOUT = 300  # seconds
FFMPEG_TIMEOUT_MIN = 30
FFMPEG_TIMEOUT_MAX = 1800

# Corrected profile identity (spec.md)
RENDER_PROFILE_VERSION = "vertical_v1"
ALGORITHM = "vertical"
ALGORITHM_VERSION = "vertical_v1"


@dataclass
class RenderCandidate:
    """Input candidate for rendering."""
    index: int
    start_ms: int
    end_ms: int
    semantic_rank: int
    semantic_score: float | None


@dataclass
class SourceMediaInfo:
    """Source media information from probe."""
    disk: str
    key: str
    width: int
    height: int
    video_codec: str
    audio_codec: str | None


@dataclass
class RenderInput:
    """Validated input for rendering (Corrected: NO database identifiers)."""
    duration_ms: int
    recommendation: dict[str, Any]
    candidate_index: int
    source_media: SourceMediaInfo
    output_key: str  # Precomputed by Laravel


@dataclass
class RenderedClip:
    """Output clip metadata."""
    candidate_index: int
    semantic_rank: int
    semantic_score: float | None
    start_ms: int
    end_ms: int
    duration_ms: int
    output: dict[str, Any]


@dataclass
class RenderParameters:
    """Algorithm parameters for provenance."""
    configuration: dict[str, Any]
    source_media: dict[str, Any]
    ffmpeg_version: str
    filter_graph: str
    limits: dict[str, int] = field(default_factory=lambda: {
        "max_recommendations": MAX_RECOMMENDATIONS,
        "max_input_bytes": MAX_INPUT_BYTES,
        "max_duration_ms": MAX_DURATION_MS,
    })


@dataclass
class RenderResult:
    """Render result (Corrected: algorithm = 'vertical', algorithm_version = 'vertical_v1')."""
    algorithm: str = ALGORITHM
    algorithm_version: str = ALGORITHM_VERSION
    parameters: RenderParameters | None = None
    clips: list[RenderedClip] = field(default_factory=list)


class RenderError(Exception):
    """Render error with sanitized message."""
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class InvalidCandidateIndex(RenderError):
    """Invalid candidate index error."""
    def __init__(self, message: str = "Invalid candidate index"):
        super().__init__("invalid_candidate_index", message)


class RenderFailed(RenderError):
    """Render failed error."""
    def __init__(self, message: str = "Clip render failed"):
        super().__init__("render_failed", message)


class VerticalClipRenderer(ABC):
    """Abstract interface for vertical clip rendering."""

    @abstractmethod
    def render(self, validated_input: RenderInput, configuration: RenderConfiguration) -> RenderResult:
        """Render a vertical clip from the selected candidate."""
        pass


class FFmpegVerticalClipRenderer(VerticalClipRenderer):
    """FFmpeg-based vertical clip renderer."""

    def __init__(self, ffmpeg_timeout: int = DEFAULT_FFMPEG_TIMEOUT) -> None:
        if not isinstance(ffmpeg_timeout, int) or ffmpeg_timeout < FFMPEG_TIMEOUT_MIN or ffmpeg_timeout > FFMPEG_TIMEOUT_MAX:
            raise ValueError(f"ffmpeg_timeout must be an integer between {FFMPEG_TIMEOUT_MIN} and {FFMPEG_TIMEOUT_MAX}")
        self.ffmpeg_timeout = ffmpeg_timeout

    def _get_ffmpeg_version(self) -> str:
        """Get FFmpeg version string."""
        try:
            result = subprocess.run(
                ["ffmpeg", "-version"],
                capture_output=True,
                text=True,
                timeout=10,
            )
            first_line = result.stdout.split("\n")[0] if result.stdout else "unknown"
            return first_line.strip()
        except Exception:
            return "unknown"

    def _probe_source_media(self, input_path: str) -> dict[str, Any]:
        """Probe source media for metadata."""
        try:
            result = subprocess.run(
                [
                    "ffprobe", "-v", "quiet", "-print_format", "json",
                    "-show_streams", "-show_format", input_path
                ],
                capture_output=True,
                text=True,
                timeout=30,
            )
            if result.returncode != 0:
                raise RenderFailed("Failed to probe source media")
            return json.loads(result.stdout)
        except json.JSONDecodeError:
            raise RenderFailed("Failed to parse ffprobe output")
        except subprocess.TimeoutExpired:
            raise RenderFailed("ffprobe timed out")
        except Exception:
            raise RenderFailed("Failed to probe source media")

    def _build_filter_graph(
        self,
        candidate: RenderCandidate,
        config: RenderConfiguration,
        source_width: int,
        source_height: int,
    ) -> str:
        """Build the FFmpeg filter graph for vertical reframe (center-crop baseline)."""
        # Center crop to 9:16 aspect ratio
        # crop=ih*9/16:ih:(iw-ih*9/16)/2:0
        crop_width = f"ih*{9}/16"
        crop_height = "ih"
        crop_x = f"(iw-ih*{9}/16)/2"
        crop_y = "0"

        filter_parts = [
            f"crop={crop_width}:{crop_height}:{crop_x}:{crop_y}",
            f"scale={config.target_width}:{config.target_height}:force_original_aspect_ratio=decrease",
            f"pad={config.target_width}:{config.target_height}:(ow-iw)/2:(oh-ih)/2",
            f"fps={config.target_fps}",
        ]

        return ",".join(filter_parts)

    def _run_ffmpeg(
        self,
        input_path: str,
        output_path: str,
        filter_graph: str,
        config: RenderConfiguration,
        start_s: float,
        duration_s: float,
    ) -> dict[str, Any]:
        """Run FFmpeg to render the clip."""
        # Use input seeking for speed (-ss before -i)
        cmd = [
            "ffmpeg", "-y",
            "-ss", str(start_s),
            "-t", str(duration_s),
            "-i", input_path,
            "-filter_complex", filter_graph,
            "-c:v", config.video_codec,
            "-b:v", f"{config.video_bitrate_kbps}k",
            "-c:a", config.audio_codec,
            "-b:a", f"{config.audio_bitrate_kbps}k",
            "-movflags", "+faststart",
            output_path,
        ]

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=self.ffmpeg_timeout,
            )
        except subprocess.TimeoutExpired:
            raise RenderFailed("FFmpeg timed out")
        except Exception as e:
            raise RenderFailed(f"FFmpeg execution failed: {e}")

        if result.returncode != 0:
            raise RenderFailed("FFmpeg failed")

        # Probe output file for metadata
        try:
            probe_result = subprocess.run(
                [
                    "ffprobe", "-v", "quiet", "-print_format", "json",
                    "-show_streams", "-show_format", output_path
                ],
                capture_output=True,
                text=True,
                timeout=30,
            )
            if probe_result.returncode != 0:
                raise RenderFailed("Failed to probe output file")
            probe_data = json.loads(probe_result.stdout)
        except json.JSONDecodeError:
            raise RenderFailed("Failed to parse ffprobe output for rendered file")
        except subprocess.TimeoutExpired:
            raise RenderFailed("ffprobe timed out for rendered file")
        except Exception:
            raise RenderFailed("Failed to probe rendered file")

        # Extract output metadata
        video_stream = None
        audio_stream = None
        for stream in probe_data.get("streams", []):
            if stream.get("codec_type") == "video" and video_stream is None:
                video_stream = stream
            elif stream.get("codec_type") == "audio" and audio_stream is None:
                audio_stream = stream

        format_info = probe_data.get("format", {})

        output_duration_ms = 0
        if video_stream and "duration" in video_stream:
            output_duration_ms = int(float(video_stream["duration"]) * 1000)
        elif "duration" in format_info:
            output_duration_ms = int(float(format_info["duration"]) * 1000)

        output_width = video_stream.get("width", 0) if video_stream else 0
        output_height = video_stream.get("height", 0) if video_stream else 0
        output_video_codec = video_stream.get("codec_name", "") if video_stream else ""
        output_audio_codec = audio_stream.get("codec_name", "") if audio_stream else ""

        # Get bitrates
        video_bitrate = config.video_bitrate_kbps
        audio_bitrate = config.audio_bitrate_kbps
        if video_stream and "bit_rate" in video_stream:
            try:
                video_bitrate = int(int(video_stream["bit_rate"]) / 1000)
            except (ValueError, TypeError):
                pass
        if audio_stream and "bit_rate" in audio_stream:
            try:
                audio_bitrate = int(int(audio_stream["bit_rate"]) / 1000)
            except (ValueError, TypeError):
                pass

        # Get file size
        try:
            size_bytes = Path(output_path).stat().st_size
        except Exception:
            size_bytes = 0

        return {
            "duration_ms": output_duration_ms,
            "width": output_width,
            "height": output_height,
            "video_codec": output_video_codec,
            "audio_codec": output_audio_codec,
            "video_bitrate_kbps": video_bitrate,
            "audio_bitrate_kbps": audio_bitrate,
            "size_bytes": size_bytes,
        }

    def render(self, validated_input: RenderInput, configuration: RenderConfiguration) -> RenderResult:
        """Render the vertical clip (Corrected: uses precomputed output_key)."""
        # Select candidate by index
        candidates = validated_input.recommendation.get("candidates", [])
        if validated_input.candidate_index < 0 or validated_input.candidate_index >= len(candidates):
            raise InvalidCandidateIndex("candidate_index out of bounds")

        selected_candidate_data = candidates[validated_input.candidate_index]

        # Validate selected candidate has non-null semantic_score
        if selected_candidate_data.get("semantic_score") is None:
            raise InvalidCandidateIndex("selected candidate must have non-null semantic_score")

        candidate = RenderCandidate(
            index=selected_candidate_data["index"],
            start_ms=selected_candidate_data["start_ms"],
            end_ms=selected_candidate_data["end_ms"],
            semantic_rank=selected_candidate_data["semantic_rank"],
            semantic_score=selected_candidate_data["semantic_score"],
        )

        # Validate bounds against duration
        if candidate.start_ms < 0 or candidate.start_ms > validated_input.duration_ms:
            raise InvalidCandidateIndex("candidate start_ms out of range")
        if candidate.end_ms <= candidate.start_ms or candidate.end_ms > validated_input.duration_ms:
            raise InvalidCandidateIndex("candidate end_ms invalid")

        # Use precomputed output key from Laravel (NOT generated in worker)
        output_key = validated_input.output_key

        # Get source media path from storage (Flysystem would be used in real implementation)
        # For now, assume local file path based on disk/key
        input_path = validated_input.source_media.key  # This should be a local path in worker context

        # Build filter graph
        filter_graph = self._build_filter_graph(
            candidate,
            configuration,
            validated_input.source_media.width,
            validated_input.source_media.height,
        )

        # Calculate timing
        start_s = candidate.start_ms / 1000.0
        duration_s = (candidate.end_ms - candidate.start_ms) / 1000.0

        # Create temp output file
        with tempfile.NamedTemporaryFile(suffix=".mp4", delete=False) as tmp:
            temp_output_path = tmp.name

        try:
            # Run FFmpeg
            output_meta = self._run_ffmpeg(
                input_path,
                temp_output_path,
                filter_graph,
                configuration,
                start_s,
                duration_s,
            )

            # Verify output
            if output_meta["size_bytes"] <= 0:
                raise RenderFailed("Output file is empty")
            if output_meta["duration_ms"] <= 0:
                raise RenderFailed("Output duration is invalid")
            if output_meta["width"] != configuration.target_width or output_meta["height"] != configuration.target_height:
                raise RenderFailed("Output resolution does not match configuration")

            # Get FFmpeg version
            ffmpeg_version = self._get_ffmpeg_version()

            # Build result
            clip = RenderedClip(
                candidate_index=candidate.index,
                semantic_rank=candidate.semantic_rank,
                semantic_score=candidate.semantic_score,
                start_ms=candidate.start_ms,
                end_ms=candidate.end_ms,
                duration_ms=candidate.end_ms - candidate.start_ms,
                output={
                    "disk": validated_input.source_media.disk,
                    "key": output_key,  # Precomputed by Laravel
                    "size_bytes": output_meta["size_bytes"],
                    "duration_ms": output_meta["duration_ms"],
                    "width": output_meta["width"],
                    "height": output_meta["height"],
                    "video_codec": output_meta["video_codec"],
                    "audio_codec": output_meta["audio_codec"],
                    "video_bitrate_kbps": output_meta["video_bitrate_kbps"],
                    "audio_bitrate_kbps": output_meta["audio_bitrate_kbps"],
                },
            )

            parameters = RenderParameters(
                configuration=configuration.to_dict(),
                source_media={
                    "disk": validated_input.source_media.disk,
                    "key": validated_input.source_media.key,
                    "duration_ms": validated_input.duration_ms,
                    "width": validated_input.source_media.width,
                    "height": validated_input.source_media.height,
                    "video_codec": validated_input.source_media.video_codec,
                    "audio_codec": validated_input.source_media.audio_codec,
                },
                ffmpeg_version=ffmpeg_version,
                filter_graph=filter_graph,
            )

            return RenderResult(
                parameters=parameters,
                clips=[clip],
            )

        finally:
            # Clean up temp file
            try:
                Path(temp_output_path).unlink(missing_ok=True)
            except Exception:
                pass


def _validate_input(contract: dict[str, Any]) -> RenderInput:
    """Validate and convert contract to RenderInput (Corrected: NO database identifiers)."""
    # This is called after contract validation, so we assume basic structure is valid
    media = contract["media"]
    recommendation = contract["recommendation"]
    candidate_index = contract["candidate_index"]
    source_media = contract["source_media"]
    output_key = contract["output_key"]  # Precomputed by Laravel

    return RenderInput(
        duration_ms=media["duration_ms"],
        recommendation=recommendation,
        candidate_index=candidate_index,
        source_media=SourceMediaInfo(
            disk=source_media["disk"],
            key=source_media["key"],
            width=source_media["width"],
            height=source_media["height"],
            video_codec=source_media["video_codec"],
            audio_codec=source_media.get("audio_codec"),
        ),
        output_key=output_key,
    )


def render_clip(contract: dict[str, Any], configuration: RenderConfiguration, ffmpeg_timeout: int) -> dict[str, Any]:
    """Main render_clip entry point (SINGULAR action)."""
    validated_input = _validate_input(contract)

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=ffmpeg_timeout)
    result = renderer.render(validated_input, configuration)

    # Convert to output format
    return {
        "status": "success",
        "render": {
            "algorithm": result.algorithm,  # "vertical"
            "algorithm_version": result.algorithm_version,  # "vertical_v1"
            "parameters": {
                "configuration": result.parameters.configuration,
                "source_media": result.parameters.source_media,
                "ffmpeg_version": result.parameters.ffmpeg_version,
                "filter_graph": result.parameters.filter_graph,
                "limits": result.parameters.limits,
            },
            "clips": [
                {
                    "candidate_index": clip.candidate_index,
                    "semantic_rank": clip.semantic_rank,
                    "semantic_score": clip.semantic_score,
                    "start_ms": clip.start_ms,
                    "end_ms": clip.end_ms,
                    "duration_ms": clip.duration_ms,
                    "output": clip.output,
                }
                for clip in result.clips
            ],
        },
    }