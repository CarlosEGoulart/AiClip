import { test, expect } from '@playwright/test'
import { trackCleanliness, expectClean } from './track-cleanliness'

function uniqueEmail() {
  return `e2e-image-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
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

async function createProjectViaUi(page: import('@playwright/test').Page, projectName: string): Promise<number> {
  await page.getByLabel('Project Name').fill(projectName)
  const created = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname === '/api/v1/projects' &&
      response.request().method() === 'POST',
  );
  await page.getByRole('button', { name: 'Create Project' }).click()
  const projectResponse = await created
  expect(projectResponse.status()).toBe(201)
  const body = await projectResponse.json()
  expect(body.data).toBeDefined()
  await expect(page.getByText(projectName)).toBeVisible()
  return body.data.id as number
}

async function openProject(page: import('@playwright/test').Page, projectName: string) {
  await page.getByRole('button', { name: `Open ${projectName}` }).click()
  await expect(page.getByRole('button', { name: 'Back to projects' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'Image Studio' })).toBeVisible()
}

async function openImageStudioTab(page: import('@playwright/test').Page): Promise<Response> {
  const imagesSeen = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/images' && response.request().method() === 'GET')
  await page.getByRole('tab', { name: 'Image Studio' }).click()
  const response = await imagesSeen
  expect(response.status()).toBe(200)

  const body = await response.json()
  expect(Object.keys(body.data).sort()).toEqual(['data', 'links', 'meta'])
  expect(body.data.data).toEqual([])
  expect(body.data.meta.total).toBe(0)

  await expect(page.getByText('No images yet')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Image Studio', exact: true })).toBeVisible()

  return response
}

const PROMPT = 'A serene mountain lake at sunrise'

async function runGenerateFlow(page: import('@playwright/test').Page) {
  await page.getByLabel('Prompt').fill(PROMPT)
  await page.getByLabel('Style preset').selectOption('cinematic')
  await page.getByLabel('Dimensions').selectOption('vertical')

  const generateSeen = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/images/generate' && response.request().method() === 'POST')
  const refetchSeen = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/images' && response.request().method() === 'GET')
  await page.getByRole('button', { name: 'Generate' }).click()

  const generateResponse = await generateSeen
  expect(generateResponse.status()).toBe(202)
  const generateBody = await generateResponse.json()
  expect(generateBody.data.status).toBe('pending')
  expect(generateBody.data.download_url).toBeNull()
  expect(Object.keys(generateBody.data).sort()).toEqual(['created_at', 'download_url', 'height', 'id', 'mime_type', 'parameters', 'project_id', 'prompt', 'size_bytes', 'status', 'updated_at', 'width'])
  expect(JSON.stringify(generateBody)).not.toContain('storage_disk')
  expect(JSON.stringify(generateBody)).not.toContain('storage_key')
  expect(JSON.stringify(generateBody)).not.toContain('user_id')
  expect(JSON.stringify(generateBody)).not.toContain('bucket')

  const pendingBadge = page.getByText('Pending')
  if (await pendingBadge.isVisible()) {
    await expect(pendingBadge).toHaveClass(/bg-gray-100/)
  }

  const refetchResponse = await refetchSeen
  expect(refetchResponse.status()).toBe(200)
  const refetchBody = await refetchResponse.json()
  expect(Object.keys(refetchBody.data).sort()).toEqual(['data', 'links', 'meta'])
  expect(JSON.stringify(refetchBody)).not.toContain('storage_disk')
  expect(JSON.stringify(refetchBody)).not.toContain('storage_key')
  expect(JSON.stringify(refetchBody)).not.toContain('user_id')
  expect(JSON.stringify(refetchBody)).not.toContain('bucket')

  await expect(page.getByText('Generated')).toBeVisible({ timeout: 10000 })
  await expect(page.getByText('Generated')).toHaveClass(/bg-green-100/)
  await expect(page.getByText('Pending')).toHaveCount(0)
  await expect(page.getByText('Generating…')).toHaveCount(0)
  await expect(page.getByText('1080 × 1920')).toBeVisible()
  await expect(page.getByText(/KB/)).toBeVisible()
  await expect(page.getByRole('list', { name: 'Generated images' }).getByText(PROMPT)).toBeVisible()
  await expect(page.getByRole('listitem')).toHaveCount(1)

  const generatedItem = refetchBody.data.data[0]
  expect(typeof generatedItem.download_url).toBe('string')
  expect(generatedItem.download_url).toContain(`images/${generatedItem.id}/download`)
  expect(generatedItem.download_url).toContain('signature=')
  expect(generatedItem.download_url).toContain('expires=')

  const preview = page.getByRole('img', { name: /^Generated image:/ })
  await expect(preview).toBeVisible()
  await expect(
    page.evaluate((element: HTMLImageElement) => element.naturalWidth, preview),
    { timeout: 10000 },
  ).toBeGreaterThan(0)

  const downloadLink = page.getByRole('link', { name: 'Download image' })
  await expect(downloadLink).toHaveAttribute('href', /api\/v1\/images\/\d+\/download\//)
  await expect(downloadLink).toHaveAttribute('href', /signature=/)
  await expect(downloadLink).toHaveAttribute('href', /expires=/)
}

async function cleanupProject(page: import('@playwright/test').Page, projectName: string) {
  await page.getByRole('button', { name: 'Back to projects' }).click()
  await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible()
  await page.getByRole('button', { name: `Delete ${projectName}` }).click()
  await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click()
  await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 })
}

async function waitForAuthReady(page: import('@playwright/test').Page) {
  await page.goto('/')
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }).or(page.getByRole('heading', { name: 'Create your workspace' })),
  ).toBeVisible({ timeout: 15000 })
}

test.describe('Image Studio (embedded)', () => {
  test('TC-E2E-01 generate-image flow: 202 pending, refetch to generated, whitelist', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Image Creator', email)

    const projectName = 'Image Studio Project'
    await createProjectViaUi(page, projectName)
    await openProject(page, projectName)
    await openImageStudioTab(page)

    await runGenerateFlow(page)

    await cleanupProject(page, projectName)
    await expectClean(page, cleanliness)
  })

  test('TC-E2E-02 responsive layout, touch targets, and focus at the configured viewport', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Image Explorer', email)

    const projectName = 'Responsive Studio Project'
    await createProjectViaUi(page, projectName)
    await openProject(page, projectName)
    await openImageStudioTab(page)

    await runGenerateFlow(page)

    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }))
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth)

    const layout = await page.evaluate(() => {
      const grid = document.querySelector('.image-studio-layout')
      if (!grid) return null
      const [form, gallery] = Array.from(grid.children) as HTMLElement[]
      const formBox = form.getBoundingClientRect()
      const galleryBox = gallery.getBoundingClientRect()
      return {
        columns: getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
        formTop: formBox.top,
        galleryTop: galleryBox.top,
        formLeft: formBox.left,
        galleryLeft: galleryBox.left,
        formBottom: formBox.bottom,
        galleryBottom: galleryBox.bottom,
      }
    })
    expect(layout).not.toBeNull()
    const viewportWidth = page.viewportSize()?.width ?? 0
    if (viewportWidth < 1024) {
      expect(layout?.columns, 'layout grid column count').toBe(1)
      expect(layout!.formTop, 'form above gallery in single column').toBeLessThan(layout!.galleryTop)
    } else {
      expect(layout?.columns, 'layout grid column count').toBe(2)
      expect(layout!.formLeft, 'form left of gallery side-by-side').toBeLessThan(layout!.galleryLeft)
    }

    const clipped = await page.evaluate(() => {
      const viewportWidth = window.innerWidth
      const elements = [
        document.querySelector('.image-studio header'),
        document.querySelector('#image-prompt'),
        document.querySelector('#image-style-preset'),
        document.querySelector('#image-dimensions'),
        document.querySelector('.image-studio button[type="submit"]'),
        document.querySelector('.image-studio [role="list"]'),
        document.querySelector('.image-gallery-card-preview'),
      ]
      return elements.filter((element) => {
        if (!element) return true
        const box = element.getBoundingClientRect()
        return box.left < -0.5 || box.right > viewportWidth + 0.5
      }).length
    })
    expect(clipped, 'elements clipped by the viewport').toBe(0)

    const touchTargets = await page.evaluate(() => {
      const height = (selector: string) =>
        document.querySelector(selector)?.getBoundingClientRect().height ?? 0
      return {
        'image-studio-tab': height('#image-studio-tab'),
        prompt: height('#image-prompt'),
        'style-preset': height('#image-style-preset'),
        dimensions: height('#image-dimensions'),
        generate: height('.image-studio button[type="submit"]'),
        download: height('.image-gallery-download'),
        'back-to-projects': height('button[aria-label="Back to projects"]'),
      }
    })
    for (const [control, height] of Object.entries(touchTargets)) {
      expect(height, `${control} touch-target height`).toBeGreaterThanOrEqual(44)
    }

    await page.getByRole('tab', { name: 'Image Studio' }).click()
    const focusVisible: Record<string, boolean> = {}
    const recordFocusedTarget = () =>
      page.evaluate((targets: FocusTarget[]) => {
        const element = document.activeElement
        if (!element) return null
        const match = targets.find((target) => document.querySelector(target.selector) === element)
        if (!match) return null
        const style = getComputedStyle(element)
        return {
          key: match.key,
          visible:
            (parseFloat(style.outlineWidth) > 0 && style.outlineStyle !== 'none') ||
            style.boxShadow !== 'none',
        }
      }, IMAGE_STUDIO_FOCUS_TARGETS)

    await page.keyboard.press('Shift+Tab')
    await page.keyboard.press('Tab')
    let hit = await recordFocusedTarget()
    if (hit) focusVisible[hit.key] = hit.visible

    for (
      let i = 0;
      i < 8 && Object.keys(focusVisible).length < IMAGE_STUDIO_FOCUS_TARGETS.length;
      i++
    ) {
      await page.keyboard.press('Tab')
      hit = await recordFocusedTarget()
      if (hit) focusVisible[hit.key] = hit.visible
    }

    expect(Object.keys(focusVisible), 'reached focus targets').toHaveLength(
      IMAGE_STUDIO_FOCUS_TARGETS.length,
    )
    for (const target of IMAGE_STUDIO_FOCUS_TARGETS) {
      expect(focusVisible[target.key], `${target.key} visible focus indicator`).toBe(true)
    }

    await cleanupProject(page, projectName)
    await expectClean(page, cleanliness)
  })
})
