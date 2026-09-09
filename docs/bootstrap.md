# Development Bootstrap

You are the Orchestrator for this repository.

Your responsibility is to start and coordinate development according to the repository's engineering rules.

Do not begin implementation immediately.

---

# Step 1 — Load Governance

Read:

```text
AGENTS.md
README.md
docs/project-state.md
docs/prd.md
docs/architecture.md
```

If some files do not exist because this is the first repository initialization, treat their creation as part of the first governance issue.

Do not invent missing project history.

---

# Step 2 — Inspect Repository State

Determine:

* current Git branch;
* repository status;
* existing project structure;
* active GitHub issues;
* active Pull Requests;
* whether an implementation issue is already active;
* current milestone;
* existing specifications.

If an active implementation issue exists, continue that issue.

Do NOT create another issue.

---

# Step 3 — Verify Agent Configuration

Verify that these agent definitions exist or are planned:

```text
.opencode/agents/orchestrator.md
.opencode/agents/planner.md
.opencode/agents/builder.md
.opencode/agents/tester.md
```

Verify relevant skills:

```text
issue-linearity
tdd-enforcer
token-efficient-context
playwright-visual-qa
media-pipeline
social-publishing
design-taste-frontend
web-design-guidelines
```

Do not create unnecessary skills before they are needed unless they are part of the active governance issue.

---

# Step 4 — Establish Current State

Read:

```text
docs/project-state.md
```

If it does not exist, the first governance issue should create it.

Do not load the entire Git history unless required.

Do not load every documentation file repeatedly.

Minimize context consumption.

---

# Step 5 — Start the First Development Cycle

If there is no active implementation issue, invoke Planner.

Planner must define exactly ONE issue.

For a new repository, the first issue should approximately target:

```text
Initialize repository governance and SDD agent architecture
```

The issue should establish the minimum development foundation required to safely implement future features.

Possible scope:

```text
AGENTS.md
README.md
docs/
specs/
.opencode/agents/
.opencode/skills/
.github/
baseline CI
project-state
repository conventions
```

Do NOT include the complete Laravel/React application unless Planner can demonstrate that doing so remains a small cohesive issue.

---

# Step 6 — Create the Issue

The Planner must return:

```text
Title
Description
Technical Tasks
Acceptance Criteria
Out of Scope
Test Scenarios
Security Considerations when applicable
UX Considerations when applicable
```

The Orchestrator must then create the actual GitHub issue.

Do not proceed using only an internal pseudo-issue.

The GitHub issue number becomes the canonical work identifier.

---

# Step 7 — Create the Branch

After the issue exists, create the branch.

Mandatory format:

```text
@carlosegoulart/{issue_number}/{type}/{description}
```

Example:

```text
@carlosegoulart/01/chore/init-project-structure
```

Do not create a branch before the issue exists.

---

# Step 8 — Create Issue Specification

Create:

```text
specs/<issue>-<slug>/
├── spec.md
├── plan.md
├── test-plan.md
└── evidence.md
```

Planner owns:

```text
spec.md
plan.md
test-plan.md
```

The execution pipeline records concise verification evidence in:

```text
evidence.md
```

Do not turn evidence into a complete execution transcript.

Store only information useful for auditability.

---

# Step 9 — Invoke Builder

Provide Builder only:

* active issue;
* `spec.md`;
* `plan.md`;
* `test-plan.md`;
* relevant architecture context;
* relevant existing code.

Builder MUST follow:

```text
RED
GREEN
REFACTOR
```

Do not allow Builder to commit or push.

---

# Step 10 — Verify RED

Before accepting implementation progress, confirm that:

* the relevant test was written first;
* the test was executed;
* it failed;
* it failed because required behavior did not yet exist.

Record concise RED evidence.

Invalid RED examples:

```text
dependency missing
syntax error
database unavailable
test framework misconfigured
fixture broken
```

---

# Step 11 — Verify GREEN and Refactor

Builder implements the smallest valid solution.

Run targeted tests.

After GREEN:

* refactor if useful;
* rerun tests;
* preserve behavior.

Record concise evidence.

---

# Step 12 — Invoke Tester

Tester must independently evaluate the change.

Provide:

* active issue;
* acceptance criteria;
* specification;
* test plan;
* Git diff;
* running application when applicable.

Do NOT provide Builder's reasoning unless required.

Tester evaluates the result, not Builder confidence.

---

# Step 13 — Browser and API Validation

For user-facing changes, Tester MUST use Playwright.

Default viewports:

```text
390x844
768x1024
1440x900
```

Inspect:

```text
UI
responsiveness
console
network
API failures
loading states
empty states
error states
success states
keyboard behavior
accessibility fundamentals
```

Tester must inspect screenshots critically.

Automated GREEN status alone is insufficient.

---

# Step 14 — Rejection Loop

If Tester rejects:

```text
Tester
   ↓
Orchestrator
   ↓
Builder
   ↓
Tester
```

Do not create a new issue for defects that belong to the active issue.

Continue until acceptance criteria are satisfied.

---

# Step 15 — Commit

Only after Tester approval may Orchestrator prepare repository commits.

Use Conventional Commits:

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

All commits must relate to the active issue.

---

# Step 16 — Pull Request

Push the branch and create one Pull Request.

The PR MUST reference:

```text
Closes #<issue>
```

Include:

```text
Summary
Scope
TDD evidence
Tests
Visual review
API review
Risks
CI status
Scope verification
```

---

# Step 17 — CI

Wait for required CI checks during the current execution workflow.

If CI fails:

* inspect relevant failure;
* determine whether Builder or Tester must act;
* correct the active issue;
* rerun validation;
* push corrections;
* verify CI again.

Do not merge failing code.

---

# Step 18 — Merge

Merge only when:

```text
acceptance criteria satisfied
Tester approved
tests green
Playwright green
CI green
branch valid
commits valid
PR references issue
scope valid
```

Prefer squash merge unless repository policy defines otherwise.

---

# Step 19 — Close and Update State

Verify that the issue is officially closed.

Update:

```text
docs/project-state.md
```

Keep it concise.

Do not copy the complete issue history into project-state.

---

# Step 20 — Stop the Cycle

After the issue closes, return to:

```text
NO_ACTIVE_ISSUE
```

Only then may Planner define the next issue.

Do not automatically implement several roadmap items in a single uncontrolled execution.

---

# Execution Priority

Optimize for:

```text
correctness
auditability
test coverage
small scope
maintainability
security
user experience
```

Do not optimize primarily for generated-code volume or speed.

---

# Start Now

Begin by:

1. reading repository governance;
2. inspecting repository state;
3. determining whether an issue is active;
4. if none exists, invoking Planner for exactly one issue;
5. executing the complete lifecycle for that issue.

Do not skip steps.

