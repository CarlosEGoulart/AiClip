---
name: playwright-visual-qa
description: Perform Playwright visual QA on running user-facing workflows. Use when testing UI, reviewing screenshots, console, network, or accessibility.
---

# Playwright Visual QA

Use only when relevant to the active task. Do not load all skills by default.

## Applicability

This skill applies to user-facing workflows with a running application. For governance-only changes with no application or interface, it is N/A with an explicit reason. Future user-facing workflows still require running-app interaction and visual review.

## Workflow

1. Start the running application for the user-facing workflow under test.
2. Exercise the workflow at `390x844`, `768x1024`, and `1440x900`.
3. Review layout, overflow, spacing, alignment, typography, visual hierarchy, and coherent mobile design. Confirm mobile looks intentionally designed.
4. Check dialogs, navigation behavior, media sizing, and content density.
5. Check loading, empty, error, success, disabled, hover, active, and focus states. Confirm destructive actions are distinguishable.
6. Verify keyboard operation, focus management, visible labels, contrast, and accessibility fundamentals.
7. Inspect screenshots critically; do not rubber-stamp generated UI.
8. Inspect browser console, network requests, API responses, uncaught exceptions, failed resources, and unexpected redirects.
9. Fail review on unexpected console errors or application 4xx/5xx errors unless explicitly expected by the tested scenario.
10. Visual defects can reject an issue even when automated tests pass.
11. Record evidence including viewports, states checked, console and network observations, and screenshots.
12. On rejection, return through Orchestrator to Builder for the same issue and re-verify after corrections.

## Expected evidence

Viewport results, state coverage, console and network notes, screenshot review, and the APPROVE or REJECT decision with findings.
