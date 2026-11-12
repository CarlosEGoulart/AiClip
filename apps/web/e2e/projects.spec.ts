import { expect, test } from '@playwright/test'

function uniqueEmail() {
  return `e2e-proj-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

function uniqueProjectName() {
  return `Project ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`
}

async function waitForAuthReady(page: import('@playwright/test').Page) {
  await page.goto('/')
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }).or(page.getByRole('heading', { name: 'Create your workspace' })),
  ).toBeVisible({ timeout: 15000 })
}

async function switchToRegister(page: import('@playwright/test').Page) {
  await waitForAuthReady(page)
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' })
  if (await registerHeading.isVisible().catch(() => false)) return
  await page.getByRole('button', { name: 'Create one' }).click()
  await expect(registerHeading).toBeVisible()
}

async function registerUser(page: import('@playwright/test').Page, name: string, email: string) {
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

  // Transfer session cookies from API responses to browser context
  const allCookies = [];
  for (const resp of [registerResponse, loginResponse]) {
    const setCookieHeader = resp.headers()['set-cookie'];
    if (!setCookieHeader) continue;
    const setCookieHeaders = Array.isArray(setCookieHeader)
      ? setCookieHeader
      : [setCookieHeader];
    for (const header of setCookieHeaders) {
      const match = header.match(/^(laravel_session|XSRF-TOKEN)=([^;]+)/);
      if (match) {
        allCookies.push({
          name: match[1],
          value: match[2],
          domain: '127.0.0.1',
          path: '/',
        });
      }
    }
  }
  if (allCookies.length > 0) {
    await page.context().addCookies(allCookies);
  }

  // Navigate to the app so the browser has the session cookie.
  await page.goto('/')
  await expect(page.getByText(/Signed in as/).first()).toBeVisible({ timeout: 15000 })
}

test.describe('Project Management', () => {
  test('authenticated user can see project creation form', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Project User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()
    await expect(page.getByText('Create New Project')).toBeVisible()
    await expect(page.getByLabel('Project Name')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Create Project' })).toBeVisible()
  })

  test('user can create a project after login', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Creator User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()
  })

  test('project persists after page reload', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Persistent User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()

    await page.reload()
    await expect(page.getByText(projectName)).toBeVisible()
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()
  })

  test('user can delete own project', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Deleter User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName = uniqueProjectName()
    await page.getByLabel('Project Name').fill(projectName)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName)).toBeVisible()

    await page.getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(page.getByRole('button', { name: 'Confirm delete project' })).toBeVisible()
    await page.getByRole('button', { name: 'Confirm delete project' }).click()
    await expect(page.getByText(projectName)).not.toBeVisible()
  })

  test('validation errors displayed for empty project name', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Validation User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(/required/i)).toBeVisible()
  })

  test('multiple projects are listed', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Multi User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const projectName1 = uniqueProjectName()
    const projectName2 = uniqueProjectName()

    await page.getByLabel('Project Name').fill(projectName1)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName1)).toBeVisible()

    await page.getByLabel('Project Name').fill(projectName2)
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByText(projectName2)).toBeVisible()

    const cards = page.locator('[role="article"]')
    await expect(cards).toHaveCount(2)
  })
})
