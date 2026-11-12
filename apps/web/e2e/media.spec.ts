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
  await page.request.post('/sanctum/csrf-cookie', {
    headers: { Accept: 'application/json' },
  })

  const registerResponse = await page.request.post('/api/v1/auth/register', {
    data: { name, email, password: 'password123', password_confirmation: 'password123' },
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
  })
  expect(registerResponse.status()).toBe(201)

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
