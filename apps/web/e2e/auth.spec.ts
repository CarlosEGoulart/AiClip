import { expect, test } from '@playwright/test'

function uniqueEmail() {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
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

test.describe('Auth Lifecycle', () => {
  test('guest → register → authenticated', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'E2E User', email)

    await expect(page.getByText(/Signed in as/).first()).toBeVisible()
    await expect(page.getByText('E2E User').first()).toBeVisible()
    await expect(page.getByText(email).first()).toBeVisible()
  })

  test('authenticated → page reload → still authenticated', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Reload User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    await page.reload()
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()
    await expect(page.getByText('Reload User').first()).toBeVisible()
  })

  test('authenticated → logout → guest', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Logout User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    await page.getByRole('button', { name: 'Log out' }).first().click()
    await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible()
    await expect(page.getByText(/Signed in as/).first()).not.toBeVisible()
  })

  test('old authentication no longer works after logout', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Replay User', email)
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()

    const cookies = await page.context().cookies()
    await page.getByRole('button', { name: 'Log out' }).first().click()
    await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible()

    await page.context().addCookies(cookies)
    await page.goto('/')
    await expect(page.getByText(/Signed in as/).first()).not.toBeVisible()
  })

  test('duplicate registration → validation feedback', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'First User', email)

    // Try to register again with same email
    await page.getByRole('button', { name: 'Create one' }).click()
    await page.getByRole('heading', { name: 'Create your workspace' }).first()
    await page.getByLabel('Name').fill('Second User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password', { exact: true }).fill('password123')
    await page.getByLabel('Confirm Password', { exact: true }).fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()
    await expect(page.getByText(/already taken|already exists/i).first()).toBeVisible()
  })

  test('password confirmation mismatch → validation feedback', async ({ page }) => {
    const email = uniqueEmail()
    await switchToRegister(page)
    await page.getByLabel('Name').fill('Test User')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password', { exact: true }).fill('password123')
    await page.getByLabel('Confirm Password', { exact: true }).fill('different123')
    await page.getByRole('button', { name: 'Create Account' }).click()
    await expect(page.getByText(/confirmation/i).first()).toBeVisible()
  })

  test('CSRF cookie is set and XSRF token is sent with state-changing requests', async ({ page }) => {
    await page.goto('/')
    await page.request.post('/sanctum/csrf-cookie', {
      headers: { Accept: 'application/json' },
    })
    await page.getByRole('heading', { name: 'Sign in to your workspace' }).or(page.getByRole('heading', { name: 'Create your workspace' })).first()

    const cookies = await page.context().cookies()
    const xsrfCookie = cookies.find(c => c.name === 'XSRF-TOKEN')
    expect(xsrfCookie).toBeDefined()

    await switchToRegister(page)
    await page.getByLabel('Name').fill('CSRF User')
    await page.getByLabel('Email').fill(uniqueEmail())
    await page.getByLabel('Password', { exact: true }).fill('password123')
    await page.getByLabel('Confirm Password', { exact: true }).fill('password123')
    await page.getByRole('button', { name: 'Create Account' }).click()
    await expect(page.getByText(/Signed in as/).first()).toBeVisible()
  })

  test('no authentication bearer token in localStorage or sessionStorage', async ({ page }) => {
    await registerUser(page, 'Storage User', uniqueEmail())

    const localStorageKeys = await page.evaluate(() => Object.keys(localStorage))
    const sessionStorageKeys = await page.evaluate(() => Object.keys(sessionStorage))

    expect(localStorageKeys).not.toContain('access_token')
    expect(sessionStorageKeys).not.toContain('access_token')
    expect(localStorageKeys).not.toContain('refresh_token')
    expect(sessionStorageKeys).not.toContain('refresh_token')
  })
})
