"""Transcribe action: supervisor that spawns killable child process."""

from __future__ import annotations

import json
import os
import signal
import subprocess
import sys
import time
from typing import Any


def _run_transcribe_child(contract: dict[str, Any]) -> dict[str, Any]:
    """Run transcription in-process (called by child process)."""
    from aiclip_worker.transcription import get_transcriber, validate_transcript_result

    storage = contract.get("storage", {})
    file_path = storage.get("key", "")

    if not file_path:
        return {
            "status": "error",
            "error": "No storage key provided for transcription",
            "stderr": "",
        }

    if not os.path.isfile(file_path):
        return {
            "status": "error",
            "error": f"No such file: {file_path}",
            "stderr": "",
        }

    try:
        engine_name = os.environ.get("TRANSCRIPTION_ENGINE", "faster_whisper")
        transcriber = get_transcriber(engine_name)
    except (ValueError, ImportError) as e:
        return {
            "status": "error",
            "error": f"Failed to initialize transcription engine: {e}",
            "stderr": "",
        }

    try:
        result = transcriber.transcribe(file_path, {})
        validate_transcript_result(result.segments)
    except Exception as e:
        return {
            "status": "error",
            "error": f"Transcription failed: {e}",
            "stderr": "",
        }

    segments = [
        {"start_ms": seg.start_ms, "end_ms": seg.end_ms, "text": seg.text}
        for seg in result.segments
    ]

    return {
        "status": "success",
        "transcription": {
            "language": result.language,
            "full_text": result.full_text,
            "segments": segments,
            "engine": result.engine,
            "model": result.model,
        },
    }


def _kill_process_group(child: subprocess.Popen) -> None:
    """Kill child process group with bounded teardown.

    Defensive PGID check: only use os.killpg when child's PGID differs
    from the parent's PGID. If they share a PGID, use os.kill() to
    avoid killing unrelated processes in the parent group.
    """
    try:
        child_pgid = os.getpgid(child.pid)
        parent_pgid = os.getpgrp()

        if child_pgid != parent_pgid:
            os.killpg(child_pgid, signal.SIGTERM)
        else:
            os.kill(child.pid, signal.SIGTERM)
    except (ProcessLookupError, PermissionError, OSError):
        pass

    try:
        child.wait(timeout=1)
    except subprocess.TimeoutExpired:
        try:
            child_pgid = os.getpgid(child.pid)
            parent_pgid = os.getpgrp()

            if child_pgid != parent_pgid:
                os.killpg(child_pgid, signal.SIGKILL)
            else:
                os.kill(child.pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError, OSError):
            pass
        try:
            child.wait(timeout=1)
        except subprocess.TimeoutExpired:
            pass


def transcribe(contract: dict[str, Any]) -> dict[str, Any]:
    """Supervisor: spawn child process with bounded timeout.

    Returns JSON error envelope on timeout or child failure.
    Never returns fallback values for malformed fields.
    """
    # Fast validation before spawning child
    storage = contract.get("storage", {})
    file_path = storage.get("key", "")

    if not file_path:
        return {
            "status": "error",
            "error": "No storage key provided for transcription",
            "stderr": "",
        }

    if not os.path.isfile(file_path):
        return {
            "status": "error",
            "error": f"No such file: {file_path}",
            "stderr": "",
        }

    timeout = int(os.environ.get("TRANSCRIBE_TIMEOUT_SECONDS", "300"))
    if timeout <= 0:
        return {
            "status": "error",
            "error": f"Invalid timeout: {timeout}",
            "stderr": "",
        }

    deadline = time.monotonic() + timeout

    child = subprocess.Popen(
        [sys.executable, "-m", "aiclip_worker.cli", "--transcribe-child"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        start_new_session=True,
    )

    contract_bytes = json.dumps(contract).encode()

    try:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            _kill_process_group(child)
            return {"status": "error", "error": f"Transcription timed out after {timeout}s", "stderr": ""}

        stdout_bytes, stderr_bytes = child.communicate(
            input=contract_bytes,
            timeout=remaining,
        )
        stdout = stdout_bytes.decode()
        stderr = stderr_bytes.decode()

    except subprocess.TimeoutExpired:
        _kill_process_group(child)
        return {"status": "error", "error": f"Transcription timed out after {timeout}s", "stderr": ""}
    except Exception as e:
        try:
            _kill_process_group(child)
        except Exception:
            pass
        return {"status": "error", "error": f"Supervisor error: {e}", "stderr": ""}

    if child.returncode != 0:
        try:
            output = json.loads(stdout)
            if isinstance(output, dict) and output.get("status") == "error":
                return output
        except (json.JSONDecodeError, TypeError):
            pass
        return {
            "status": "error",
            "error": f"Transcription failed (exit {child.returncode})",
            "stderr": stderr[:500],
        }

    output = json.loads(stdout)
    if not isinstance(output, dict) or output.get("status") != "success":
        return {
            "status": "error",
            "error": "Invalid child output",
            "stderr": "",
        }

    transcription = output.get("transcription")
    if not isinstance(transcription, dict):
        return {
            "status": "error",
            "error": "Missing transcription in child output",
            "stderr": "",
        }

    for field in ("language", "engine", "model"):
        val = transcription.get(field)
        if not isinstance(val, str) or not val.strip():
            return {
                "status": "error",
                "error": f"Missing or empty {field}",
                "stderr": "",
            }

    if not isinstance(transcription.get("full_text"), str):
        return {
            "status": "error",
            "error": "Missing or invalid full_text",
            "stderr": "",
        }

    if not isinstance(transcription.get("segments"), list):
        return {
            "status": "error",
            "error": "Missing or invalid segments",
            "stderr": "",
        }

    return output
