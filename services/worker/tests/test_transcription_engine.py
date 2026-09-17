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

    def test_segment_rejects_negative_start_ms(self) -> None:
        """Segment rejects negative start_ms."""
        with pytest.raises(ValueError, match="start_ms"):
            Segment(start_ms=-1, end_ms=1000, text="test")

    def test_segment_rejects_end_before_start(self) -> None:
        """Segment rejects end_ms < start_ms."""
        with pytest.raises(ValueError, match="end_ms"):
            Segment(start_ms=1000, end_ms=500, text="test")

    def test_segment_rejects_empty_text(self) -> None:
        """Segment rejects empty text."""
        with pytest.raises(ValueError, match="text"):
            Segment(start_ms=0, end_ms=1000, text="")

    def test_segment_rejects_whitespace_only_text(self) -> None:
        """Segment rejects whitespace-only text."""
        with pytest.raises(ValueError, match="text"):
            Segment(start_ms=0, end_ms=1000, text="   ")


class TestTranscriptValidation:
    """Test transcript result validation."""

    def test_transcript_result_rejects_unordered_segments(self) -> None:
        """TranscriptResult rejects segments not ordered by start_ms."""
        from aiclip_worker.transcription import validate_transcript_result
        segments = [
            Segment(start_ms=1000, end_ms=2000, text="second"),
            Segment(start_ms=0, end_ms=1000, text="first"),
        ]
        with pytest.raises(ValueError, match="order"):
            validate_transcript_result(segments)

    def test_transcript_result_rejects_overlapping_segments(self) -> None:
        """TranscriptResult rejects overlapping segments."""
        from aiclip_worker.transcription import validate_transcript_result
        segments = [
            Segment(start_ms=0, end_ms=1500, text="first"),
            Segment(start_ms=1000, end_ms=2000, text="second"),
        ]
        with pytest.raises(ValueError, match="overlap"):
            validate_transcript_result(segments)

    def test_validate_transcript_result_passes_for_valid(self) -> None:
        """validate_transcript_result accepts valid segments."""
        from aiclip_worker.transcription import validate_transcript_result
        segments = [
            Segment(start_ms=0, end_ms=1000, text="first"),
            Segment(start_ms=1000, end_ms=2000, text="second"),
        ]
        validate_transcript_result(segments)  # Should not raise


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

    def test_faster_whisper_transcribe_calls_model_transcribe(self) -> None:
        """FasterWhisperTranscriber.transcribe calls WhisperModel.transcribe."""
        mock_model = MagicMock()
        mock_segment = MagicMock()
        mock_segment.start = 0.0
        mock_segment.end = 1.5
        mock_segment.text = " Hello world "
        mock_model.transcribe.return_value = (
            [mock_segment],
            MagicMock(language="en", language_probability=0.9),
        )

        with patch.dict(os.environ, {
            "WHISPER_MODEL": "base",
            "WHISPER_DEVICE": "cpu",
            "WHISPER_COMPUTE_TYPE": "int8",
        }):
            with patch("aiclip_worker.transcription.FasterWhisperTranscriber._load_model") as mock_load:
                transcriber = FasterWhisperTranscriber()
                transcriber._model = mock_model
                result = transcriber.transcribe("/tmp/test.wav", {"language": "en", "beam_size": 5})

        mock_model.transcribe.assert_called_once_with(
            "/tmp/test.wav", language="en", beam_size=5
        )
        assert result.language == "en"
        assert len(result.segments) == 1
        assert result.segments[0].start_ms == 0
        assert result.segments[0].end_ms == 1500
        assert result.segments[0].text == "Hello world"

    def test_faster_whisper_model_load_is_lazy(self) -> None:
        """FasterWhisperTranscriber does not load model on construction."""
        with patch.dict(os.environ, {
            "WHISPER_MODEL": "base",
            "WHISPER_DEVICE": "cpu",
            "WHISPER_COMPUTE_TYPE": "int8",
        }):
            with patch("aiclip_worker.transcription.FasterWhisperTranscriber._load_model") as mock_load:
                transcriber = FasterWhisperTranscriber()
                mock_load.assert_not_called()

    def test_faster_whisper_missing_dependency_raises_import_error(self) -> None:
        """FasterWhisperTranscriber raises ImportError when faster-whisper missing."""
        transcriber = FasterWhisperTranscriber()
        transcriber._model = None

        with patch.dict("sys.modules", {"faster_whisper": None}):
            with pytest.raises(ImportError, match="faster-whisper"):
                transcriber._load_model()

    def test_faster_whisper_model_error_propagates(self) -> None:
        """FasterWhisperTranscriber propagates model errors."""
        mock_model = MagicMock()
        mock_model.transcribe.side_effect = RuntimeError("Model inference failed")

        transcriber = FasterWhisperTranscriber()
        transcriber._model = mock_model

        with pytest.raises(RuntimeError, match="Model inference failed"):
            transcriber.transcribe("/tmp/test.wav", {})


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
