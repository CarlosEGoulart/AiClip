import { expect, test } from '@playwright/test'

function uniqueEmail() {
  return `e2e-clip-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`
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

  await page.goto('/')
  await expect(page.getByText(/Signed in as/).first()).toBeVisible({ timeout: 15000 })
}

async function createProjectViaUi(page: import('@playwright/test').Page, projectName: string): Promise<number> {
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

async function openProject(page: import('@playwright/test').Page, projectName: string) {
  await page.getByRole('button', { name: `Open ${projectName}` }).click()
  await expect(page.getByRole('button', { name: 'Back to projects' })).toBeVisible()
  await expect(page.getByRole('tab', { name: 'Review Clips' })).toBeVisible()
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

  async function seedClipReview(page: Page, projectId: number) {
    const result = await page.evaluate(async (pid) => {
      const cookieMap: Record<string, string> = {}
      for (const part of document.cookie.split(';')) {
        const eq = part.indexOf('=')
        if (eq === -1) continue
        cookieMap[part.slice(0, eq).trim()] = part.slice(eq + 1).trim()
      }
      const xsrf = cookieMap['XSRF-TOKEN'] || ''
      const res = await fetch('/api/v1/test/clip-reviews/seed', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent(xsrf),
        },
        body: JSON.stringify({ project_id: pid }),
      })
      const body = await res.json()
      return { status: res.status, body }
    }, projectId)

    expect(result.status, 'seed route status').toBe(200)
    expect(result.body.data.status).toBe('pending')
    expect(result.body.data.video_url).toBeDefined()
    return result.body.data.id as number
  }

  async function selectClipAndVerifyPending(page: Page, clipReviewId: number) {
    await expect(page.getByRole('listitem')).toHaveCount(1)

    await page.getByRole('listitem').first().click()

    await expect(page.getByLabel('Clip video')).toBeVisible()

    await expect(page.getByRole('group', { name: 'Review actions' })).toBeVisible()

    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveAttribute(
      'aria-pressed',
      'false'
    )

    await expect(page.getByRole('listitem').first()).toContainText('Pending')

    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId))
  }

  async function approveClip(page: Page, clipReviewId: number) {
    await page.getByRole('button', { name: 'Approve clip' }).click()
    await expect(page.getByRole('alertdialog')).toBeVisible()
    await expect(page.getByRole('heading', { name: /confirm approve/i })).toBeVisible()

    await page.getByRole('button', { name: /confirm approve/i }).click()

    await expect(page.getByRole('alertdialog')).not.toBeVisible()

    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveAttribute(
      'aria-pressed',
      'true'
    )
    await expect(page.getByRole('button', { name: 'Approve clip' })).toBeDisabled()

    await expect(page.getByRole('listitem').first()).toContainText('Approved')

    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId))
  }

  async function rejectClipWithFeedback(page: Page, clipReviewId: number, projectId: number) {
    await page.getByRole('button', { name: 'Reject clip' }).click()
    await expect(page.getByRole('alertdialog')).toBeVisible()
    await expect(page.getByRole('heading', { name: /confirm reject/i })).toBeVisible()
    await expect(page.getByText(/feedback will be required/i)).toBeVisible()

    await page.getByRole('button', { name: /confirm reject/i }).click()
    await expect(page.getByRole('alertdialog')).not.toBeVisible()

    await expect(page.getByRole('group', { name: 'Feedback form' })).toBeVisible()

    await expect(page.getByRole('button', { name: 'Submit Feedback' })).toBeDisabled()

    await page.locator('#feedback').fill('This clip needs better audio levels.')
    await expect(page.getByRole('button', { name: 'Submit Feedback' })).toBeEnabled()

    const feedbackSubmitted = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === `/api/v1/clip-reviews/${clipReviewId}/feedback` &&
        response.request().method() === 'POST',
    )
    const listRefetched = page.waitForResponse(isGet(`/api/v1/projects/${projectId}/clip-reviews`))

    await page.getByRole('button', { name: 'Submit Feedback' }).click()

    const feedbackResponse = await feedbackSubmitted
    expect(feedbackResponse.status()).toBe(200)
    const refetchResponse = await listRefetched
    expect(refetchResponse.status()).toBe(200)

    await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveAttribute(
      'aria-pressed',
      'true'
    )
    await expect(page.getByRole('button', { name: 'Reject clip' })).toBeDisabled()

    await expect(page.getByRole('listitem').first()).toContainText('Rejected')

    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId))
  }

  async function needsChangesClipWithFeedback(page: Page, clipReviewId: number, projectId: number) {
    await page.getByRole('button', { name: 'Needs Changes clip' }).click()
    await expect(page.getByRole('alertdialog')).toBeVisible()
    await expect(page.getByRole('heading', { name: /confirm needs changes/i })).toBeVisible()
    await expect(page.getByText(/feedback will be required/i)).toBeVisible()

    await page.getByRole('button', { name: /confirm needs changes/i }).click()
    await expect(page.getByRole('alertdialog')).not.toBeVisible()

    await expect(page.getByRole('group', { name: 'Feedback form' })).toBeVisible()

    await page.locator('#feedback').fill('Please adjust the transition timing.')

    const feedbackSubmitted = page.waitForResponse(
      (response) =>
        new URL(response.url()).pathname === `/api/v1/clip-reviews/${clipReviewId}/feedback` &&
        response.request().method() === 'POST',
    )
    const listRefetched = page.waitForResponse(isGet(`/api/v1/projects/${projectId}/clip-reviews`))

    await page.getByRole('button', { name: 'Submit Feedback' }).click()

    const feedbackResponse = await feedbackSubmitted
    expect(feedbackResponse.status()).toBe(200)
    const refetchResponse = await listRefetched
    expect(refetchResponse.status()).toBe(200)

    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toHaveAttribute(
      'aria-pressed',
      'true'
    )
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toBeDisabled()

    await expect(page.getByRole('listitem').first()).toContainText('Needs Changes')

    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId))
  }

  async function cleanupProject(page: Page, projectName: string) {
    await page.getByRole('button', { name: 'Back to project', exact: true }).click()
    await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible()
    await page.getByRole('button', { name: `Delete ${projectName}` }).click()
    await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click()
    await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 })
  }

  test('TC-E2E-05 approve happy path', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Clip Approver', email)

    const projectName = 'Approve Project'
    const projectId = await createProjectViaUi(page, projectName)

    await openProject(page, projectName)

    const clipReviewId = await seedClipReview(page, projectId)

    await openReviewTab(page, projectId)

    await selectClipAndVerifyPending(page, clipReviewId)

    await approveClip(page, clipReviewId)

    await cleanupProject(page, projectName)

    await expectClean(page, cleanliness)
  })

  test('TC-E2E-06 reject happy path (with feedback)', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Clip Rejecter', email)

    const projectName = 'Reject Project'
    const projectId = await createProjectViaUi(page, projectName)

    await openProject(page, projectName)

    const clipReviewId = await seedClipReview(page, projectId)

    await openReviewTab(page, projectId)

    await selectClipAndVerifyPending(page, clipReviewId)

    await rejectClipWithFeedback(page, clipReviewId, projectId)

    await cleanupProject(page, projectName)

    await expectClean(page, cleanliness)
  })

  test('TC-E2E-07 needs_changes happy path (with feedback)', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Clip Requester', email)

    const projectName = 'Needs Changes Project'
    const projectId = await createProjectViaUi(page, projectName)

    await openProject(page, projectName)

    const clipReviewId = await seedClipReview(page, projectId)

    await openReviewTab(page, projectId)

    await selectClipAndVerifyPending(page, clipReviewId)

    await needsChangesClipWithFeedback(page, clipReviewId, projectId)

    await cleanupProject(page, projectName)

    await expectClean(page, cleanliness)
  })

  test('TC-E2E-08 terminal state — no further transition after approve', async ({ page }) => {
    const cleanliness = trackCleanliness(page)

    const email = uniqueEmail()
    await waitForAuthReady(page)
    await registerUser(page, 'Clip Terminal', email)

    const projectName = 'Terminal Project'
    const projectId = await createProjectViaUi(page, projectName)

    await openProject(page, projectName)

    const clipReviewId = await seedClipReview(page, projectId)

    await openReviewTab(page, projectId)

    await selectClipAndVerifyPending(page, clipReviewId)

    await approveClip(page, clipReviewId)

    await expect(page.getByRole('button', { name: 'Reject clip' })).toBeDisabled()
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toBeDisabled()

    await expect(page.getByRole('listitem').first()).toContainText('Approved')

    await cleanupProject(page, projectName)

    await expectClean(page, cleanliness)
  })
})
