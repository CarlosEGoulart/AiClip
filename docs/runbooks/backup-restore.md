# Backup and Restore Runbook

## Overview

Database backup, restore, and data retention procedures for AiClip production.

## Backup Strategy

### Database (PostgreSQL)

- Daily automated backup at 02:00 UTC
- Point-in-time recovery enabled (WAL archiving)
- Retention: 30 days of daily backups, 12 weeks of weekly backups
- Backup verification: weekly restore test to staging

### Storage (S3-compatible)

- Object versioning enabled
- Lifecycle policy: transition to Glacier after 30 days, delete after 365 days
- Cross-region replication for disaster recovery

## Backup Verification

Weekly automated test:

1. Restore latest backup to staging environment
2. Run `php artisan migrate --force` on restored database
3. Run application smoke tests against restored data
4. Report results to on-call engineer

## Restore Procedure

### Point-in-Time Recovery

1. Identify target recovery time
2. Stop application and queue workers
3. Restore base backup from S3/Glacier
4. Apply WAL archives up to target time
5. Start PostgreSQL and verify data integrity
6. Point application to restored database
7. Run `php artisan migrate --force` if schema changed after backup
8. Verify health and readiness endpoints
9. Run E2E smoke test

### Full Restore from Backup

1. Download latest backup from S3
2. Stop application and queue workers
3. Restore PostgreSQL from backup file
4. Start application services
5. Verify data integrity and application functionality

## Data Retention

| Data Type | Retention | Policy |
|-----------|-----------|--------|
| Database backups | 30 days daily, 12 weeks weekly | Automated |
| S3 media objects | 365 days | Lifecycle policy |
| Queue failed jobs | 7 days | Auto-cleanup |
| Application logs | 14 days | Log rotation |
| Audit logs | 90 days | Immutable storage |

## Testing

Restore tests are run weekly in staging. Results are documented and retained for 90 days.
