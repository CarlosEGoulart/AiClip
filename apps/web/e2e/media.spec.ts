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
  // Navigate to app (gets CSRF cookie via Vite proxy)
  await page.goto('/');

  // Fetch CSRF cookie via browser (browser manages cookies)
  await page.evaluate(async () => {
    await fetch('/sanctum/csrf-cookie', {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      credentials: 'include',
    });
  });

  // Switch to register if needed
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' });
  if (!(await registerHeading.isVisible().catch(() => false))) {
    await page.getByRole('button', { name: 'Create one' }).click();
    await expect(registerHeading).toBeVisible();
  }

  // Fill form via UI
  await page.getByLabel('Name').fill(name);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password123');
  await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Create Account' }).click();

  // Wait for session established
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
