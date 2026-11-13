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
  // Get CSRF cookie via API (APIRequestContext manages cookies)
  await page.request.post('/sanctum/csrf-cookie', {
    headers: { Accept: 'application/json' },
  })

  // Extract XSRF-TOKEN cookie value from the csrf-cookie response
  const csrfSetCookie = page.request.post('/sanctum/csrf-cookie', {
    headers: { Accept: 'application/json' },
  })
  // NOTE: We already called csrf-cookie above. Extract XSRF-TOKEN from it.
  
  // Actually, better approach: call csrf-cookie once, extract XSRF-TOKEN, then use it
  const csrfResponse = await page.request.post('/sanctum/csrf-cookie', {
    headers: { Accept: 'application/json' },
  })
  const csrfSetCookieHeader = csrfResponse.headers()['set-cookie']
  const csrfHeaders = Array.isArray(csrfSetCookieHeader) ? csrfSetCookieHeader : [csrfSetCookieHeader]
  let xsrfToken = ''
  for (const h of csrfHeaders) {
    const m = h.match(/XSRF-TOKEN=([^;]+)/)
    if (m) { xsrfToken = m[1]; break }
  }

  // Register via API with X-XSRF-TOKEN header
  const registerResponse = await page.request.post('/api/v1/auth/register', {
    data: { name, email, password: 'password123', password_confirmation: 'password123' },
    headers: { 
      'Accept': 'application/json', 
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': xsrfToken,
    },
  })
  expect(registerResponse.status()).toBe(201)

  // Login via API with X-XSRF-TOKEN header
  const loginResponse = await page.request.post('/api/v1/auth/login', {
    data: { email, password: 'password123' },
    headers: { 
      'Accept': 'application/json', 
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': xsrfToken,
    },
  })
  expect(loginResponse.status()).toBe(200)

  // Extract laravel_session from login response Set-Cookie header
  const loginSetCookie = loginResponse.headers()['set-cookie']
  const loginHeaders = Array.isArray(loginSetCookie) ? loginSetCookie : [loginSetCookie]
  let sessionValue = ''
  for (const h of loginHeaders) {
    const m = h.match(/laravel_session=([^;]+)/)
    if (m) { sessionValue = m[1]; break }
  }

  // Set session cookie in browser context (host-only, no domain attr = sent to 127.0.0.1:any port)
  if (sessionValue) {
    await page.context().addCookies([{
      name: 'laravel_session',
      value: sessionValue,
      path: '/',
    }])
  }

  // Navigate to app - browser sends session cookie via Vite proxy to Laravel
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
