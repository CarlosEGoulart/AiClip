"""Vertical clip rendering with FFmpeg."""

from __future__ import annotations

import json
import math
import os
import shutil
import subprocess
import tempfile
import time
import uuid
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
    """Validated input for rendering."""
    duration_ms: int
    recommendation: dict[str, Any]
    candidate_index: int
    source_media: SourceMediaInfo
    media_asset_id: str
    recommendation_id: str


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
    """Render result."""
    algorithm: str = "ffmpeg_vertical_baseline"
    algorithm_version: str = "1.0.0"
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

    def _build_filter_graph_core(
        self,
        config: RenderConfiguration,
        source_width: int,
        source_height: int,
        caption_file: str | None = None,
    ) -> str:
        """Build the FFmpeg filter graph for vertical reframe."""
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

        # Optional subtitles filter after fps, before encode
        if caption_file is not None:
            filter_parts.append(f"subtitles={caption_file}")

        return ",".join(filter_parts)

    def _build_filter_graph(
        self,
        candidate,
        config: RenderConfiguration,
        source_width: int,
        source_height: int,
        caption_file: str | None = None,
    ) -> str:
        return self._build_filter_graph_core(config, source_width, source_height, caption_file)

    def _run_ffmpeg(
        self,
        input_path: str,
        output_path: str,
        filter_graph: str,
        config: RenderConfiguration,
        start_s: float,
        duration_s: float,
        has_audio: bool,
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
        ]
        if has_audio:
            cmd.extend([
                "-c:a", config.audio_codec,
                "-b:a", f"{config.audio_bitrate_kbps}k",
            ])
        else:
            cmd.append("-an")
        cmd.extend([
            "-movflags", "+faststart",
            output_path,
        ])

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
        pix_fmt = None
        for stream in probe_data.get("streams", []):
            if stream.get("codec_type") == "video" and video_stream is None:
                video_stream = stream
                pix_fmt = stream.get("pix_fmt")
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
            "pix_fmt": pix_fmt,
        }

    def render(self, validated_input, configuration):
        """Render the vertical clip."""
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

        # Build output key
        timestamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        output_key = f"renders/{validated_input.media_asset_id}/{validated_input.recommendation_id}/{candidate.index}_{timestamp}.mp4"

        # Get source media path from storage (Flysystem would be used in real implementation)
        # For now, assume local file path based on disk/key
        # In real implementation, Laravel would download from S3 to a temp file and pass the path
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
            has_audio = validated_input.source_media.audio_codec is not None
            output_meta = self._run_ffmpeg(
                input_path,
                temp_output_path,
                filter_graph,
                configuration,
                start_s,
                duration_s,
                has_audio,
            )

            # Verify output
            if output_meta["size_bytes"] <= 0:
                raise RenderFailed("Output file is empty")
            if output_meta["duration_ms"] <= 0:
                raise RenderFailed("Output duration is invalid")
            if output_meta["width"] != configuration.target_width or output_meta["height"] != configuration.target_height:
                raise RenderFailed("Output resolution does not match configuration")
            # Check pix_fmt
            if output_meta.get("pix_fmt") != "yuv420p":
                raise RenderFailed(f"Output pix_fmt is not yuv420p: {output_meta.get('pix_fmt')}")
            # Check video codec: actual should be h264 (since we use libx264)
            if output_meta["video_codec"] != "h264":
                raise RenderFailed(f"Output video codec is not h264: {output_meta['video_codec']}")
            # Check audio: if source has audio, output must have aac; if source has no audio, output must have no audio
            if validated_input.source_media.audio_codec is not None:
                if output_meta["audio_codec"] != "aac":
                    raise RenderFailed(f"Output audio codec is not aac: {output_meta['audio_codec']}")
            else:
                if output_meta["audio_codec"] != "":
                    raise RenderFailed(f"Output audio codec should be empty but got: {output_meta['audio_codec']}")

            # Check duration accuracy
            expected_duration_ms = candidate.end_ms - candidate.start_ms
            actual_duration_ms = output_meta["duration_ms"]
            if abs(actual_duration_ms - expected_duration_ms) > 50:
                raise RenderFailed(f"Output duration mismatch: expected {expected_duration_ms}ms, got {actual_duration_ms}ms")

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
                    "key": output_key,
                    "size_bytes": output_meta["size_bytes"],
                    "duration_ms": output_meta["duration_ms"],
                    "width": output_meta["width"],
                    "height": output_meta["height"],
                    "video_codec": output_meta["video_codec"],
                    "audio_codec": output_meta["audio_codec"],
                    "video_bitrate_kbps": output_meta["video_bitrate_kbps"],
                    "audio_bitrate_kbps": output_meta["audio_bitrate_kbps"],
                    "mime_type": "video/mp4",
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

    def render_singular(self, duration_ms: int, source_media: SourceMediaInfo, start_ms: int, end_ms: int, configuration: RenderConfiguration, output_key: str, output_disk: str, candidate_index: int, caption_file: str | None = None) -> dict[str, Any]:
        """Render a singular vertical clip given explicit timing parameters."""
        # Validate timing bounds
        if start_ms < 0 or start_ms > duration_ms:
            raise InvalidCandidateIndex("start_ms out of range")
        if end_ms <= start_ms or end_ms > duration_ms:
            raise InvalidCandidateIndex("end_ms invalid")

        # Probe source media to verify metadata and ensure compatibility
        probe_data = self._probe_source_media(source_media.key)
        # Extract video and audio streams
        video_stream = None
        audio_stream = None
        for stream in probe_data.get("streams", []):
            if stream.get("codec_type") == "video" and video_stream is None:
                video_stream = stream
            elif stream.get("codec_type") == "audio" and audio_stream is None:
                audio_stream = stream

        if video_stream is None:
            raise RenderFailed("No video stream found in source media")

        probed_width = video_stream.get("width", 0)
        probed_height = video_stream.get("height", 0)
        probed_video_codec = video_stream.get("codec_name", "")
        probed_audio_codec = audio_stream.get("codec_name") if audio_stream else None

        # Validate probed dimensions and codecs match source_media contract
        if probed_width != source_media.width:
            raise RenderFailed(f"Source media width mismatch: expected {source_media.width}, got {probed_width}")
        if probed_height != source_media.height:
            raise RenderFailed(f"Source media height mismatch: expected {source_media.height}, got {probed_height}")
        if probed_video_codec != source_media.video_codec:
            raise RenderFailed(f"Source media video codec mismatch: expected {source_media.video_codec}, got {probed_video_codec}")
        if source_media.audio_codec is not None:
            if probed_audio_codec != source_media.audio_codec:
                raise RenderFailed(f"Source media audio codec mismatch: expected {source_media.audio_codec}, got {probed_audio_codec}")
        else:
            if probed_audio_codec is not None:
                raise RenderFailed(f"Source media expected no audio stream, but got audio codec: {probed_audio_codec}")

        # Build filter graph using source media dimensions (from source_media, which should match probed)
        filter_graph = self._build_filter_graph(
            None,
            configuration,
            source_media.width,
            source_media.height,
            caption_file=caption_file,
        )

        # Calculate timing
        start_s = start_ms / 1000.0
        duration_s = (end_ms - start_ms) / 1000.0

        # Create isolated temporary directory under /tmp/renders/{uuid}
        temp_dir = Path("/tmp/renders") / uuid.uuid4().hex
        temp_dir.mkdir(parents=True, exist_ok=True)
        temp_output_path = temp_dir / "temp.mp4"
        final_output_path = Path(output_key)
        try:
            # Run FFmpeg to temporary file
            has_audio = source_media.audio_codec is not None
            self._run_ffmpeg(
                source_media.key,  # input_path
                str(temp_output_path),
                filter_graph,
                configuration,
                start_s,
                duration_s,
                has_audio,
            )

            # Validate temporary file
            if not temp_output_path.exists():
                raise RenderFailed("Temporary output file not found")
            temp_size = temp_output_path.stat().st_size
            if temp_size <= 0:
                raise RenderFailed("Temporary output file is empty")

            # Probe temporary file for basic validation (we'll do full validation on final file)
            try:
                temp_result = subprocess.run(
                    [
                        "ffprobe", "-v", "quiet", "-print_format", "json",
                        "-show_streams", "-show_format", str(temp_output_path)
                    ],
                    capture_output=True,
                    text=True,
                    timeout=30,
                )
                if temp_result.returncode != 0:
                    raise RenderFailed("Failed to probe temporary output file")
                temp_probe_data = json.loads(temp_result.stdout)
            except (json.JSONDecodeError, subprocess.TimeoutExpired):
                raise RenderFailed("Failed to probe temporary output file")

            # Extract video stream from temp probe
            temp_video_stream = None
            for stream in temp_probe_data.get("streams", []):
                if stream.get("codec_type") == "video" and temp_video_stream is None:
                    temp_video_stream = stream

            if temp_video_stream is None:
                raise RenderFailed("No video stream found in temporary output")

            # Prepare final output path
            try:
                final_output_path.parent.mkdir(parents=True, exist_ok=True)
            except Exception as e:
                raise RenderFailed(f"Failed to create final output directory: {e}")

            # Move temporary file to final location
            try:
                os.replace(str(temp_output_path), str(final_output_path))
            except Exception as e:
                raise RenderFailed(f"Failed to move temporary file to final location: {e}")

            # Validate final file
            if not final_output_path.exists():
                raise RenderFailed("Final output file not found after move")
            final_size = final_output_path.stat().st_size
            if final_size <= 0:
                # Clean up final partial file
                try:
                    final_output_path.unlink(missing_ok=True)
                except Exception:
                    pass
                raise RenderFailed("Final output file is empty after move")

            # Probe final file for validation
            try:
                final_result = subprocess.run(
                    [
                        "ffprobe", "-v", "quiet", "-print_format", "json",
                        "-show_streams", "-show_format", str(final_output_path)
                    ],
                    capture_output=True,
                    text=True,
                    timeout=30,
                )
                if final_result.returncode != 0:
                    # Clean up final partial file
                    try:
                        final_output_path.unlink(missing_ok=True)
                    except Exception:
                        pass
                    raise RenderFailed("Failed to probe final output file")
                final_probe_data = json.loads(final_result.stdout)
            except (json.JSONDecodeError, subprocess.TimeoutExpired):
                # Clean up final partial file
                try:
                    final_output_path.unlink(missing_ok=True)
                except Exception:
                    pass
                raise RenderFailed("Failed to probe final output file")

            # Extract video and audio streams from final probe
            final_video_stream = None
            final_audio_stream = None
            for stream in final_probe_data.get("streams", []):
                if stream.get("codec_type") == "video" and final_video_stream is None:
                    final_video_stream = stream
                elif stream.get("codec_type") == "audio" and final_audio_stream is None:
                    final_audio_stream = stream

            if final_video_stream is None:
                # Clean up final partial file
                try:
                    final_output_path.unlink(missing_ok=True)
                except Exception:
                    pass
                raise RenderFailed("No video stream found in final output")

            # Extract metadata
            final_duration_ms = 0
            if final_video_stream and "duration" in final_video_stream:
                final_duration_ms = int(float(final_video_stream["duration"]) * 1000)
            elif "duration" in final_probe_data.get("format", {}):
                final_duration_ms = int(float(final_probe_data["format"]["duration"]) * 1000)

            final_width = final_video_stream.get("width", 0) if final_video_stream else 0
            final_height = final_video_stream.get("height", 0) if final_video_stream else 0
            final_video_codec = final_video_stream.get("codec_name", "") if final_video_stream else ""
            final_audio_codec = final_audio_stream.get("codec_name", "") if final_audio_stream else ""

            # Get bitrates
            video_bitrate = configuration.video_bitrate_kbps
            audio_bitrate = configuration.audio_bitrate_kbps
            if final_video_stream and "bit_rate" in final_video_stream:
                try:
                    video_bitrate = int(int(final_video_stream["bit_rate"]) / 1000)
                except (ValueError, TypeError):
                    pass
            if final_audio_stream and "bit_rate" in final_audio_stream:
                try:
                    audio_bitrate = int(int(final_audio_stream["bit_rate"]) / 1000)
                except (ValueError, TypeError):
                    pass

            # Get file size (already have final_size)
            size_bytes = final_size

            # Validate final output
            if final_width != configuration.target_width or final_height != configuration.target_height:
                # Clean up final partial file
                try:
                    final_output_path.unlink(missing_ok=True)
                except Exception:
                    pass
                raise RenderFailed("Final output resolution does not match configuration")
            if final_probe_data.get("streams", []):
                for stream in final_probe_data.get("streams", []):
                    if stream.get("codec_type") == "video":
                        if stream.get("pix_fmt") != "yuv420p":
                            # Clean up final partial file
                            try:
                                final_output_path.unlink(missing_ok=True)
                            except Exception:
                                pass
                            raise RenderFailed(f"Final output pix_fmt is not yuv420p: {stream.get('pix_fmt')}")
                        break
            if final_video_codec != "h264":
                # Clean up final partial file
                try:
                    final_output_path.unlink(missing_ok=True)
                except Exception:
                    pass
                raise RenderFailed(f"Final output video codec is not h264: {final_video_codec}")
            if source_media.audio_codec is not None:
                if final_audio_codec != "aac":
                    # Clean up final partial file
                    try:
                        final_output_path.unlink(missing_ok=True)
                    except Exception:
                        pass
                    raise RenderFailed(f"Final output audio codec is not aac: {final_audio_codec}")
            else:
                if final_audio_codec != "":
                    # Clean up final partial file
                    try:
                        final_output_path.unlink(missing_ok=True)
                    except Exception:
                        pass
                    raise RenderFailed(f"Final output audio codec should be empty but got: {final_audio_codec}")

            # Check duration accuracy on final file
            expected_duration_ms = end_ms - start_ms
            if abs(final_duration_ms - expected_duration_ms) > 50:
                # Clean up final partial file
                try:
                    final_output_path.unlink(missing_ok=True)
                except Exception:
                    pass
                raise RenderFailed(f"Final output duration mismatch: expected {expected_duration_ms}ms, got {final_duration_ms}ms")

            # Get FFmpeg version
            ffmpeg_version = self._get_ffmpeg_version()

            # Build clip info dict
            clip_info = {
                "candidate_index": candidate_index,
                "start_ms": start_ms,
                "end_ms": end_ms,
                "duration_ms": expected_duration_ms,  # use expected duration for consistency
                "output": {
                    "disk": output_disk,
                    "key": output_key,
                    "size_bytes": size_bytes,
                    "duration_ms": final_duration_ms,
                    "width": final_width,
                    "height": final_height,
                    "video_codec": configuration.video_codec,  # logical codec
                    "audio_codec": configuration.audio_codec if source_media.audio_codec is not None else None,
                    "video_bitrate_kbps": video_bitrate,
                    "audio_bitrate_kbps": audio_bitrate,
                    "mime_type": "video/mp4",
                    # mime_type removed per spec
                },
            }

            # Build parameters dict
            parameters = {
                "configuration": configuration.to_dict(),
                "source_media": {
                    "disk": source_media.disk,
                    "key": source_media.key,
                    "duration_ms": duration_ms,
                    "width": source_media.width,
                    "height": source_media.height,
                    "video_codec": source_media.video_codec,
                    "audio_codec": source_media.audio_codec,
                },
                "ffmpeg_version": ffmpeg_version,
                "filter_graph": filter_graph,
                "limits": {
                    "max_recommendations": MAX_RECOMMENDATIONS,
                    "max_input_bytes": MAX_INPUT_BYTES,
                    "max_duration_ms": MAX_DURATION_MS,
                },
            }

            return (clip_info, parameters)

        except Exception as e:
            # If we have a final output path and it exists, clean it up (because validation failed after move)
            if 'final_output_path' in locals() and final_output_path.exists():
                try:
                    final_output_path.unlink()
                except Exception:
                    pass
            # Re-raise as RenderFailed if it's not already
            if isinstance(e, RenderFailed):
                raise
            else:
                raise RenderFailed(str(e))
        finally:
            # Clean up temp directory
            try:
                shutil.rmtree(temp_dir, ignore_errors=True)
            except Exception:
                pass


def _validate_input(contract: dict[str, Any]) -> RenderInput:
    """Validate and convert contract to RenderInput."""
    # This is called after contract validation, so we assume basic structure is valid
    media = contract["media"]
    recommendation = contract["recommendation"]
    candidate_index = contract["candidate_index"]
    source_media = contract["source_media"]
    media_asset_id = contract["media_asset_id"]
    recommendation_id = contract["recommendation_id"]

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
        media_asset_id=media_asset_id,
        recommendation_id=recommendation_id,
    )


def render_clips(contract: dict[str, Any], configuration: RenderConfiguration, ffmpeg_timeout: int) -> dict[str, Any]:
    """Main render_clips entry point."""
    validated_input = _validate_input(contract)

    renderer = FFmpegVerticalClipRenderer(ffmpeg_timeout=ffmpeg_timeout)
    result = renderer.render(validated_input, configuration)

    # Convert to output format
    return {
        "status": "success",
        "render": {
            "algorithm": result.algorithm,
            "algorithm_version": result.algorithm_version,
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