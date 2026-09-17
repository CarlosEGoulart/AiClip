"""Tests for the transcription engine abstraction."""

from __future__ import annotations

import hashlib
import os
from pathlib import Path
from unittest.mock import patch

import pytest

from aiclip_worker.transcription import (
    DeterministicTranscriber,
    FasterWhisperTranscriber,
    Segment,
    TranscriptResult,
    Transcriber,
    get_transcriber,
)


class TestSegment:
    """Test Segment dataclass."""

    def test_segment_creation_with_valid_data(self) -> None:
        """Segment stores start_ms, end_ms, and text correctly."""
        segment = Segment(start_ms=0, end_ms=1200, text="Hello world")
        assert segment.start_ms == 0
        assert segment.end_ms == 1200
        assert segment.text == "Hello world"

    def test_segment_start_ms_is_integer(self) -> None:
        """Segment start_ms is an integer."""
        segment = Segment(start_ms=100, end_ms=200, text="test")
        assert isinstance(segment.start_ms, int)

    def test_segment_end_ms_is_integer(self) -> None:
        """Segment end_ms is an integer."""
        segment = Segment(start_ms=100, end_ms=200, text="test")
        assert isinstance(segment.end_ms, int)

    def test_segment_text_is_string(self) -> None:
        """Segment text is a string."""
        segment = Segment(start_ms=100, end_ms=200, text="test")
        assert isinstance(segment.text, str)


class TestTranscriptResult:
    """Test TranscriptResult dataclass."""

    def test_transcript_result_creation_with_valid_data(self) -> None:
        """TranscriptResult stores all fields correctly."""
        segments = [Segment(start_ms=0, end_ms=1000, text="Hello")]
        result = TranscriptResult(
            language="en",
            full_text="Hello",
            segments=segments,
            engine="deterministic",
            model="deterministic",
        )
        assert result.language == "en"
        assert result.full_text == "Hello"
        assert len(result.segments) == 1
        assert result.engine == "deterministic"
        assert result.model == "deterministic"

    def test_transcript_result_segments_is_list_of_segment(self) -> None:
        """TranscriptResult segments is a list of Segment objects."""
        segments = [
            Segment(start_ms=0, end_ms=1000, text="Hello"),
            Segment(start_ms=1000, end_ms=2000, text="World"),
        ]
        result = TranscriptResult(
            language="en",
            full_text="Hello World",
            segments=segments,
            engine="deterministic",
            model="deterministic",
        )
        assert isinstance(result.segments, list)
        for seg in result.segments:
            assert isinstance(seg, Segment)


class TestDeterministicTranscriber:
    """Test DeterministicTranscriber implementation."""

    def test_deterministic_transcriber_returns_deterministic_output(self) -> None:
        """Same audio file path returns identical TranscriptResult."""
        transcriber = DeterministicTranscriber()
        audio_path = "/tmp/test_audio.wav"

        result1 = transcriber.transcribe(audio_path, {})
        result2 = transcriber.transcribe(audio_path, {})

        assert result1.language == result2.language
        assert result1.full_text == result2.full_text
        assert len(result1.segments) == len(result2.segments)
        assert result1.engine == "deterministic"
        assert result1.model == "deterministic"

    def test_deterministic_transcriber_returns_different_output_for_different_input(self) -> None:
        """Different audio file paths produce different transcripts."""
        transcriber = DeterministicTranscriber()

        result1 = transcriber.transcribe("/tmp/audio_a.wav", {})
        result2 = transcriber.transcribe("/tmp/audio_b.wav", {})

        # At least full_text or segments should differ
        assert result1.full_text != result2.full_text or len(result1.segments) != len(result2.segments)

    def test_deterministic_transcriber_segments_format(self) -> None:
        """Segments are ordered by start_ms ascending with valid format."""
        transcriber = DeterministicTranscriber()
        result = transcriber.transcribe("/tmp/test_audio.wav", {})

        assert isinstance(result.segments, list)
        assert len(result.segments) > 0

        for seg in result.segments:
            assert isinstance(seg, Segment)
            assert seg.start_ms >= 0
            assert seg.end_ms >= seg.start_ms
            assert isinstance(seg.text, str)
            assert len(seg.text) > 0

        # Verify ordering
        for i in range(1, len(result.segments)):
            assert result.segments[i].start_ms >= result.segments[i - 1].start_ms

    def test_deterministic_transcriber_language_is_en(self) -> None:
        """DeterministicTranscriber always returns English language."""
        transcriber = DeterministicTranscriber()
        result = transcriber.transcribe("/tmp/test.wav", {})
        assert result.language == "en"


class TestFasterWhisperTranscriber:
    """Test FasterWhisperTranscriber initialization."""

    def test_faster_whisper_transcriber_initialization(self) -> None:
        """FasterWhisperTranscriber stores configuration."""
        with patch.dict(os.environ, {
            "WHISPER_MODEL": "base",
            "WHISPER_DEVICE": "cpu",
            "WHISPER_COMPUTE_TYPE": "int8",
            "WHISPER_MODEL_CACHE": "/tmp/cache",
        }):
            transcriber = FasterWhisperTranscriber()
            assert transcriber.model_name == "base"
            assert transcriber.device == "cpu"
            assert transcriber.compute_type == "int8"
            assert transcriber.model_cache == "/tmp/cache"

    def test_faster_whisper_transcriber_defaults(self) -> None:
        """FasterWhisperTranscriber uses sensible defaults."""
        with patch.dict(os.environ, {}, clear=True):
            transcriber = FasterWhisperTranscriber()
            assert transcriber.model_name == "base"
            assert transcriber.device == "cpu"
            assert transcriber.compute_type == "int8"

    def test_faster_whisper_transcriber_is_transcriber(self) -> None:
        """FasterWhisperTranscriber is a Transcriber."""
        transcriber = FasterWhisperTranscriber()
        assert isinstance(transcriber, Transcriber)


class TestGetTranscriber:
    """Test get_transcriber factory function."""

    def test_get_transcriber_returns_deterministic(self) -> None:
        """get_transcriber returns DeterministicTranscriber for 'deterministic'."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "deterministic"}):
            transcriber = get_transcriber()
            assert isinstance(transcriber, DeterministicTranscriber)

    def test_get_transcriber_returns_faster_whisper(self) -> None:
        """get_transcriber returns FasterWhisperTranscriber for 'faster_whisper'."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "faster_whisper"}):
            transcriber = get_transcriber()
            assert isinstance(transcriber, FasterWhisperTranscriber)

    def test_get_transcriber_defaults_to_faster_whisper(self) -> None:
        """get_transcriber defaults to FasterWhisperTranscriber."""
        with patch.dict(os.environ, {}, clear=True):
            transcriber = get_transcriber()
            assert isinstance(transcriber, FasterWhisperTranscriber)

    def test_get_transcriber_raises_for_unknown(self) -> None:
        """get_transcriber raises ValueError for unknown engine."""
        with patch.dict(os.environ, {"TRANSCRIPTION_ENGINE": "unknown_engine"}):
            with pytest.raises(ValueError, match="Unknown transcription engine"):
                get_transcriber()
