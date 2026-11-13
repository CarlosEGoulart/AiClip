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
 *   2. Loads the app in the browser and waits for hydration (`networkidle`)
 *      so `getMe()` completes before checking auth state.
 *   3. If the register API call already carried the `laravel_session` cookie
 *      into the browser context (page.request shares cookies with the context),
 *      the app renders the authenticated shell immediately — return early.
 *   4. Otherwise ensure the login view is showing, handling the case where the
 *      app shows "Create your workspace" (onboarding) instead of the login
 *      heading by clicking "Sign in" to switch views.
 *   5. Logs in through the browser UI (fill form + click submit).
 *   6. Waits for "Signed in as" as the proven-authenticated gate.
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

  // 3. Wait for hydration (including getMe()) to complete before branching.
  //    Without this, the Promise.race below may resolve while the React app is
  //    still in an intermediate "Checking session..." state where none of the
  //    target headings are visible yet.
  await page.waitForLoadState('networkidle')

  // 4. Wait for EITHER the authenticated shell ("Signed in as") OR a
  //    login/registration heading to appear. Using Promise.race ensures we
  //    wait for hydration before branching, so we never proceed to a heading
  //    that will never appear (e.g. the login heading when already
  //    authenticated from the register API cookie).
  const signedInAs = page.getByText(/Signed in as/).first()
  const loginHeading = page.getByRole('heading', {
    name: 'Sign in to your workspace',
  })
  const createHeading = page.getByRole('heading', {
    name: 'Create your workspace',
  })

  await Promise.race([
    signedInAs.waitFor({ state: 'visible', timeout: 30000 }),
    loginHeading.waitFor({ state: 'visible', timeout: 30000 }),
    createHeading.waitFor({ state: 'visible', timeout: 30000 }),
  ])

  // 4. If "Signed in as" is visible → already authenticated from the register
  //    API cookie carried into the browser context — return early.
  if (await signedInAs.isVisible()) {
    return
  }

  // 5. Otherwise ensure the login view is showing. The app may render the
  //    onboarding view ("Create your workspace") instead of the login view;
  //    click "Sign in" to switch to login, then wait for the login heading.
  if (await createHeading.isVisible()) {
    await page.getByRole('button', { name: 'Sign in' }).click()
    await loginHeading.waitFor({ state: 'visible', timeout: 30000 })
  }

  // 6. Fill the login form via the browser UI.
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill('password123')

  // 7. Submit the form.
  await page.getByRole('button', { name: 'Sign In' }).click()

  // 8. Proven-authenticated gate: the authenticated shell must render.
  await expect(signedInAs).toBeVisible({ timeout: 30000 })
}
