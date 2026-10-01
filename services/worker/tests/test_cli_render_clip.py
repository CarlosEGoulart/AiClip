"""CLI transport tests for the singular render-clip command."""

from __future__ import annotations

import json
from io import BytesIO, StringIO
from unittest.mock import MagicMock, patch

from aiclip_worker.actions.render_clip import run_cli
from aiclip_worker.cli import main


def valid_render_clip_contract() -> dict:
    return {
        "version": "1.0.0",
        "action": "render_clip",
        "media": {"duration_ms": 30000},
        "candidate_index": 2,
        "candidate": {"start_ms": 1000, "end_ms": 9000},
        "configuration": {
            "target_width": 1080,
            "target_height": 1920,
            "target_fps": 30,
            "video_codec": "libx264",
            "video_bitrate_kbps": 5000,
            "audio_codec": "aac",
            "audio_bitrate_kbps": 128,
        },
        "source_media": {
            "disk": "media",
            "key": "projects/7/assets/42/source.mp4",
            "width": 1920,
            "height": 1080,
            "video_codec": "h264",
            "audio_codec": "aac",
        },
        "output_storage": {
            "disk": "media",
            "key": "projects/7/renders/42/2/vertical_v1/550e8400-e29b-41d4-a716-446655440000.mp4",
            "mime_type": "video/mp4",
        },
    }


def stdin_for(payload: bytes) -> MagicMock:
    stream = MagicMock()
    stream.buffer = BytesIO(payload)
    stream.isatty.return_value = False
    return stream


def test_cli_router_exposes_singular_render_clip_command():
    with patch("aiclip_worker.actions.render_clip.run_cli", return_value=7) as delegated:
        assert main(["render-clip"]) == 7
        delegated.assert_called_once_with([])


def test_invalid_json_is_sanitized_invalid_contract():
    with patch("sys.stdin", new=stdin_for(b"not-json")):
        with patch("sys.stdout", new_callable=StringIO) as output:
            exit_code = run_cli([])

    assert exit_code == 2
    assert json.loads(output.getvalue()) == {
        "status": "error",
        "code": "invalid_contract",
        "error": "Invalid render contract",
        "stderr": "",
    }


def test_missing_candidate_index_is_not_defaulted():
    contract = valid_render_clip_contract()
    del contract["candidate_index"]

    with patch("sys.stdin", new=stdin_for(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=StringIO) as output:
            exit_code = run_cli([])

    assert exit_code == 2
    result = json.loads(output.getvalue())
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"


def test_valid_stdin_contract_can_complete_through_execution_seam():
    contract = valid_render_clip_contract()
    success = {
        "status": "success",
        "render": {
            "algorithm": "ffmpeg_vertical_baseline",
            "algorithm_version": "1.0.0",
            "parameters": {},
            "clips": [],
        },
    }

    with patch("sys.stdin", new=stdin_for(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=StringIO) as output:
            with patch("aiclip_worker.actions.render_clip.validate_contract", return_value=(True, "")):
                with patch("aiclip_worker.actions.render_clip.render_clip", return_value=success):
                    exit_code = run_cli([])

    assert exit_code == 0
    assert json.loads(output.getvalue()) == success


def test_unmocked_valid_request_fails_closed_before_stage_c():
    """Prove that an unmocked valid request cannot falsely report success before Stage C."""
    contract = valid_render_clip_contract()

    with patch("sys.stdin", new=stdin_for(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=StringIO) as output:
            with patch("aiclip_worker.actions.render_clip.validate_contract", return_value=(True, "")):
                # Do NOT patch render_clip - test the real implementation
                exit_code = run_cli([])

    # Must fail (exit code 1 for render_failed), never succeed (exit code 0)
    assert exit_code == 1
    result = json.loads(output.getvalue())
    assert result["status"] == "error"
    assert result["code"] == "render_failed"
    assert result["error"] == "Clip render failed"
    assert result["stderr"] == ""
    assert "Stage C required" not in result["error"]
    assert "Rendering not implemented" not in result["error"]