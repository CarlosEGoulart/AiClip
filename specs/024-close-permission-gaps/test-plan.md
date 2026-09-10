# Test Plan: Close Remaining Permission and Baseline Gaps

## Test Strategy

### Permission Regression Tests (tests/governance/test_agent_permissions.py)

- Use parsed YAML frontmatter from agent config files
- Use decide() helper for actual permission rule evaluation
- Verify Builder denies governance test edits
- Verify Builder allows application test edits
- Verify Builder denies control-plane files under apps/
- Verify all agents deny .env, allow .env.example
- Verify permission tests use parsed rule decisions

### Existing Governance Tests (tests/governance/test_governance.py)

- Update g3 for new Builder governance boundary
- Update g4 for new Builder governance test command boundary
- All existing tests remain green

### Configuration Verification

- composer.json setup script has no npm steps
- Backend CI has no Node.js step
- apps/api/AGENTS.md references no .claude/skills/

### Documentation Verification

- PRD has no scheduling claims
- Roadmap separates M0 governance from M1
- README DoD says explicit authorization

## Test Commands

```sh
python -m unittest discover -s tests/governance -p 'test_*.py' -v
cd apps/api && php artisan test
cd apps/web && npm test
cd apps/web && npm run lint
cd apps/web && npm run build
```
