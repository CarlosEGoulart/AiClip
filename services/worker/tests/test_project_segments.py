"""Tests for project_segments_to_clip_local function.

Validates the pure segment projection transformation per spec.md.
No FFmpeg, database, Laravel integration, persistence, schema, UI, or contract changes.
"""

from __future__ import annotations

import pytest

from aiclip_worker.rendering import project_segments_to_clip_local


class TestProjectSegmentsToClipLocal:
    """Behavioral tests for project_segments_to_clip_local."""

    def test_tp01_normal_projection(self):
        """TP-01: Normal projection within clip bounds."""
        segments = [
            {"start_ms": 11200, "end_ms": 12600, "text": "Hello world"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert len(result) == 1
        assert result[0]["local_start_ms"] == 1200
        assert result[0]["local_end_ms"] == 2600
        assert result[0]["text"] == "Hello world"

    def test_tp02_segment_completely_before_clip(self):
        """TP-02: Segment completely before clip is excluded."""
        segments = [
            {"start_ms": 5000, "end_ms": 6000, "text": "Before clip"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert result == []

    def test_tp03_segment_completely_after_clip(self):
        """TP-03: Segment completely after clip is excluded."""
        segments = [
            {"start_ms": 20000, "end_ms": 21000, "text": "After clip"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert result == []

    def test_tp04_partial_overlap_at_clip_start(self):
        """TP-04: Partial overlap at clip start clamps local_start to 0."""
        segments = [
            {"start_ms": 9000, "end_ms": 11000, "text": "Start overlap"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert len(result) == 1
        assert result[0]["local_start_ms"] == 0
        assert result[0]["local_end_ms"] == 1000
        assert result[0]["text"] == "Start overlap"

    def test_tp05_partial_overlap_at_clip_end(self):
        """TP-05: Partial overlap at clip end clamps local_end to clip_duration."""
        segments = [
            {"start_ms": 14000, "end_ms": 20000, "text": "End overlap"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert len(result) == 1
        assert result[0]["local_start_ms"] == 4000
        assert result[0]["local_end_ms"] == 5000
        assert result[0]["text"] == "End overlap"

    def test_tp06_empty_input(self):
        """TP-06: Empty input produces empty output."""
        segments = []
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert result == []

    def test_tp07_determinism_and_purity(self):
        """TP-07: Repeated calls with identical inputs produce identical output.
        
        No FFmpeg, subprocesses, database access, network access, or logging of transcript contents.
        """
        segments = [
            {"start_ms": 11200, "end_ms": 12600, "text": "Hello world"},
            {"start_ms": 9000, "end_ms": 11000, "text": "Start overlap"},
            {"start_ms": 14000, "end_ms": 20000, "text": "End overlap"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result1 = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)
        result2 = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)
        result3 = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert result1 == result2 == result3
        assert len(result1) == 3

    def test_tp08_multiple_segments_mixed_cases(self):
        """TP-08: Multiple segments with mixed overlap scenarios."""
        segments = [
            {"start_ms": 5000, "end_ms": 6000, "text": "Before clip"},      # Excluded
            {"start_ms": 9000, "end_ms": 11000, "text": "Start overlap"},  # Clamped start
            {"start_ms": 11200, "end_ms": 12600, "text": "Inside clip"},   # Normal
            {"start_ms": 14000, "end_ms": 20000, "text": "End overlap"},   # Clamped end
            {"start_ms": 20000, "end_ms": 21000, "text": "After clip"},    # Excluded
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert len(result) == 3
        # First result: start overlap clamped
        assert result[0]["local_start_ms"] == 0
        assert result[0]["local_end_ms"] == 1000
        assert result[0]["text"] == "Start overlap"
        # Second result: normal projection
        assert result[1]["local_start_ms"] == 1200
        assert result[1]["local_end_ms"] == 2600
        assert result[1]["text"] == "Inside clip"
        # Third result: end overlap clamped
        assert result[2]["local_start_ms"] == 4000
        assert result[2]["local_end_ms"] == 5000
        assert result[2]["text"] == "End overlap"

    def test_edge_case_segment_exactly_at_clip_bounds(self):
        """Edge case: Segment exactly at clip bounds results in zero-length and is filtered."""
        segments = [
            {"start_ms": 10000, "end_ms": 10000, "text": "Zero length at start"},
            {"start_ms": 15000, "end_ms": 15000, "text": "Zero length at end"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert result == []

    def test_edge_case_segment_with_empty_text(self):
        """Edge case: Segment with empty text string is included (text preservation)."""
        segments = [
            {"start_ms": 11000, "end_ms": 12000, "text": ""},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert len(result) == 1
        assert result[0]["local_start_ms"] == 1000
        assert result[0]["local_end_ms"] == 2000
        assert result[0]["text"] == ""

    def test_edge_case_clip_duration_zero(self):
        """Edge case: Zero-duration clip (start == end) filters all segments."""
        segments = [
            {"start_ms": 10000, "end_ms": 11000, "text": "Inside zero clip"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 10000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert result == []

    def test_output_uses_integer_arithmetic_only(self):
        """Verify integer arithmetic only - no floating point in timestamp computation."""
        segments = [
            {"start_ms": 10001, "end_ms": 10003, "text": "Odd ms"},
        ]
        clip_start_ms = 10000
        clip_end_ms = 15000

        result = project_segments_to_clip_local(segments, clip_start_ms, clip_end_ms)

        assert len(result) == 1
        assert isinstance(result[0]["local_start_ms"], int)
        assert isinstance(result[0]["local_end_ms"], int)
        assert result[0]["local_start_ms"] == 1
        assert result[0]["local_end_ms"] == 3