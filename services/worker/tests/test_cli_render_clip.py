"""CLI transport tests for the singular render-clip command."""

from __future__ import annotations

import copy
import hashlib
import json
from io import BytesIO, StringIO
from unittest.mock import MagicMock, patch

from aiclip_worker.actions.render_clip import run_cli
from aiclip_worker.cli import main
from aiclip_worker.rendering import RenderConfiguration, RenderFailed


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
    payload = json.dumps(contract).encode()
    success = {
        "status": "success",
        "render": {
            "algorithm": "ffmpeg_vertical_baseline",
            "algorithm_version": "1.0.0",
            "parameters": {
                "request_sha256": hashlib.sha256(payload).hexdigest()
            },
            "clips": [],
        },
    }
    # Expected transport output: the helper's envelope plus the digest binding
    # computed over the exact raw request bytes (captured before run_cli runs,
    # because run_cli injects into the envelope it emits).
    expected = copy.deepcopy(success)

    with patch("sys.stdin", new=stdin_for(payload)):
        with patch("sys.stdout", new_callable=StringIO) as output:
            with patch("aiclip_worker.actions.render_clip.validate_contract", return_value=(True, "")):
                with patch("aiclip_worker.actions.render_clip.render_clip", return_value=success):
                    exit_code = run_cli([])

    assert exit_code == 0
    # The transport binds every success envelope to the exact raw request bytes.
    assert json.loads(output.getvalue()) == expected


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


# ---------------------------------------------------------------------------
# M6.2 result contract: request digest binding and configuration echo
# ---------------------------------------------------------------------------

def valid_render_clip_contract_with_captions() -> dict:
    contract = valid_render_clip_contract()
    contract["configuration"]["captions"] = {
        "enabled": True,
        "font_file": "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
        "font_size": 72,
        "font_color": "ffffff",
        "outline_color": "000000",
        "outline_width": 3,
        "background_color": "000000",
        "background_opacity": 0.5,
        "box_padding": 10,
        "margin_bottom": 100,
        "max_chars_per_line": 32,
    }
    contract["captions"] = {
        "enabled": True,
        "segments": [{"start_ms": 1500, "end_ms": 8000, "text": "Caption text"}],
    }
    return contract


def renderer_returns(contract: dict) -> tuple[dict, dict]:
    """The (clip_info, parameters) pair the renderer returns.

    parameters.configuration is built with RenderConfiguration.to_dict() on
    purpose: the renderer echoes its own seven-key profile, never the
    request's configuration. The action layer owns the envelope echo.
    """
    start_ms = contract["candidate"]["start_ms"]
    end_ms = contract["candidate"]["end_ms"]
    configuration = contract["configuration"]
    clip_info = {
        "candidate_index": contract["candidate_index"],
        "start_ms": start_ms,
        "end_ms": end_ms,
        "duration_ms": end_ms - start_ms,
        "output": {
            "disk": contract["output_storage"]["disk"],
            "key": contract["output_storage"]["key"],
            "size_bytes": 102400,
            "duration_ms": end_ms - start_ms,
            "width": configuration["target_width"],
            "height": configuration["target_height"],
            "video_codec": "h264",
            "audio_codec": configuration["audio_codec"],
            "video_bitrate_kbps": configuration["video_bitrate_kbps"],
            "audio_bitrate_kbps": configuration["audio_bitrate_kbps"],
            "mime_type": "video/mp4",
        },
    }
    parameters = {
        "configuration": RenderConfiguration.from_dict(configuration).to_dict(),
        "source_media": {
            "disk": contract["source_media"]["disk"],
            "key": contract["source_media"]["key"],
            "duration_ms": contract["media"]["duration_ms"],
            "width": contract["source_media"]["width"],
            "height": contract["source_media"]["height"],
            "video_codec": contract["source_media"]["video_codec"],
            "audio_codec": contract["source_media"]["audio_codec"],
        },
        "ffmpeg_version": "ffmpeg version 6.0",
        "filter_graph": (
            "crop=608:1080:656:0,"
            "scale=1080:1920:force_original_aspect_ratio=decrease,"
            "pad=1080:1920:(ow-iw)/2:(oh-ih)/2,fps=30,"
            "drawtext=text='Caption text'"
        ),
        "limits": {
            "max_recommendations": 1000,
            "max_input_bytes": 8388608,
            "max_duration_ms": 2147483647,
        },
    }
    return clip_info, parameters


def test_success_envelope_binds_request_digest_and_echoes_request_configuration():
    """Real run_cli path: stdin -> validated contract -> renderer -> stdout envelope."""
    contract = valid_render_clip_contract_with_captions()
    payload = json.dumps(contract).encode()
    expected_digest = hashlib.sha256(payload).hexdigest()
    clip_info, parameters = renderer_returns(contract)

    with patch("sys.stdin", new=stdin_for(payload)):
        with patch("sys.stdout", new_callable=StringIO) as output:
            with patch(
                "aiclip_worker.rendering.FFmpegVerticalClipRenderer.render_singular",
                return_value=(clip_info, parameters),
            ):
                exit_code = run_cli([])

    assert exit_code == 0, output.getvalue()
    result = json.loads(output.getvalue())
    assert result["status"] == "success"
    envelope_parameters = result["render"]["parameters"]
    assert set(envelope_parameters) == {
        "configuration",
        "source_media",
        "ffmpeg_version",
        "filter_graph",
        "limits",
        "request_sha256",
    }
    assert envelope_parameters["request_sha256"] == expected_digest
    assert envelope_parameters["configuration"] == contract["configuration"]
    assert envelope_parameters["configuration"]["captions"] == contract["configuration"]["captions"]


def test_success_envelope_echoes_exact_configuration_for_request_without_captions():
    """Requests without captions keep the seven-key echo; behavior is unchanged."""
    contract = valid_render_clip_contract()
    payload = json.dumps(contract).encode()
    expected_digest = hashlib.sha256(payload).hexdigest()
    clip_info, parameters = renderer_returns(contract)

    with patch("sys.stdin", new=stdin_for(payload)):
        with patch("sys.stdout", new_callable=StringIO) as output:
            with patch(
                "aiclip_worker.rendering.FFmpegVerticalClipRenderer.render_singular",
                return_value=(clip_info, parameters),
            ):
                exit_code = run_cli([])

    assert exit_code == 0, output.getvalue()
    result = json.loads(output.getvalue())
    envelope_parameters = result["render"]["parameters"]
    assert envelope_parameters["configuration"] == contract["configuration"]
    assert "captions" not in envelope_parameters["configuration"]
    assert envelope_parameters["request_sha256"] == expected_digest


def test_failed_render_envelope_carries_no_request_digest():
    """A failed render must never carry a digest binding."""
    contract = valid_render_clip_contract_with_captions()
    payload = json.dumps(contract).encode()

    with patch("sys.stdin", new=stdin_for(payload)):
        with patch("sys.stdout", new_callable=StringIO) as output:
            with patch(
                "aiclip_worker.rendering.FFmpegVerticalClipRenderer.render_singular",
                side_effect=RenderFailed("Caption font file not found"),
            ):
                exit_code = run_cli([])

    assert exit_code == 1
    result = json.loads(output.getvalue())
    assert result == {
        "status": "error",
        "code": "render_failed",
        "error": "Clip render failed",
        "stderr": "",
    }
    assert "request_sha256" not in result