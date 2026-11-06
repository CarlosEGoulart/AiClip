# Incident Response Runbook

## Overview

Incident response procedures for AiClip production incidents.

## Severity Levels

| Severity | Definition | Response Time | Escalation |
|----------|------------|---------------|------------|
| SEV-1 | Complete service outage or data loss | 15 min | On-call + Engineering Lead |
| SEV-2 | Major feature broken, no workaround | 30 min | On-call |
| SEV-3 | Minor feature degraded, workaround exists | 2 hours | On-call |
| SEV-4 | Cosmetic issue, no user impact | Next business day | Engineering team |

## Incident Declaration

Any engineer can declare an incident when:

- Service is unavailable or severely degraded
- Data integrity is compromised
- Security breach is suspected
- Critical user flow fails in production

## Response Process

### 1. Declare Incident

- Post in incident channel with severity level
- Create incident timeline document
- Assign incident commander

### 2. Investigate

- Check `/api/v1/health` and `/api/v1/readiness`
- Review application logs (sanitized — no secrets exposed)
- Check queue worker status: `php artisan queue:failed`
- Check database connectivity and migrations
- Review recent deployments and configuration changes

### 3. Mitigate

- If deployment-related: rollback (see `docs/runbooks/rollback.md`)
- If infrastructure-related: scale resources or failover
- If security-related: revoke exposed credentials, rotate secrets

### 4. Resolve

- Verify health and readiness endpoints return passing status
- Verify E2E smoke test passes
- Confirm data integrity
- Close incident with resolution summary

### 5. Post-Incident Review

Within 24 hours of resolution:

- Document root cause
- Identify contributing factors
- Define action items with owners and due dates
- Update runbooks if procedures need improvement
- Update `docs/known-limitations.md` if applicable

## Security Incidents

For suspected security breaches:

1. Immediately revoke and rotate all exposed credentials
2. Review access logs for unauthorized access
3. Check `SanitizeLogProcessor` masked output for leaked secrets
4. Notify security contact
5. Document incident per regulatory requirements

## Queue Incident Response

For queue worker failures:

1. Check `php artisan queue:failed` for failed jobs
2. Review job payloads for sensitive data (should be sanitized)
3. Retry failed jobs: `php artisan queue:retry <id>` or `php artisan queue:retry all`
4. If jobs repeatedly fail, investigate root cause (timeout, dependency, idempotency)
5. Adjust `$timeout` values or job logic as needed
