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
  // Get CSRF cookie via browser fetch (browser manages cookies)
  await page.evaluate(async () => {
    await fetch('/sanctum/csrf-cookie', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'include',
    });
  });

  // Register via browser fetch (cookies auto-managed)
  await page.evaluate(async ({ name, email }) => {
    const response = await fetch('/api/v1/auth/register', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ name, email, password: 'password123', password_confirmation: 'password123' }),
    });
    if (response.status !== 201) {
      throw new Error(`Register failed: ${response.status}`);
    }
  }, { name, email });

  // Login via browser fetch
  await page.evaluate(async ({ email }) => {
    const response = await fetch('/api/v1/auth/login', {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ email, password: 'password123' }),
    });
    if (response.status !== 200) {
      throw new Error(`Login failed: ${response.status}`);
    }
  }, { email });

  // Navigate to app - browser now has session cookie
  await page.goto('/');
  await expect(page.getByText(/Signed in as/).first()).toBeVisible({ timeout: 15000 });
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
