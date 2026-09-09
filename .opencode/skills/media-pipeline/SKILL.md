---
name: media-pipeline
description: Guide async Laravel media jobs with safe FFmpeg handling. Use when planning media processing, transcoding, or worker jobs.
---

# Media Pipeline

Use only when relevant to the active task. Do not load all skills by default. This issue provides future implementation guidance only; no media code is implemented here.

## Workflow

1. Keep Laravel as orchestrator of asynchronous jobs. Heavy ML inference and FFmpeg work stays outside normal HTTP request processes in the Python media worker.
2. Validate inputs with FFprobe before processing. Validate paths, inputs, and options before building commands.
3. Execute FFmpeg through argument-list subprocess calls without shell interpolation.
4. Bound execution time and resources. Handle exit codes and cancellation explicitly.
5. Use job-isolated temporary paths with clear ownership. Clean up on success, failure, and cancellation.
6. Provide bounded recovery for abandoned files. Protect original inputs from overwrite.
7. Support retries with idempotent outputs.
8. Use provider abstractions for transcription, analysis, and rendering. Use deterministic fixtures and fakes in tests.
9. Never download large models in CI.

## Expected evidence

Job boundary notes, command construction review, temporary-file lifecycle, cleanup verification, and deterministic test handoff.
