"""Detect scenes action: supervisor that spawns killable child process."""

from __future__ import annotations

import json
import os
import signal
import subprocess
import sys
import time
from typing import Any


def _run_detect_scenes_child(contract: dict[str, Any]) -> dict[str, Any]:
    """Run scene detection in-process (called by child process)."""
    from aiclip_worker.scene_detection import get_scene_detector, validate_scene_result

    storage = contract.get("storage", {})
    file_path = storage.get("key", "")

    if not file_path:
        return {
            "status": "error",
            "error": "No storage key provided for scene detection",
            "stderr": "",
        }

    if not os.path.isfile(file_path):
        return {
            "status": "error",
            "error": f"No such file: {file_path}",
            "stderr": "",
        }

    try:
        engine_name = os.environ.get("SCENE_DETECTION_ENGINE", "deterministic")
        detector = get_scene_detector(engine_name)
    except (ValueError, ImportError) as e:
        return {
            "status": "error",
            "error": f"Failed to initialize scene detection engine: {e}",
            "stderr": "",
        }

    try:
        media = contract.get("media", {})
        options: dict[str, Any] = {}
        if "duration_ms" in media:
            options["duration_ms"] = int(media["duration_ms"])
        result = detector.detect(file_path, options=options if options else None)
        validate_scene_result(result.scenes)
    except Exception as e:
        return {
            "status": "error",
            "error": f"Scene detection failed: {e}",
            "stderr": "",
        }

    scenes = [
        {"index": s.index, "start_ms": s.start_ms, "end_ms": s.end_ms}
        for s in result.scenes
    ]

    return {
        "status": "success",
        "scene_detection": {
            "detector": result.detector,
            "detector_version": result.detector_version,
            "parameters": result.parameters,
            "scenes": scenes,
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


def detect_scenes(contract: dict[str, Any]) -> dict[str, Any]:
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
            "error": "No storage key provided for scene detection",
            "stderr": "",
        }

    if not os.path.isfile(file_path):
        return {
            "status": "error",
            "error": f"No such file: {file_path}",
            "stderr": "",
        }

    timeout = int(os.environ.get("SCENE_DETECT_TIMEOUT_SECONDS", "120"))
    if timeout <= 0:
        return {
            "status": "error",
            "error": f"Invalid timeout: {timeout}",
            "stderr": "",
        }

    deadline = time.monotonic() + timeout

    child = subprocess.Popen(
        [sys.executable, "-m", "aiclip_worker.cli", "--detect-scenes-child"],
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
            return {"status": "error", "error": f"Scene detection timed out after {timeout}s", "stderr": ""}

        stdout_bytes, stderr_bytes = child.communicate(
            input=contract_bytes,
            timeout=remaining,
        )
        stdout = stdout_bytes.decode()
        stderr = stderr_bytes.decode()

    except subprocess.TimeoutExpired:
        _kill_process_group(child)
        return {"status": "error", "error": f"Scene detection timed out after {timeout}s", "stderr": ""}
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
            "error": f"Scene detection failed (exit {child.returncode})",
            "stderr": stderr[:500],
        }

    output = json.loads(stdout)
    if not isinstance(output, dict) or output.get("status") != "success":
        return {
            "status": "error",
            "error": "Invalid child output",
            "stderr": "",
        }

    scene_detection = output.get("scene_detection")
    if not isinstance(scene_detection, dict):
        return {
            "status": "error",
            "error": "Missing scene_detection in child output",
            "stderr": "",
        }

    for field in ("detector", "detector_version"):
        val = scene_detection.get(field)
        if not isinstance(val, str) or not val.strip():
            return {
                "status": "error",
                "error": f"Missing or empty {field}",
                "stderr": "",
            }

    if not isinstance(scene_detection.get("parameters"), dict):
        return {
            "status": "error",
            "error": "Missing or invalid parameters",
            "stderr": "",
        }

    if not isinstance(scene_detection.get("scenes"), list):
        return {
            "status": "error",
            "error": "Missing or invalid scenes",
            "stderr": "",
        }

    return output
