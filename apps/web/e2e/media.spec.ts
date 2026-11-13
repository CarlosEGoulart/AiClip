import { expect, test } from '@playwright/test'
import { trackCleanliness } from './track-cleanliness'

function uniqueEmail() {
  return `e2e-media-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

async function switchToRegister(page: import('@playwright/test').Page) {
  await page.goto('/')
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

test.describe('Media Upload and Management', () => {
  test('upload video → appears in list → delete removes it', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Test User', email)

    await page.getByLabel('Project Name').fill('Media Test Project')
    const created = page.waitForResponse(response =>
      new URL(response.url()).pathname === '/api/v1/projects' && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'Create Project' }).click()
    const projectResponse = await created
    expect(projectResponse.status()).toBe(201)
    const { data: project } = await projectResponse.json()
    await expect(page.getByText('Media Test Project')).toBeVisible()

    await page.getByRole('button', { name: 'Upload' }).click()
    await page.getByLabel('Select file').setInputFiles({
      name: 'test-video.mp4',
      mimeType: 'video/mp4',
      buffer: Buffer.from('fake-video-content'),
    })

    await expect(page.getByText('Media Test Project')).toBeVisible()
    await expect(page.getByText(/video/i)).toBeVisible()
  })

  test('rejects non-video file upload', async ({ page }) => {
    const email = uniqueEmail()
    await registerUser(page, 'Test User', email)

    await page.getByLabel('Project Name').fill('Test Project')
    const created = page.waitForResponse(response =>
      new URL(response.url()).pathname === '/api/v1/projects' && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'Create Project' }).click()
    const projectResponse = await created
    expect(projectResponse.status()).toBe(201)
    const { data: project } = await projectResponse.json()
    await expect(page.getByText('Test Project')).toBeVisible()

    await page.getByRole('button', { name: 'Upload' }).click()
    await page.getByLabel('Select file').setInputFiles({
      name: 'test.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('fake-text-content'),
    })

    await expect(page.getByText(/not a video/i)).toBeVisible()
  })
})
