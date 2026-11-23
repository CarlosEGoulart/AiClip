# Overview

Unit tests for the deterministic spec-plan quality validator.

## Test Scenarios

- Q1: Valid compliant bundle → pass.
- Q2-Q4: Missing required files → fail closed.
- Q5: Empty spec.md → fail.
- Q6-Q8: Missing required sections → fail.
- Q9: Placeholder content → fail.
- Q10: Empty required section → fail.
- Q11: Non-checklist acceptance criteria → fail.
- Q12: Subjective adjectives → fail.
- Q13: Contradictory criteria → fail.

## Application E2E / Playwright

N/A — governance/tooling only, no UI changes.

## Test Execution

```bash
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests/governance -p 'test_spec_plan_quality.py' -v
```
