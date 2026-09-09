# Architecture (Target)

This document describes the planned target stack. No services, schemas, or deployment are implemented in issue #1.

## Planned stack

Laravel, React with TypeScript, PostgreSQL, Python media worker, FFmpeg with FFprobe, Playwright, Docker, and GitHub Actions.

## Planned responsibilities

Laravel handles authentication, authorization, application API, projects, media metadata, job orchestration, social OAuth, publishing, and persistence.

React provides the user interface, media review, clip editor, image studio, social connections, and publishing UI.

The media worker handles FFmpeg tasks, transcription, scene detection, face tracking, clip analysis, rendering, and image generation outside HTTP requests.

## Boundaries

Laravel remains the authoritative application backend. Heavy ML inference stays outside normal HTTP request processes. Provider abstractions isolate image generation, transcription, ranking, and publishing. CI uses deterministic fakes.
