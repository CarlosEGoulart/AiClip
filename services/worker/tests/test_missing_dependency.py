"""Test for PySceneDetectAdapter missing dependency error.

These tests verify that PySceneDetectAdapter raises an actionable ImportError
when the scenedetect package is not installed, using deterministic import
interception. They execute regardless of whether scenedetect is installed.
"""

from __future__ import annotations

import builtins

import pytest

from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter


def _blocking_import(name: str, *args, **kwargs):
    """Import hook that blocks scenedetect while allowing all other imports."""
    real_import = _blocking_import._real_import  # type: ignore[attr-defined]
    if name == "scenedetect" or name.startswith("scenedetect."):
        raise ImportError("simulated missing scenedetect")
    return real_import(name, *args, **kwargs)


@pytest.fixture(autouse=False)
def block_scenedetect(monkeypatch):
    """Block scenedetect imports for the duration of a test."""
    _blocking_import._real_import = builtins.__import__  # type: ignore[attr-defined]
    monkeypatch.setattr(builtins, "__import__", _blocking_import)
    yield
    # Cleanup cached scenedetect modules so other tests are unaffected
    import sys
    for mod_name in list(sys.modules):
        if mod_name == "scenedetect" or mod_name.startswith("scenedetect."):
            del sys.modules[mod_name]


def test_missing_dependency_raises_actionable_error(block_scenedetect):
    """Adapter raises actionable ImportError when scenedetect is not installed."""
    adapter = PySceneDetectAdapter()

    with pytest.raises(ImportError, match="scenedetect"):
        adapter.detect("/fake/path/video.mp4")

    # Verify actionable guidance is present
    with pytest.raises(ImportError, match="pip install"):
        adapter.detect("/fake/path/video.mp4")


def test_lazy_import_preserved(block_scenedetect):
    """Importing the adapter module and constructing it succeeds without scenedetect."""
    # Module import succeeds (lazy import — scenedetect not needed at import time)
    from aiclip_worker.scene_detection_pyscenedetect import PySceneDetectAdapter

    # Construction succeeds
    adapter = PySceneDetectAdapter()
    assert adapter.get_name() == "pyscenedetect"

    # get_version returns 'unknown' when scenedetect is blocked
    assert adapter.get_version() == "unknown"

    # Only detect() triggers the controlled ImportError
    with pytest.raises(ImportError, match="scenedetect"):
        adapter.detect("/fake/path/video.mp4")
