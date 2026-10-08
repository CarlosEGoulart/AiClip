# Evidence: Issue #76 — M6.2 Caption Burn-in Slice 1

This is the evidence file for the Tester approval decision.

## Tester Decision

Decision: APPROVE

### Justification

All 5 authorized Slice 1 changes have been verified as correctly implemented:

1. **`render_clip.py`** — `caption_file` passed to `renderer.render_singular()`
   - Contract field read optionally; passed to renderer

2. **`rendering.py`** — optional subtitles filter preserved; `caption_file` parameter support
   - Filter graph adds `subtitles={caption_file}` only when present
   - When absent: identical to M6.1 baseline (backward compatible)

3. **`media_processing_v1.json`** — `caption_file` added as optional in `render_clip_request` schema
   - Type `["string", "null"]` with description "Optional caption file path for burn-in subtitles"

4. **`contracts.py`** — burn_in architecture removed
   - No BURN_IN_* constants or action="burn_in" present

5. **`cli.py`** — burn-clip subcommand removed
   - No "burn-clip" subcommand; `render-clip` works with stdin JSON

Independent Tester Determination:
The implementation correctly satisfies all Slice 1 acceptance criteria for M6.2 Caption Burn-in. The independent tester (this agent) finds no blocking defects, no regressions, and no scope drift. All acceptance criteria are satisfied.

### RED

caption_file extracted but never passed to renderer

### GREEN

caption_file now passed to renderer.render_singular()

### REFACTOR

burn_in constants removed, cli cleaned up

Decision: APPROVE