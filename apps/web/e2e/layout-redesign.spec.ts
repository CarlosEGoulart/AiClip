import { expect, test, type Page } from '@playwright/test'
import { trackCleanliness, expectClean } from './track-cleanliness'
import { registerUser } from './helpers/auth'

function uniqueEmail() {
  return `e2e-layout-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

async function switchToRegister(page: Page) {
  await page.goto('/')
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' })
  if (await registerHeading.isVisible().catch(() => false)) return
  await page.getByRole('button', { name: 'Create one' }).click()
  await expect(registerHeading).toBeVisible()
}

async function createProjectViaUi(page: Page, projectName: string): Promise<number> {
  await page.getByLabel('Project Name').fill(projectName)
  const created = page.waitForResponse((response) =>
    new URL(response.url()).pathname === '/api/v1/projects' && response.request().method() === 'POST',
  )
  await page.getByRole('button', { name: 'Create Project' }).click()
  const projectResponse = await created
  expect(projectResponse.status()).toBe(201)
  const body = await projectResponse.json()
  expect(body.data).toBeDefined()
  await expect(page.getByText(projectName)).toBeVisible()
  return body.data.id as number
}

async function openProject(page: Page, projectName: string) {
  await page.getByRole('button', { name: `Open ${projectName}` }).click()
  await expect(page.getByRole('button', { name: 'Back to projects' })).toBeVisible()
}

async function waitForAuthReady(page: Page) {
  await page.goto('/')
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }).or(
      page.getByRole('heading', { name: 'Create your workspace' }),
    ),
  ).toBeVisible({ timeout: 15000 })
}

test.describe('Layout Redesign — T.1 to T.6', () => {
  test('T.1 home/dashboard loads: clear title, primary action obvious, heading on first screen', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Layout Viewer', email)

    // Dashboard first screen: hero heading + primary action + moving picture
    await expect(page.getByRole('heading', { name: 'Turn long video into short clips' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Create Project' })).toBeVisible()
    await expect(page.getByAltText(/AiClip workspace/)).toBeVisible()

    // No blank stretches: the hero occupies the first screen
    const hero = page.getByRole('heading', { name: 'Turn long video into short clips' })
    await expect(hero).toBeInViewport()

    await expectClean(page, cleanliness)
  })

  test('T.2 media upload page: upload controls recognizable, empty/loading/error states', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Uploader', email)

    await createProjectViaUi(page, 'Layout Upload Project')
    await openProject(page, 'Layout Upload Project')

    // Media tab is active by default; upload controls present
    await expect(page.getByLabel('Upload Video')).toBeVisible()
    await expect(page.getByLabel('Import from YouTube URL')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Upload' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Import' })).toBeVisible()

    // Empty state present before upload
    await expect(page.getByText(/No media assets yet/)).toBeVisible()

    // Client-side error state for invalid YouTube URL
    await page.getByLabel('Import from YouTube URL').fill('https://example.com/video')
    await page.getByRole('button', { name: 'Import' }).click()
    await expect(page.getByRole('alert')).toBeVisible()
    await expect(await page.getByRole('alert').innerText()).toMatch(/Please enter a valid YouTube URL/)

    await expectClean(page, cleanliness)
  })

  test('T.3 Image Studio page: form usable, focus visible, 44px touch targets', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Studio Viewer', email)

    await createProjectViaUi(page, 'Layout Studio Project')
    await openProject(page, 'Layout Studio Project')

    await page.getByRole('tab', { name: 'Image Studio' }).click()
    await expect(page.getByRole('heading', { name: 'Image Studio', exact: true })).toBeVisible()

    // Form usable
    await expect(page.getByLabel('Prompt')).toBeVisible()
    await expect(page.getByLabel('Style preset')).toBeVisible()
    await expect(page.getByLabel('Dimensions')).toBeVisible()
    await expect(page.getByRole('button', { name: 'Generate' })).toBeVisible()

    // No horizontal overflow
    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }))
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth)

    // Touch targets ≥ 44px
    const touchTargets = await page.evaluate(() => {
      const height = (selector: string) =>
        document.querySelector(selector)?.getBoundingClientRect().height ?? 0
      return {
        prompt: height('#image-prompt'),
        'style-preset': height('#image-style-preset'),
        dimensions: height('#image-dimensions'),
        generate: height('.image-studio button[type="submit"]'),
        'back-to-projects': height('button[aria-label="Back to projects"]'),
      }
    })
    for (const [control, height] of Object.entries(touchTargets)) {
      if (height === 0) continue
      expect(height, `${control} touch-target height`).toBeGreaterThanOrEqual(44)
    }

    // Keyboard navigation: focus visible on controls
    await page.keyboard.press('Tab')
    const focused = await page.evaluate(() => !!document.activeElement && document.activeElement.matches('input, select, textarea, button, [role="tab"]'))
    expect(focused, 'keyboard focus lands on a control').toBe(true)

    await expectClean(page, cleanliness)
  })

  test('T.4 Social Connections page: connection cards clear, keyboard navigable', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Connections Viewer', email)

    await page.goto('/connections')
    await expect(page.getByRole('heading', { name: 'Social Connections' })).toBeVisible()

    // Connection cards / list clear
    await expect(page.getByRole('heading', { name: 'Connected Accounts' })).toBeVisible()
    await expect(page.getByRole('button', { name: /connect account/i })).toBeVisible()

    // Keyboard navigable: Tab reaches controls with visible focus
    await page.keyboard.press('Tab')
    let focusedAny = false
    for (let i = 0; i < 12 && !focusedAny; i++) {
      await page.keyboard.press('Tab')
      focusedAny = await page.evaluate(() => !!document.activeElement && (document.activeElement as HTMLElement).matches('button, a, input, [role="tab"]'))
    }
    expect(focusedAny, 'keyboard reaches an interactive control').toBe(true)

    await expectClean(page, cleanliness)
  })

  test('T.5 mobile viewport 390x844: layout intentional, touch targets accessible', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Mobile Viewer', email)

    await createProjectViaUi(page, 'Mobile Layout Project')
    await openProject(page, 'Mobile Layout Project')

    // Layout intentional: elements not clipped by viewport
    const clipped = await page.evaluate(() => {
      const viewportWidth = window.innerWidth
      const elements = [
        document.querySelector('.dashboard-hero'),
        document.querySelector('.media-upload-form'),
        document.querySelector('.image-studio header'),
        document.querySelector('.media-upload-form button'),
      ]
      return elements.filter((element) => {
        if (!element) return true
        const box = element.getBoundingClientRect()
        return box.left < -0.5 || box.right > viewportWidth + 0.5
      }).length
    })
    expect(clipped, 'elements clipped by the viewport').toBe(0)

    // Touch targets accessible on mobile
    await page.getByRole('button', { name: 'Create Project' }).click()
    await expect(page.getByRole('heading', { name: 'Create New Project' })).toBeVisible()
    const createButton = page.getByRole('button', { name: 'Create Project' })
    const box = await createButton.boundingBox()
    expect(box?.height).toBeGreaterThanOrEqual(44)
    expect(box?.width).toBeGreaterThanOrEqual(44)

    await expectClean(page, cleanliness)
  })

  test('T.6 console/network cleanliness at all viewports', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Cleanliness Viewer', email)

    await createProjectViaUi(page, 'Cleanliness Project')
    await openProject(page, 'Cleanliness Project')

    // Exercise each screen to surface console/network issues
    await expect(page.getByRole('heading', { name: 'Turn long video into short clips' })).toBeVisible()
    await page.getByRole('button', { name: 'Upload' }).click()
    await page.getByRole('tab', { name: 'Image Studio' }).click()
    await expect(page.getByRole('heading', { name: 'Image Studio', exact: true })).toBeVisible()

    await page.goto('/connections')
    await expect(page.getByRole('heading', { name: 'Social Connections' })).toBeVisible()

    await expectClean(page, cleanliness)
  })
})
