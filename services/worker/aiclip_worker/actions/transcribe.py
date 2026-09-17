"""Transcribe action: invokes transcription engine on normalized audio."""

from __future__ import annotations

import os
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeoutError
from typing import Any

from aiclip_worker.transcription import get_transcriber


def transcribe(contract: dict[str, Any]) -> dict[str, Any]:
    """Transcribe audio file using the configured transcription engine.

    Args:
        contract: Media processing contract dict with storage info and derived_asset_id.

    Returns:
        Dict with 'status' = 'success' and 'transcription' data on success,
        or 'status' = 'error' with error details on failure.
    """
    # Validate storage key
    storage = contract.get("storage", {})
    file_path = storage.get("key", "")

    if not file_path:
        return {
            "status": "error",
            "error": "No storage key provided for transcription",
            "stderr": "",
        }

    # Check file exists
    if not os.path.isfile(file_path):
        return {
            "status": "error",
            "error": f"No such file: {file_path}",
            "stderr": "",
        }

    # Get timeout
    timeout = int(os.environ.get("TRANSCRIBE_TIMEOUT_SECONDS", "300"))

    # Get the transcriber engine
    try:
        engine_name = os.environ.get("TRANSCRIPTION_ENGINE", "faster_whisper")
        transcriber = get_transcriber(engine_name)
    except (ValueError, ImportError) as e:
        return {
            "status": "error",
            "error": f"Failed to initialize transcription engine: {e}",
            "stderr": "",
        }

    # Perform transcription with timeout using thread pool
    try:
        with ThreadPoolExecutor(max_workers=1) as executor:
            future = executor.submit(transcriber.transcribe, file_path, {})
            result = future.result(timeout=timeout)
    except FuturesTimeoutError:
        return {
            "status": "error",
            "error": f"Transcription timed out after {timeout}s",
            "stderr": "",
        }
    except Exception as e:
        return {
            "status": "error",
            "error": f"Transcription failed: {e}",
            "stderr": "",
        }

    # Convert to dict format
    segments = [
        {
            "start_ms": seg.start_ms,
            "end_ms": seg.end_ms,
            "text": seg.text,
        }
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
