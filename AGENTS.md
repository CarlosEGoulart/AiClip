# AGENTS.md

## 1. Purpose

This repository is developed using strict Spec-Driven Development, Test-Driven Development, GitHub Flow, and a hierarchical multi-agent workflow.

This file defines mandatory rules for every AI agent working in this repository.

These rules are normative.

If an agent instruction, generated plan, implementation shortcut, or model suggestion conflicts with this file, this file takes precedence.

---

# 2. Core Engineering Principles

All development MUST follow:

* Spec-Driven Development
* Test-Driven Development
* Strict issue linearity
* GitHub Flow
* Conventional Commits
* Independent testing
* Visual browser validation
* Small incremental changes
* Explicit acceptance criteria
* English-only repository content

The objective is verified progress, not maximum code generation speed.

---

# 3. Agent Hierarchy

The project uses four primary agents:

```text
Orchestrator
├── Planner
├── Builder
└── Tester
```

The Orchestrator is the primary coordination agent.

Planner, Builder, and Tester are specialized agents with isolated responsibilities.

Agents MUST NOT silently assume responsibilities belonging to another agent.

---

# 4. Agent Responsibilities

## 4.1 Orchestrator

The Orchestrator controls the development lifecycle.

Responsibilities:

* inspect repository state;
* verify whether an issue is active;
* invoke Planner;
* create GitHub issues from Planner output;
* create branches;
* invoke Builder;
* invoke Tester;
* route rejected work back to Builder;
* create commits;
* push branches;
* create Pull Requests;
* monitor CI;
* coordinate corrections;
* merge approved Pull Requests;
* verify issue closure;
* update project state;
* stop after closure and await explicit authorization before any next issue.

The Orchestrator is the only agent authorized to perform repository lifecycle operations.

These include:

* branch creation;
* branch switching;
* commits;
* pushes;
* Pull Request creation;
* merge operations;
* issue closure.

The Orchestrator MUST NOT:

* implement application features directly;
* bypass Tester;
* bypass CI;
* merge failing code;
* work around acceptance criteria;
* start multiple implementation issues simultaneously.

---

## 4.2 Planner

The Planner defines what should be built.

Preferred planning model:

```text
GPT-6 Astra
```

Responsibilities:

* understand current project state;
* inspect relevant architecture;
* identify the smallest useful next increment;
* define exactly one issue;
* create its specification;
* create its implementation plan;
* define acceptance criteria;
* define test scenarios;
* identify security implications;
* identify UX implications;
* define explicit out-of-scope work.

Planner MUST NOT:

* write production code;
* implement features;
* create multiple implementation issues;
* create branches;
* commit;
* push;
* merge;
* silently expand project scope.

The Planner may clarify an existing active issue.

Clarification does not create another issue.

---

## 4.3 Builder

The Builder implements the active issue.

Use an appropriate currently available free OpenCode coding model.

Responsibilities:

* read the active issue;
* read the active specification;
* read the implementation plan;
* read the test plan;
* follow TDD;
* implement only the active issue;
* execute targeted tests;
* refactor after reaching GREEN;
* report implementation status to Orchestrator.

Builder MUST NOT:

* create issues;
* create branches;
* commit;
* push;
* create Pull Requests;
* merge;
* modify unrelated code;
* weaken tests;
* disable tests;
* silently expand scope.

When requirements are ambiguous, Builder MUST return the issue to Orchestrator for Planner clarification.

---

## 4.4 Tester

Tester is an independent quality gate.

Tester SHOULD use a different model from Builder whenever possible.

Responsibilities:

* inspect acceptance criteria;
* inspect code changes;
* execute unit tests;
* execute integration tests;
* execute E2E tests;
* execute Playwright scenarios;
* inspect browser behavior;
* inspect screenshots;
* inspect browser console;
* inspect network activity;
* inspect API responses;
* validate error states;
* validate loading states;
* validate responsive layouts;
* validate accessibility fundamentals;
* detect unrelated scope changes;
* approve or reject the implementation.

Tester MUST NOT approve work solely because automated tests pass.

Tester MUST interact with the running application when application behavior is affected. For governance-only changes with no application or interface, Tester exercises the relevant tooling and reviews the actual artifacts instead, recording application checks as N/A with a reason.

Tester MUST NOT directly repair production implementation.

If Tester detects a defect:

```text
Tester
   ↓
REJECT
   ↓
Orchestrator
   ↓
Builder
   ↓
Tester
```

This loop continues until approval.

---

# 5. Strict Linearity

Only ONE implementation issue may be active at any time.

Parallel implementation is forbidden.

The mandatory lifecycle is:

```text
Issue Creation
    ↓
Branch Creation
    ↓
Specification
    ↓
TDD RED
    ↓
TDD GREEN
    ↓
REFACTOR
    ↓
Independent Testing
    ↓
Commit
    ↓
Push
    ↓
Pull Request
    ↓
CI
    ↓
Merge
    ↓
Issue Closure
```

Only after the active issue is officially closed, with explicit authorization, may Planner define the next issue. Otherwise stop at NO_ACTIVE_ISSUE.

Milestones and roadmap entries are not active issues.

---

# 6. Issue State Machine

The Orchestrator MUST conceptually track:

```text
NO_ACTIVE_ISSUE
ISSUE_CREATED
BRANCH_CREATED
SPEC_READY
RED_VERIFIED
GREEN_VERIFIED
TESTER_APPROVED
PR_OPEN
CI_GREEN
MERGED
ISSUE_CLOSED
```

Forbidden transitions include:

```text
ISSUE_CREATED -> NEW_ISSUE

RED_VERIFIED -> PR_OPEN

GREEN_VERIFIED -> MERGED

PR_OPEN -> NEW_ISSUE

TESTER_APPROVED -> MERGED_WITH_FAILED_CI

MERGED -> NEW_ISSUE_BEFORE_CLOSURE
```

---

# 7. Every Action Requires an Issue

No production repository change may exist without an issue.

There MUST NOT be:

* commits without an issue;
* Pull Requests without an issue;
* implementation branches without an issue;
* undocumented refactors;
* unrelated cleanup inside another issue.

If new work is discovered while implementing an issue and it is not necessary to satisfy the active acceptance criteria, record it for future Planner evaluation.

Do not implement it immediately.

---

# 8. Mandatory Issue Structure

Every issue created by Planner MUST contain:

```markdown
# Title

Clear and concise title.

## Description

Explain the goal and why it is needed.

## Technical Tasks

- [ ] Task
- [ ] Task
- [ ] Task

## Acceptance Criteria

- [ ] Required behavior works.
- [ ] Relevant unit tests exist and pass.
- [ ] Relevant integration tests pass.
- [ ] Relevant E2E tests exist and pass.
```

When applicable also include:

```markdown
## Out of Scope

## Security Considerations

## UX Considerations

## Test Scenarios

## Dependencies
```

Issues must be small and cohesive.

---

# 9. Branch Naming

Branch naming is mandatory.

Format:

```text
@carlosegoulart/{n_issue}/{type}/{description}
```

Allowed branch types:

```text
feat
fix
chore
refactor
docs
test
```

Examples:

```text
@carlosegoulart/01/chore/init-project-structure

@carlosegoulart/15/feat/audio-chunk-manager

@carlosegoulart/31/feat/dashboard-grid
```

Rules:

* use the real GitHub issue number;
* use lowercase;
* use kebab-case;
* do not use spaces;
* branch scope must match issue scope.

---

# 10. Conventional Commits

Mandatory format:

```text
<type>(<scope>): <subject>
```

Allowed types:

```text
feat
fix
chore
refactor
docs
test
perf
ci
```

Examples:

```text
feat(clips): generate ranked clip candidates

test(clips): add clip ranking unit tests

fix(captions): preserve word timing during rendering

refactor(media): isolate ffmpeg command builder

ci(playwright): upload traces on test failure
```

Commits MUST relate directly to the active issue.

Prefer including:

```text
Refs #<issue>
```

in the commit body or footer.

No unrelated changes are allowed.

---

# 11. Pull Request Rules

Every Pull Request MUST solve exactly one issue.

The PR MUST reference the issue.

Recommended relationship:

```text
Closes #<issue>
```

PR description MUST include:

```markdown
## Summary

## Scope

## TDD Evidence

### RED

### GREEN

### REFACTOR

## Tests

- [ ] Unit tests
- [ ] Integration tests
- [ ] E2E tests
- [ ] Playwright validation
- [ ] Visual validation when applicable

## API Review

## Visual Review

## Risks

## CI

## Scope Verification
```

Pull Requests may not be merged while required CI checks fail.

---

# 12. Test-Driven Development

TDD is mandatory.

The required cycle is:

```text
RED
 ↓
GREEN
 ↓
REFACTOR
```

## RED

Before implementing behavior:

1. write the test;
2. execute the test;
3. verify that it fails;
4. verify that failure occurs because the required behavior is missing.

Environment failures, syntax errors, broken fixtures, or missing dependencies do not count as valid RED evidence.

## GREEN

Implement only enough production code to satisfy the behavior.

Run the relevant tests.

## REFACTOR

Improve implementation quality without altering behavior.

Run tests again.

All tests must remain green.

---

# 13. Testing Requirements

Every feature requires unit and E2E coverage when applicable.

For governance-only changes with no application or interface, application unit, API, E2E, Playwright, visual, and accessibility interaction are N/A with an explicit reason. Future user-facing workflows still require running-app interaction and Playwright review.

## Laravel

Use:

* Pest or PHPUnit;
* unit tests;
* feature/API tests;
* database tests where required.

## React

Use:

* Vitest;
* React Testing Library;
* behavior-focused component tests.

## Media Worker

Use:

* pytest;
* deterministic fixtures;
* provider fakes.

## E2E

Use:

```text
Playwright
```

Playwright is mandatory for user-facing workflows.

---

# 14. Playwright Quality Gate

Tester MUST visually inspect UI changes.

Required default viewports:

```text
390x844
768x1024
1440x900
```

Tester MUST inspect:

* layout;
* overflow;
* spacing;
* alignment;
* typography;
* responsiveness;
* dialogs;
* loading states;
* empty states;
* error states;
* success states;
* focus behavior;
* keyboard navigation;
* media sizing;
* disabled states;
* navigation behavior.

Tester MUST also inspect:

```text
browser console
network requests
API responses
uncaught exceptions
failed resources
unexpected redirects
```

Unexpected console errors fail review.

Unexpected application 4xx/5xx errors fail review unless explicitly required by the tested scenario.

---

# 15. Visual Self-Review

Tester must evaluate the interface critically.

Questions include:

1. Is the primary action obvious?
2. Is visual hierarchy clear?
3. Is spacing consistent?
4. Are controls visually recognizable?
5. Are interactive states clear?
6. Does mobile appear intentionally designed?
7. Are errors understandable?
8. Are loading states stable?
9. Are destructive actions distinguishable?
10. Is content density appropriate?
11. Does the interface look coherent rather than automatically generated?
12. Are accessibility fundamentals respected?

Visual defects may reject an issue even when automated tests pass.

---

# 16. Design Skills

Use relevant design skills when implementing UI.

Required when applicable:

```text
design-taste-frontend
web-design-guidelines
```

Recommended order:

```text
Builder
   ↓
design-taste-frontend
   ↓
implementation
   ↓
web-design-guidelines review
   ↓
Tester
   ↓
Playwright visual QA
```

Skills are guidance, not justification for violating accessibility, performance, or project conventions.

---

# 17. Recommended Project Skills

Project-local skills should include:

```text
issue-linearity
tdd-enforcer
token-efficient-context
playwright-visual-qa
media-pipeline
social-publishing
```

Load skills only when relevant.

Avoid injecting every skill into every agent context.

---

# 18. Context Efficiency

Agents MUST minimize unnecessary context.

Start with:

```text
docs/project-state.md
active issue
active spec
active plan
relevant changed files
```

Do not automatically load:

```text
vendor/
node_modules/
build/
dist/
all closed issues
full Git history
all previous agent conversations
all documentation
```

Search before reading entire files.

Read only relevant sections.

Summarize large logs.

Inspect targeted diffs before inspecting the entire repository.

Keep subagent contexts isolated.

---

# 19. Repository Language

ALL repository content MUST be written in English.

This includes:

* code;
* variables;
* functions;
* classes;
* methods;
* comments;
* documentation;
* issues;
* Pull Requests;
* commit messages;
* branches;
* tests;
* fixtures;
* error messages;
* logs;
* API fields;
* database names.

Portuguese is forbidden inside repository artifacts.

User-facing internationalization may be implemented later.

---

# 20. Architecture Baseline

Primary stack:

```text
Laravel
React
TypeScript
PostgreSQL
Python media worker
FFmpeg
Playwright
Docker
GitHub Actions
```

Responsibilities:

```text
Laravel
├── Authentication
├── Authorization
├── Application API
├── Projects
├── Media metadata
├── Job orchestration
├── Social OAuth
├── Publishing
└── Persistence

React
├── User interface
├── Media review
├── Clip editor
├── Image studio
├── Social connections
└── Publishing UI

Media Worker
├── FFmpeg
├── FFprobe
├── Transcription
├── Scene detection
├── Face tracking
├── Clip analysis
├── Rendering
└── Image generation
```

Laravel remains the authoritative application backend.

Heavy ML inference must not run inside normal Laravel HTTP request processes.

---

# 21. AI Provider Boundaries

Do not tightly couple application logic to individual models.

Use provider abstractions.

Examples:

```text
ImageGenerationProvider

TranscriptionProvider

ClipRankingProvider

SocialPublisher
```

Provider implementations may be replaced without modifying application-domain behavior.

CI MUST use deterministic fakes instead of downloading multi-gigabyte models.

---

# 22. External APIs

Third-party integrations must use current official documentation.

This applies especially to:

* YouTube;
* Instagram;
* TikTok;
* OAuth providers;
* model licenses;
* model APIs.

Never assume external API behavior solely from model memory.

Do not use unofficial browser automation to publish social media content when an official API is required.

---

# 23. Security

Never expose:

* OAuth tokens;
* refresh tokens;
* API secrets;
* database credentials;
* private storage credentials.

Secrets must not appear in:

* source code;
* logs;
* screenshots;
* exceptions;
* fixtures committed to Git.

Use encrypted server-side storage when required.

Frontend applications must not receive social provider refresh tokens.

---

# 24. No Placeholder Completion

An issue cannot be marked complete when required production behavior contains:

```text
TODO
FIXME
temporary production mock
empty implementation
disabled assertion
skipped required test
hardcoded fake success
commented-out implementation
```

Test doubles are allowed only inside explicitly defined test/provider boundaries.

---

# 25. Scope Discipline

Do not perform opportunistic refactors.

Do not fix unrelated bugs.

Do not introduce unrelated dependencies.

Do not redesign unrelated UI.

Do not rename unrelated components.

If something unrelated deserves attention, record it for Planner consideration after the current issue closes.

---

# 26. Definition of Done

An issue is DONE only when all applicable items are satisfied:

```text
[ ] Issue exists
[ ] Branch naming is valid
[ ] Specification exists
[ ] Plan exists
[ ] Acceptance criteria are satisfied
[ ] RED was demonstrated
[ ] GREEN was demonstrated
[ ] Refactor remained green
[ ] Unit tests pass
[ ] Integration tests pass
[ ] E2E tests pass
[ ] Playwright validation passes
[ ] Visual review passes
[ ] API review passes
[ ] Accessibility review passes
[ ] Security implications were considered
[ ] Documentation is updated
[ ] Commits follow Conventional Commits
[ ] PR references the issue
[ ] Tester approved
[ ] CI is green
[ ] PR is merged
[ ] Issue is closed
```

Only then may another implementation issue begin.

---

# 27. Project State

Maintain:

```text
docs/project-state.md
```

It should contain only:

```markdown
# Current Architecture

# Completed Capabilities

# Important Decisions

# Known Limitations

# Current Milestone

# Next Architectural Goal
```

Keep this document concise.

It exists to bootstrap future agent contexts without loading entire project history.

---

# 28. First Development Cycle

When starting from an empty repository, the first development issue should establish project governance and agent infrastructure.

Approximate goal:

```text
Initialize repository governance and SDD agent architecture
```

Expected scope:

* root repository structure;
* `AGENTS.md`;
* `README.md`;
* `docs/`;
* `specs/`;
* `.opencode/agents/`;
* `.opencode/skills/`;
* Git conventions;
* baseline CI structure;
* `docs/project-state.md`.

Do not initialize any application implementation in the first issue, including partial Laravel, React, database, media, social, UI, container, or deployment scaffolding.

After the issue is tested, merged, and closed, stop. Return to NO_ACTIVE_ISSUE and do not invoke Planner for another issue without explicit authorization.

---

# 29. Ultimate Rule

Never optimize for the appearance of progress.

Optimize for:

```text
one issue
correctly specified
correctly implemented
independently tested
visually verified
CI verified
merged
closed
```

Then repeat.

