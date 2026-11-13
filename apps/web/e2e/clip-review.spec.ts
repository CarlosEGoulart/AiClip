import { expect, test, type APIResponse, type Page, type Response } from '@playwright/test'
import { trackCleanliness, expectClean } from './track-cleanliness'
import { registerUser } from './helpers/auth'

function uniqueEmail() {
  return `e2e-clip-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
}

function isGet(path: string) {
  return (response: APIResponse) =>
    new URL(response.url()).pathname === path &&
    response.request().method() === 'GET'
}

async function expectEmptyReviewSection(page: Page, projectName: string) {
  await expect(page.getByText('Select a clip to review')).toBeVisible()
  await expect(page.getByRole('group', { name: 'Review actions' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toHaveCount(0)
  await expect(page.getByRole('group', { name: 'Feedback form' })).toHaveCount(0)
}

type FocusTarget = { key: string; selector: string }

const FOCUS_TARGETS: FocusTarget[] = [
  { key: 'back-to-projects', selector: 'button[aria-label="Back to projects"]' },
  { key: 'media-tab', selector: '#media-tab' },
  { key: 'review-tab', selector: '#review-tab' },
  { key: 'back-to-project', selector: 'button[aria-label="Back to project"]' },
  { key: 'logout', selector: '.logout-button' },
]

async function switchToRegister(page: Page) {
  await page.goto('/')
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' })
  if (await registerHeading.isVisible().catch(() => false)) return
  await page.getByRole('button', { name: 'Create one' }).click()
  await expect(registerHeading).toBeVisible()
}

async function createProjectViaUi(page: Page, projectName: string): Promise<number> {
  await page.getByLabel('Project Name').fill(projectName)
  const created = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname === '/api/v1/projects' &&
      response.request().method() === 'POST',
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
  await expect(page.getByRole('tab', { name: 'Review Clips' })).toBeVisible()
}

async function waitForAuthReady(page: Page) {
  await page.goto('/')
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }).or(page.getByRole('heading', { name: 'Create your workspace' })),
  ).toBeVisible({ timeout: 15000 })
}

async function openReviewTab(page: Page, projectId: number): Promise<Response> {
  const clipReviewsSeen = page.waitForResponse(
    new URL(response.url()).pathname === `/api/v1/projects/${projectId}/clip-reviews` &&
    response.request().method() === 'GET',
  )
  await page.getByRole('tab', { name: 'Review Clips' }).click()
  return clipReviewsSeen
}

test.describe('Clip Review Section (embedded)', () => {
  test('TC-E2E-01 review section discoverability and empty state', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Clip Reviewer', email)

    const projectName = 'Clip Review Project'
    const projectId = await createProjectViaUi(page, projectName)

    await openProject(page, projectName)

    const csrfSeen = page.waitForResponse(isGet('/sanctum/csrf-cookie'))
    const clipReviewsResponse = await openReviewTab(page, projectId)
    const csrfResponse = await csrfSeen
    expect(csrfResponse.status()).toBe(204)
    expect(clipReviewsResponse.status()).toBe(200)
    const body = await clipReviewsResponse.json()
    expect(Object.keys(body.data).sort()).toEqual(['data', 'links', 'meta'])
    expect(body.data.data).toEqual([])
    expect(body.data.meta.total).toBe(0)
    expect(body.data.meta.last_page).toBe(1)
    expect(body.data.links.next).toBeNull()
    expect(body.data.links.prev).toBeNull()

    await expectEmptyReviewSection(page, projectName)

    await expect(page.getByLabel('Clip video')).toHaveCount(0)
    await expect(page.getByRole('group', { name: 'Review actions' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toHaveCount(0)
    await expect(page.getByRole('group', { name: 'Feedback form' })).toHaveCount(0)

    await page.getByRole('button', { name: 'Back to project', exact: true }).click()
    await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible()
    await page.getByRole('button', { name: `Delete ${projectName}` }).click()
    await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click()
    await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 })

    await expectClean(page, cleanliness)
  })

  test('TC-E2E-02 empty section non-interactivity and navigation', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Clip Navigator', email)

    const projectName = 'Navigation Project'
    const projectId = await createProjectViaUi(page, projectName)

    await openProject(page, projectName)
    const firstResponse = await openReviewTab(page, projectId)
    expect(firstResponse.status()).toBe(200)
    const firstBody = await firstResponse.json()
    expect(Object.keys(firstBody.data).sort()).toEqual(['data', 'links', 'meta'])
    expect(firstBody.data.data).toEqual([])
    expect(firstBody.data.meta.total).toBe(0)
    expect(firstBody.data.meta.last_page).toBe(1)
    expect(firstBody.data.links.next).toBeNull()
    expect(firstBody.data.links.prev).toBeNull()
    await expectEmptyReviewSection(page, projectName)

    await expect(page.getByRole('group', { name: 'Review actions' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toHaveCount(0)

    const urlBefore = page.url()
    await page.keyboard.press('n')
    await page.keyboard.press('N')
    await page.keyboard.press('p')
    await page.keyboard.press('P')
    expect(page.url()).toBe(urlBefore)
    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBeNull()
    await expectEmptyReviewSection(page, projectName)

    await page.getByRole('tab', { name: 'Media' }).click()
    await expect(
      page.getByText('No media assets yet. Upload a video to get started.'),
    ).toBeVisible()
    const secondResponse = await openReviewTab(page, projectId)
    expect(secondResponse.status()).toBe(200)
    const secondBody = await secondResponse.json()
    expect(Object.keys(secondBody.data).sort()).toEqual(['data', 'links', 'meta'])
    expect(secondBody.data.data).toEqual([])
    expect(secondBody.data.meta.total).toBe(0)
    expect(secondBody.data.meta.last_page).toBe(1)
    expect(secondBody.data.links.next).toBeNull()
    expect(secondBody.data.links.prev).toBeNull()
    await expectEmptyReviewSection(page, projectName)

    await page.getByRole('button', { name: 'Back to project', exact: true }).click()
    await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible()

    await expectClean(page, cleanliness)
  })

  test('TC-E2E-03 responsive viewports: real review section', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Clip Explorer', email)

    const projectName = 'Responsive Project'
    const projectId = await createProjectViaUi(page, projectName)

    await openProject(page, projectName)
    const clipReviewsResponse = await openReviewTab(page, projectId)
    expect(clipReviewsResponse.status()).toBe(200)
    const body = await clipReviewsResponse.json()
    expect(Object.keys(body.data).sort()).toEqual(['data', 'links', 'meta'])
    expect(body.data.data).toEqual([])
    expect(body.data.meta.total).toBe(0)
    expect(body.data.meta.last_page).toBe(1)
    expect(body.data.links.next).toBeNull()
    expect(body.data.links.prev).toBeNull()
    await expectEmptyReviewSection(page, projectName)

    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }))
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth)

    const clippedElements = await page.evaluate(() => {
      const viewportWidth = window.innerWidth
      const elements = [
        document.querySelector('.review-header'),
        document.querySelector('.review-sidebar'),
        document.querySelector('.review-empty'),
      ]
      return elements.filter((element) => {
        if (!element) return true
        const box = element.getBoundingClientRect()
        return box.left < -0.5 || box.right > viewportWidth + 0.5
      }).length
    })
    expect(clippedElements, 'elements clipped by the viewport').toBe(0)

    const touchTargets = await page.evaluate(() => {
      const height = (selector: string) =>
        document.querySelector(selector)?.getBoundingClientRect().height ?? 0
      return {
        'back-to-projects': height('button[aria-label="Back to projects"]'),
        'media-tab': height('#media-tab'),
        'review-tab': height('#review-tab'),
        'back-to-project': height('button[aria-label="Back to project"]'),
        logout: height('.logout-button'),
      }
    })
    for (const [control, height] of Object.entries(touchTargets)) {
      expect(height, `${control} touch-target height`).toBeGreaterThanOrEqual(44)
    }

    const gridColumns = await page.evaluate(
      () =>
        getComputedStyle(document.querySelector('.review-layout')).gridTemplateColumns
          .split(' ')
          .filter(Boolean).length,
    )
    const viewportWidth = page.viewportSize()?.width ?? 0
    expect(gridColumns, 'review layout grid column count').toBe(viewportWidth < 1024 ? 1 : 2)

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
      }, FOCUS_TARGETS)

    for (let i = 0; i < 10 && focusVisible['back-to-projects'] === undefined; i++) {
      await page.keyboard.press('Shift+Tab')
      const hit = await recordFocusedTarget()
      if (hit) focusVisible[hit.key] = hit.visible
    }
    for (let i = 0; i < 10 && focusVisible.logout === undefined; i++) {
      await page.keyboard.press('Tab')
      const hit = await recordFocusedTarget()
      if (hit) focusVisible[hit.key] = hit.visible
    }

    expect(Object.keys(focusVisible), 'reached focus targets').toHaveLength(FOCUS_TARGETS.length)
    for (const target of FOCUS_TARGETS) {
      expect(focusVisible[target.key], `${target.key} visible focus indicator`).toBe(true)
    }

    await cleanupProject(page, projectName)
    await expectClean(page, cleanliness)
  })

  async function cleanupProject(page: Page, projectName: string) {
    await page.getByRole('button', { name: 'Back to project', exact: true }).click()
    await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible()
    await page.getByRole('button', { name: `Delete ${projectName}` }).click()
    await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click()
    await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 })
  }
})
