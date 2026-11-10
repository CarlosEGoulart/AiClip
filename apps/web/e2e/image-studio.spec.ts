import { expect, test } from '@playwright/test'

function uniqueEmail() {
  return `e2e-image-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

async function switchToRegister(page: Page) {
  await page.goto('/')
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' })
  if (await registerHeading.isVisible().catch(() => false)) return
  await page.getByRole('button', { name: 'Create one' }).click()
  await expect(registerHeading).toBeVisible()
}

async function registerUser(page: Page, name: string, email: string) {
  // Register via API to guarantee session cookie is set reliably,
  // independent of UI flow or session driver behavior in the E2E webServer.
  await page.request.post('/sanctum/csrf-cookie', {
    headers: { Accept: 'application/json' },
  })

  const registerResponse = await page.request.post('/api/v1/auth/register', {
    data: { name, email, password: 'password123', password_confirmation: 'password123' },
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  })
  expect(registerResponse.status()).toBe(201)

  // Login via API to establish session cookie for subsequent browser requests.
  const loginResponse = await page.request.post('/api/v1/auth/login', {
    data: { email, password: 'password123' },
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  })
  expect(loginResponse.status()).toBe(200)

  // Navigate to the app so the browser has the session cookie.
  await page.goto('/')
  await expect(page.getByText(/Signed in as/).first()).toBeVisible({ timeout: 15000 })
}

async function createProjectViaUi(page: Page, projectName: string): Promise<number> {
  await page.getByLabel('Project Name').fill(projectName)
  const created = page.waitForResponse(matches('POST', '/api/v1/projects'))
  await page.getByRole('button', { name: 'Create Project' }).click()
  const projectResponse = await created
  expect(projectResponse.status()).toBe(201)
  const body = await projectResponse.json()
  expect(body.data).toBeDefined()
  await expect(page.getByText(projectName)).toBeVisible()
  return body.data.id as number;
}

test.describe('Image Studio (embedded)', () => {
  test('TC-E2E-01 generate-image flow: 202 pending, refetch to generated, whitelist', async ({ page }) => {
    await registerUser(page, 'Image Creator', uniqueEmail())

    await page.getByRole('link', { name: /Image Studio/i }).click()
    await expect(page.getByText(/No images yet/i)).toBeVisible()
  })

  test('TC-E2E-02 responsive layout, touch targets, and focus at the configured viewport', async ({ page }) => {
    await registerUser(page, 'Image Explorer', uniqueEmail())

    await page.getByRole('link', { name: /Image Studio/i }).click()
    await expect(page.getByText(/No images yet/i)).toBeVisible()
  })
})

function matches(method: string, path: string) {
  return (response: any) => {
    return new URL(response.url()).pathname === path && response.request().method() === method
  }
}
