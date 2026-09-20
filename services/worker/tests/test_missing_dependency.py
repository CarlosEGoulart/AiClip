"""Test for PySceneDetectAdapter missing dependency error - runs in subprocess for isolation.

These tests are skipped when scenedetect is already installed in the environment
(such as in CI where the [scene_detection] extra is installed), since they
require scenedetect to be absent to verify the actionable ImportError.
"""

from __future__ import annotations

import subprocess
import sys

import pytest

# Check if scenedetect is available in the current environment
try:
    import scenedetect  # noqa: F401
    HAS_SCENEDETECT = True
except ImportError:
    HAS_SCENEDETECT = False


@pytest.mark.skipif(HAS_SCENEDETECT, reason="scenedetect is installed; missing dependency test requires scenedetect to be absent")
def test_pyscenedetect_adapter_missing_dependency_raises_actionable_error() -> None:
    """Adapter raises actionable ImportError when scenedetect is not installed."""
    # Run in a subprocess with scenedetect removed from path
    code = """
import sys
# Remove scenedetect from sys.modules
for mod in list(sys.modules.keys()):
    if mod.startswith('scenedetect'):
        del sys.modules[mod]

# Also remove our adapter module to force reload
for mod in list(sys.modules.keys()):
    if mod.startswith('aiclip_worker.scene_detection_pyscenedetect'):
        del sys.modules[mod]

from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter

adapter = PySceneDetectAdapter()
try:
    adapter.detect("/fake/path/video.mp4")
    print("ERROR: Expected ImportError was not raised")
    sys.exit(1)
except ImportError as e:
    error_msg = str(e)
    if "scenedetect" not in error_msg.lower():
        print(f"ERROR: Missing 'scenedetect' in error message: {error_msg}")
        sys.exit(1)
    if "pip install" not in error_msg.lower() and "scene_detection" not in error_msg.lower():
        print(f"ERROR: Missing install guidance in error message: {error_msg}")
        sys.exit(1)
    print(f"SUCCESS: {error_msg}")
    sys.exit(0)
except Exception as e:
    print(f"ERROR: Unexpected exception: {type(e).__name__}: {e}")
    sys.exit(1)
"""
    result = subprocess.run(
        [sys.executable, "-c", code],
        capture_output=True,
        text=True,
        cwd="/home/goulartoliveiracarloseduardo/AiClip/services/worker",
    )
    assert result.returncode == 0, f"Subprocess failed: {result.stderr}\n{result.stdout}"
    assert "SUCCESS" in result.stdout


@pytest.mark.skipif(HAS_SCENEDETECT, reason="scenedetect is installed; missing dependency test requires scenedetect to be absent")
def test_pyscenedetect_adapter_lazy_import_preserved() -> None:
    """Module import succeeds without scenedetect installed (lazy import preserved)."""
    code = """
import sys
# Remove scenedetect from sys.modules
for mod in list(sys.modules.keys()):
    if mod.startswith('scenedetect'):
        del sys.modules[mod]

# Also remove our adapter module to force reload
for mod in list(sys.modules.keys()):
    if mod.startswith('aiclip_worker.scene_detection_pyscenedetect'):
        del sys.modules[mod]

# Import should succeed without raising ImportError at import time
from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter

# Only calling detect() should raise
adapter = PySceneDetectAdapter()
try:
    adapter.detect("/fake/path/video.mp4")
    print("ERROR: Expected ImportError was not raised")
    sys.exit(1)
except ImportError as e:
    error_msg = str(e)
    if "scenedetect" not in error_msg.lower():
        print(f"ERROR: Missing 'scenedetect' in error message: {error_msg}")
        sys.exit(1)
    if "pip install" not in error_msg.lower() and "scene_detection" not in error_msg.lower():
        print(f"ERROR: Missing install guidance in error message: {error_msg}")
        sys.exit(1)
    print(f"SUCCESS: {error_msg}")
    sys.exit(0)
except Exception as e:
    print(f"ERROR: Unexpected exception: {type(e).__name__}: {e}")
    sys.exit(1)
"""
    result = subprocess.run(
        [sys.executable, "-c", code],
        capture_output=True,
        text=True,
        cwd="/home/goulartoliveiracarloseduardo/AiClip/services/worker",
    )
    assert result.returncode == 0, f"Subprocess failed: {result.stderr}\n{result.stdout}"
    assert "SUCCESS" in result.stdout