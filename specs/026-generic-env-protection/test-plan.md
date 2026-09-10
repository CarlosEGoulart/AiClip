# Test Plan: Generic .env.* Protection

## Test Strategy

### Permission Regression Tests

- Verify all four agents deny .env, .env.testing, .env.development, .env.backup
- Verify arbitrary future .env.* variants are denied
- Verify nested .env.* variants are denied
- Verify .env.example remains allowed
- Verify normal source files remain allowed

### Governance Tests

- All existing tests remain green
- New tests pass for generic patterns

### Runtime Verification

- OpenCode debug probes confirm correct patterns
