import { expect, type Page } from '@playwright/test'

/**
 * Shared, UI-based authentication helper for E2E specs.
 *
 * Replaces the fragile per-spec pattern of registering via `page.request` API
 * calls and manually transferring the `laravel_session` cookie into the
 * browser context. That cookie transfer did not reliably establish a session
 * for browser-initiated `GET /api/v1/auth/me` through the Vite proxy, so the
 * app rendered the guest login view and "Signed in as" never appeared.
 *
 * Instead this helper:
 *   1. Creates the user deterministically via the register API.
 *   2. Loads the app in the browser.
 *   3. Logs in through the browser UI (fill form + click submit).
 *   4. Waits for "Signed in as" as the proven-authenticated gate.
 *
 * All state-changing browser requests flow through the app's own CSRF/XSRF
 * handling, so the session cookie is set by Laravel on the real login
 * response and the browser context stays consistent.
 */
export async function registerUser(
  page: Page,
  name: string,
  email: string,
): Promise<void> {
  // 1. Create the user deterministically via the API.
  const registerResponse = await page.request.post('/api/v1/auth/register', {
    data: {
      name,
      email,
      password: 'password123',
      password_confirmation: 'password123',
    },
    headers: { Accept: 'application/json' },
  })
  expect(registerResponse.status()).toBe(201)

  // 2. Load the app (Vite dev server on 5173; /api proxied to Laravel on 8000).
  await page.goto('/')

  // 3. Wait for the login view to be ready.
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }),
  ).toBeVisible({ timeout: 15000 })

  // 4. Fill the login form via the browser UI.
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill('password123')

  // 5. Submit the form.
  await page.getByRole('button', { name: 'Sign In' }).click()

  // 6. Proven-authenticated gate: the authenticated shell must render.
  await expect(page.getByText(/Signed in as/).first()).toBeVisible({
    timeout: 15000,
  })
}
