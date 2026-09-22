"""Metadata-only clip analysis acceptance at the existing v1 boundary."""

from __future__ import annotations

import pytest

from aiclip_worker.contracts import validate_contract


@pytest.mark.parametrize(
    "scenes",
    [
        [{"index": 0, "start_ms": 0, "end_ms": 30000}],
        [],
    ],
    ids=["whole-scene", "empty-scenes"],
)
def test_accepts_minimal_scene_only_analyze_clips_contract(
    scenes: list[dict[str, int]],
) -> None:
    """Ready analysis needs timing/configuration, not the legacy storage envelope."""
    contract = {
        "version": "1.0.0",
        "action": "analyze_clips",
        "media": {"duration_ms": 30000},
        "scenes": scenes,
        "configuration": {
            "min_duration_ms": 5000,
            "target_duration_ms": 30000,
            "max_duration_ms": 60000,
            "max_candidates": 20,
            "weights": {
                "duration_fit": 50,
                "speech_coverage": 30,
                "boundary_alignment": 20,
            },
        },
    }

    is_valid, error = validate_contract(contract)

    assert is_valid is True, f"Valid metadata-only analyze_clips rejected: {error}"
    assert error == ""
