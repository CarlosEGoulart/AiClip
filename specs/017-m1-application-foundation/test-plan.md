# Test Plan: Issue #17

## Backend Tests (Pest)

| Test | Scenario | Expected |
|------|----------|----------|
| test_health_returns_200 | GET /api/v1/health with DB connected | 200 |
| test_health_returns_json | Response is valid JSON | JSON |
| test_health_has_status_field | Response contains status field | "ok" |
| test_health_has_database_field | Response contains database field | "connected" |
| test_health_has_timestamp_field | Response contains timestamp field | ISO 8601 |
| test_health_queries_database | Actually executes SELECT 1 | Query runs |
| test_health_returns_503_on_db_failure | DB connection fails | 503 |

## Frontend Tests (Vitest + RTL)

| Test | Scenario | Expected |
|------|----------|----------|
| test_renders_loading_state | Component mounts | Loading shown |
| test_renders_success_state | API returns 200 | Success shown |
| test_renders_error_state | API returns error | Error shown |
| test_api_client_makes_request | Call health API | Correct URL |

## E2E Tests (Playwright)

| Test | Scenario | Expected |
|------|----------|----------|
| test_health_page_shows_success | Navigate to health page | Success displayed |
| test_loading_state_visible | Page loads | Loading shown briefly |
| test_responsive_mobile | 390x844 viewport | No overflow |
| test_responsive_tablet | 768x1024 viewport | No overflow |
| test_responsive_desktop | 1440x900 viewport | No overflow |
