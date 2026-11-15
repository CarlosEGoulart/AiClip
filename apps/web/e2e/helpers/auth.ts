import { expect, type Page } from '@playwright/test'

/**
 * Shared, UI-based authentication helper for E2E specs.
 *
 * Registers users entirely through the browser UI (matching the proven-passing
 * pattern in apps/web/tests/e2e/auth-functional.spec.ts). The previous hybrid
 * approach used `page.request.post('/api/v1/auth/register')` then UI login, but
 * `page.request` uses a separate cookie jar from the browser context, so the
 * `laravel_session` cookie from the API response did NOT reliably reach the
 * browser. This caused unpredictable auth state after `page.goto('/')`.
 *
 * Instead this helper:
 *   1. Loads the app in the browser and waits for hydration (`networkidle`)
 *   2. Waits for the login or register heading
 *   3. If on the login view, clicks "Create one" to switch to the register view
 *   4. Fills the register form via browser UI (Name, Email, Password, Confirm Password)
 *   5. Clicks "Create Account"
 *   6. Waits for "Signed in as" as the proven-authenticated gate
 *
 * The session cookie is set by Laravel on the real register response through
 * the browser's own cookie jar, so the browser context stays consistent.
 */
export async function registerUser(
  page: Page,
  name: string,
  email: string,
): Promise<void> {
  // 1. Navigate to app and wait for hydration
  await page.goto('/')
  await page.waitForLoadState('networkidle')

  // 2. Wait for login or register heading
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }).or(
      page.getByRole('heading', { name: 'Create your workspace' })
    )
  ).toBeVisible({ timeout: 15000 })

  // 3. If on login view, switch to register view
  const loginHeading = page.getByRole('heading', { name: 'Sign in to your workspace' })
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' })
  if (await loginHeading.isVisible()) {
    await page.getByRole('button', { name: 'Create one' }).click()
    await expect(registerHeading).toBeVisible()
  }

  // 4. Fill register form via browser UI
  await page.getByLabel('Name').fill(name)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('password123')
  await page.getByLabel('Confirm Password', { exact: true }).fill('password123')

  // 5. Submit
  await page.getByRole('button', { name: 'Create Account' }).click()

  // 6. Wait for authenticated shell (proven-authenticated gate)
  await expect(page.getByText(/Signed in as/).first()).toBeVisible({ timeout: 15000 })
}
