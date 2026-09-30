"""CLI transport tests for render-clips action."""

from __future__ import annotations

import json
import sys
from io import BytesIO
from unittest.mock import patch, MagicMock

import pytest

from aiclip_worker.actions.render_clips import run_cli, _read_stdin, _emit, error


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


def valid_render_contract() -> dict:
    """A valid render_clips contract."""
    return {
        "version": "1.0.0",
        "action": "render_clips",
        "media": {"duration_ms": 30000},
        "recommendation": {
            "candidates": [
                {
                    "index": 0,
                    "start_ms": 0,
                    "end_ms": 10000,
                    "semantic_rank": 1,
                    "semantic_score": 0.95,
                },
                {
                    "index": 1,
                    "start_ms": 10000,
                    "end_ms": 20000,
                    "semantic_rank": 2,
                    "semantic_score": 0.75,
                },
            ],
            "candidate_index": 0,
        },
        "candidate_index": 0,
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
            "key": "projects/1/assets/1/source.mp4",
            "width": 1920,
            "height": 1080,
            "video_codec": "h264",
            "audio_codec": "aac",
        },
        "media_asset_id": 1,
        "recommendation_id": 1,
    }


# ---------------------------------------------------------------------------
# CLI Transport tests (TC-CLI-01 through TC-CLI-07)
# ---------------------------------------------------------------------------


def test_valid_stdin_json_stdout_json_exit_0():
    """Valid stdin JSON -> stdout JSON, exit 0."""
    contract = valid_render_contract()

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            with patch("aiclip_worker.actions.render_clips.validate_contract") as mock_validate:
                mock_validate.return_value = (True, "")
                with patch("aiclip_worker.actions.render_clips.render_clips") as mock_render:
                    mock_render.return_value = {
                        "status": "success",
                        "render": {
                            "algorithm": "ffmpeg_vertical_baseline",
                            "algorithm_version": "1.0.0",
                            "parameters": {
                                "configuration": contract["configuration"],
                                "source_media": contract["source_media"],
                                "ffmpeg_version": "ffmpeg version 6.0",
                                "filter_graph": "crop=ih*9/16:ih:(iw-ih*9/16)/2:0,scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30",
                                "limits": {
                                    "max_recommendations": 1000,
                                    "max_input_bytes": 8388608,
                                    "max_duration_ms": 2147483647,
                                },
                                "request_sha256": "a" * 64,
                            },
                            "clips": [
                                {
                                    "candidate_index": 0,
                                    "semantic_rank": 1,
                                    "semantic_score": 0.95,
                                    "start_ms": 0,
                                    "end_ms": 10000,
                                    "duration_ms": 10000,
                                    "output": {
                                        "disk": "media",
                                        "key": "renders/media/projects/1/assets/1/source.mp4/0_20260101T000000Z.mp4",
                                        "size_bytes": 1024000,
                                        "duration_ms": 10000,
                                        "width": 1080,
                                        "height": 1920,
                                        "video_codec": "libx264",
                                        "audio_codec": "aac",
                                        "video_bitrate_kbps": 5000,
                                        "audio_bitrate_kbps": 128,
                                    },
                                }
                            ],
                        },
                    }

                    exit_code = run_cli([])

    assert exit_code == 0
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "success"
    assert "render" in result


def test_invalid_json_on_stdin_exit_2():
    """Invalid JSON on stdin -> exit 2, error envelope."""
    invalid_json = b"not valid json"

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(invalid_json)):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            exit_code = run_cli([])

    assert exit_code == 2
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"
    assert result["error"] == "Invalid render contract"


def test_invalid_contract_missing_field_exit_2():
    """Invalid contract (missing field) -> exit 2."""
    contract = valid_render_contract()
    del contract["version"]

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            with patch("aiclip_worker.actions.render_clips.validate_contract") as mock_validate:
                mock_validate.return_value = (False, "missing required fields: version")

                exit_code = run_cli([])

    assert exit_code == 2
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"


def test_runtime_error_ffmpeg_fail_exit_1():
    """Runtime error (FFmpeg fail) -> exit 1."""
    contract = valid_render_contract()

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            with patch("aiclip_worker.actions.render_clips.validate_contract") as mock_validate:
                mock_validate.return_value = (True, "")
                with patch("aiclip_worker.actions.render_clips.render_clips") as mock_render:
                    from aiclip_worker.rendering import RenderFailed
                    mock_render.side_effect = RenderFailed("FFmpeg failed")

                    exit_code = run_cli([])

    assert exit_code == 1
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "render_failed"
    assert result["error"] == "Clip render failed"


def test_output_bounded_no_unbounded_stdout_stderr():
    """Output bounded (no unbounded stdout/stderr leak)."""
    contract = valid_render_contract()

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            with patch("aiclip_worker.actions.render_clips.validate_contract") as mock_validate:
                mock_validate.return_value = (True, "")
                with patch("aiclip_worker.actions.render_clips.render_clips") as mock_render:
                    # Return a very large result
                    mock_render.return_value = {
                        "status": "success",
                        "render": {
                            "algorithm": "ffmpeg_vertical_baseline",
                            "algorithm_version": "1.0.0",
                            "parameters": {},
                            "clips": [{"x": "y" * 2000000}],  # Large output
                        },
                    }

                    exit_code = run_cli([])

    # Should still exit 1 because output too large triggers error envelope
    assert exit_code == 1
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "render_failed"


def test_nan_in_output_rejected_before_emit():
    """NaN in output -> rejected before emit, exit 1."""
    contract = valid_render_contract()

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            with patch("aiclip_worker.actions.render_clips.validate_contract") as mock_validate:
                mock_validate.return_value = (True, "")
                with patch("aiclip_worker.actions.render_clips.render_clips") as mock_render:
                    # Return result with NaN
                    mock_render.return_value = {
                        "status": "success",
                        "render": {
                            "algorithm": "ffmpeg_vertical_baseline",
                            "algorithm_version": "1.0.0",
                            "parameters": {},
                            "clips": [{"semantic_score": float("nan")}],
                        },
                    }

                    exit_code = run_cli([])

    assert exit_code == 1
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "render_failed"


def test_input_size_above_8mb_exit_2():
    """Input size > 8MB -> exit 2."""
    contract = valid_render_contract()
    contract["huge_field"] = "x" * (9 * 1024 * 1024)

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            exit_code = run_cli([])

    assert exit_code == 2
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"


# ---------------------------------------------------------------------------
# Argument parsing rejection (no argument-list transport)
# ---------------------------------------------------------------------------


def test_arguments_rejected():
    """Any command-line arguments rejected without echoing payload."""
    contract = valid_render_contract()

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(json.dumps(contract).encode())):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            with patch("aiclip_worker.actions.render_clips.validate_contract") as mock_validate:
                mock_validate.return_value = (True, "")
                with patch("aiclip_worker.actions.render_clips.render_clips") as mock_render:
                    mock_render.return_value = {
                        "status": "success",
                        "render": {
                            "algorithm": "ffmpeg_vertical_baseline",
                            "algorithm_version": "1.0.0",
                            "parameters": {},
                            "clips": [],
                        },
                    }

                    exit_code = run_cli(["--some-arg"])

    # Argument parsing error should result in invalid_contract
    assert exit_code == 2
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"


def test_tty_stdin_rejected():
    """TTY stdin (no input) rejected."""
    with patch("sys.stdin.isatty", return_value=True):
        with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
            exit_code = run_cli([])

    assert exit_code == 2
    output = mock_stdout.getvalue().decode()
    result = json.loads(output)
    assert result["status"] == "error"
    assert result["code"] == "invalid_contract"


# ---------------------------------------------------------------------------
# Error envelope format
# ---------------------------------------------------------------------------


def test_error_envelope_format():
    """Error envelope has correct format."""
    err = error("invalid_contract")

    assert err["status"] == "error"
    assert err["code"] == "invalid_contract"
    assert err["error"] == "Invalid render contract"
    assert err["stderr"] == ""

    err = error("render_failed")
    assert err["code"] == "render_failed"
    assert err["error"] == "Clip render failed"


def test_emit_success():
    """_emit writes success envelope and returns 0."""
    result = {"status": "success", "data": "test"}

    with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
        exit_code = _emit(result)

    assert exit_code == 0
    output = mock_stdout.getvalue().decode()
    parsed = json.loads(output)
    assert parsed == result


def test_emit_invalid_contract():
    """_emit writes invalid_contract envelope and returns 2."""
    result = error("invalid_contract")

    with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
        exit_code = _emit(result)

    assert exit_code == 2
    output = mock_stdout.getvalue().decode()
    parsed = json.loads(output)
    assert parsed == result


def test_emit_render_failed():
    """_emit writes render_failed envelope and returns 1."""
    result = error("render_failed")

    with patch("sys.stdout", new_callable=lambda: BytesIO()) as mock_stdout:
        exit_code = _emit(result)

    assert exit_code == 1
    output = mock_stdout.getvalue().decode()
    parsed = json.loads(output)
    assert parsed == result


# ---------------------------------------------------------------------------
# JSON strictness
# ---------------------------------------------------------------------------


def test_json_rejects_nan_constant():
    """JSON constant NaN rejected."""
    from aiclip_worker.actions.render_clips import _reject_constant

    with pytest.raises(ValueError, match="JSON constant not allowed"):
        _reject_constant("NaN")

    with pytest.raises(ValueError, match="JSON constant not allowed"):
        _reject_constant("Infinity")

    with pytest.raises(ValueError, match="JSON constant not allowed"):
        _reject_constant("-Infinity")


def test_json_rejects_non_finite_float():
    """Non-finite float rejected."""
    from aiclip_worker.actions.render_clips import _finite_float

    with pytest.raises(ValueError, match="Non-finite float not allowed"):
        _finite_float(float("nan"))

    with pytest.raises(ValueError, match="Non-finite float not allowed"):
        _finite_float(float("inf"))

    with pytest.raises(ValueError, match="Non-finite float not allowed"):
        _finite_float(float("-inf"))

    # Finite values accepted
    assert _finite_float(1.5) == 1.5
    assert _finite_float(0) == 0.0


def test_json_rejects_duplicate_keys():
    """Duplicate keys in JSON object rejected."""
    from aiclip_worker.actions.render_clips import _unique_object

    with pytest.raises(ValueError, match="Duplicate key in JSON object"):
        _unique_object([("a", 1), ("a", 2)])

    # Unique keys accepted
    result = _unique_object([("a", 1), ("b", 2)])
    assert result == {"a": 1, "b": 2}


# ---------------------------------------------------------------------------
# Input reading
# ---------------------------------------------------------------------------


def test_read_stdin_with_buffer():
    """_read_stdin reads from stdin.buffer."""
    test_data = b"test input data"

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(test_data)):
        with patch("sys.stdin.isatty", return_value=False):
            result = _read_stdin()

    assert result == test_data


def test_read_stdin_without_buffer():
    """_read_stdin falls back to text mode."""
    test_data = "test input data"

    with patch("sys.stdin.buffer", None):
        with patch("sys.stdin.read", return_value=test_data):
            with patch("sys.stdin.isatty", return_value=False):
                result = _read_stdin()

    assert result == test_data.encode("utf-8")


def test_read_stdin_respects_max_size():
    """_read_stdin reads at most MAX_INPUT_BYTES + 1."""
    from aiclip_worker.actions.render_clips import MAX_INPUT_BYTES

    large_data = b"x" * (MAX_INPUT_BYTES + 100)

    with patch("sys.stdin.buffer", new_callable=lambda: BytesIO(large_data)):
        with patch("sys.stdin.isatty", return_value=False):
            result = _read_stdin()

    # Should read MAX_INPUT_BYTES + 1 bytes
    assert len(result) == MAX_INPUT_BYTES + 1