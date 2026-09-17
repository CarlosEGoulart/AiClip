"""Transcription engine abstraction for audio transcription."""

from __future__ import annotations

import hashlib
import os
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import List


@dataclass
class Segment:
    """A single segment of a transcript with timing information."""

    start_ms: int
    end_ms: int
    text: str

    def __post_init__(self) -> None:
        if not isinstance(self.start_ms, int):
            raise TypeError("start_ms must be an integer")
        if not isinstance(self.end_ms, int):
            raise TypeError("end_ms must be an integer")
        if self.start_ms < 0:
            raise ValueError("start_ms must be >= 0")
        if self.end_ms < self.start_ms:
            raise ValueError("end_ms must be >= start_ms")
        if not isinstance(self.text, str):
            raise TypeError("text must be a string")
        self.text = self.text.strip()
        if not self.text:
            raise ValueError("text must not be empty after trimming")


@dataclass
class TranscriptResult:
    """Result of a transcription operation."""

    language: str
    full_text: str
    segments: List[Segment]
    engine: str
    model: str


class Transcriber(ABC):
    """Abstract base class for transcription engines."""

    @abstractmethod
    def transcribe(self, audio_path: str, options: dict) -> TranscriptResult:
        """Transcribe audio file and return structured result.

        Args:
            audio_path: Path to the audio file.
            options: Additional options for transcription.

        Returns:
            TranscriptResult with language, full_text, segments, engine, model.
        """
        pass


class DeterministicTranscriber(Transcriber):
    """Deterministic transcriber for CI/testing. No model downloads."""

    def transcribe(self, audio_path: str, options: dict) -> TranscriptResult:
        """Return deterministic transcript based on audio file path hash."""
        path_hash = hashlib.sha256(audio_path.encode()).hexdigest()[:16]

        # Generate deterministic segments based on hash
        num_segments = 2 + (int(path_hash[:4], 16) % 3)  # 2-4 segments

        segments: list[Segment] = []
        current_ms = 0
        for i in range(num_segments):
            # Deterministic duration: 800-2400ms per segment
            duration = 800 + (int(path_hash[i * 4 : i * 4 + 4], 16) % 1600)
            text_words = [
                f"word{i * 3 + j}_{path_hash[j * 2 : j * 2 + 2]}"
                for j in range(3)
            ]
            text = " ".join(text_words)
            segments.append(Segment(
                start_ms=current_ms,
                end_ms=current_ms + duration,
                text=text,
            ))
            current_ms += duration

        full_text = " ".join(seg.text for seg in segments)

        result = TranscriptResult(
            language="en",
            full_text=full_text,
            segments=segments,
            engine="deterministic",
            model="deterministic",
        )
        validate_transcript_result(result.segments)

        return result


class FasterWhisperTranscriber(Transcriber):
    """Runtime transcription engine using faster-whisper library."""

    def __init__(self) -> None:
        """Initialize FasterWhisperTranscriber with configuration from environment."""
        self.model_name: str = os.environ.get("WHISPER_MODEL", "base")
        self.device: str = os.environ.get("WHISPER_DEVICE", "cpu")
        self.compute_type: str = os.environ.get("WHISPER_COMPUTE_TYPE", "int8")
        self.model_cache: str = os.environ.get(
            "WHISPER_MODEL_CACHE", os.path.expanduser("~/.cache/whisper")
        )
        self._model = None

    def _load_model(self) -> None:
        """Load the faster-whisper model lazily."""
        if self._model is not None:
            return

        try:
            from faster_whisper import WhisperModel

            self._model = WhisperModel(
                self.model_name,
                device=self.device,
                compute_type=self.compute_type,
                download_root=self.model_cache,
            )
        except ImportError as e:
            raise ImportError(
                "faster-whisper is required for FasterWhisperTranscriber. "
                "Install it with: pip install faster-whisper"
            ) from e

    def transcribe(self, audio_path: str, options: dict) -> TranscriptResult:
        """Transcribe audio using faster-whisper.

        Args:
            audio_path: Path to the audio file.
            options: Additional options (e.g., language, beam_size).

        Returns:
            TranscriptResult with language, full_text, segments, engine, model.
        """
        self._load_model()
        assert self._model is not None

        language = options.get("language")
        beam_size = options.get("beam_size", 5)

        segments_iter, info = self._model.transcribe(
            audio_path,
            language=language,
            beam_size=beam_size,
        )

        segments: list[Segment] = []
        full_text_parts: list[str] = []

        for seg in segments_iter:
            start_ms = int(seg.start * 1000)
            end_ms = int(seg.end * 1000)
            text = seg.text.strip()
            segments.append(Segment(start_ms=start_ms, end_ms=end_ms, text=text))
            full_text_parts.append(text)

        full_text = " ".join(full_text_parts)

        if not info.language or not isinstance(info.language, str) or not info.language.strip():
            raise ValueError("Transcription engine did not detect language")
        language = info.language.strip()
        result = TranscriptResult(
            language=language,
            full_text=full_text,
            segments=segments,
            engine="faster_whisper",
            model=self.model_name,
        )
        validate_transcript_result(result.segments)

        return result


def get_transcriber(engine: str | None = None) -> Transcriber:
    """Factory function to get a transcriber based on engine name.

    Args:
        engine: Engine name ('deterministic' or 'faster_whisper').
                If None, uses TRANSCRIPTION_ENGINE env var.
                Defaults to 'faster_whisper'.

    Returns:
        Transcriber instance.

    Raises:
        ValueError: If engine name is unknown.
    """
    if engine is None:
        engine = os.environ.get("TRANSCRIPTION_ENGINE", "faster_whisper")

    if engine == "deterministic":
        return DeterministicTranscriber()
    elif engine == "faster_whisper":
        return FasterWhisperTranscriber()
    else:
        raise ValueError(f"Unknown transcription engine: {engine}")


def validate_transcript_result(segments: list[Segment]) -> None:
    """Validate that segments are ordered and non-overlapping.

    Args:
        segments: List of Segment objects to validate.

    Raises:
        ValueError: If segments are not ordered by start_ms or overlap.
    """
    for i in range(1, len(segments)):
        if segments[i].start_ms < segments[i - 1].start_ms:
            raise ValueError(
                f"Segments must be ordered by start_ms: "
                f"segment {i} start_ms={segments[i].start_ms} < "
                f"segment {i - 1} start_ms={segments[i - 1].start_ms}"
            )
        if segments[i].start_ms < segments[i - 1].end_ms:
            raise ValueError(
                f"Segments must not overlap: "
                f"segment {i} start_ms={segments[i].start_ms} < "
                f"segment {i - 1} end_ms={segments[i - 1].end_ms}"
            )
