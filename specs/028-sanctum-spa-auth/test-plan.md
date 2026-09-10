# Test Plan: Sanctum SPA Authentication

## Backend Tests

- Registration: 201, hashed password, no password in response, authenticated after
- Registration validation: missing name, invalid email, duplicate email, missing password, password mismatch
- Login: valid credentials, incorrect password, unknown email, missing credentials
- Current user: guest 401, authenticated returns sanitized user
- Logout: success, session invalidated, guest 401

## Frontend Tests

- Auth components render correctly
- Forms submit correctly
- Error states display

## E2E Tests

- Registration flow
- Login flow
- Logout flow
- Page refresh preserves auth
