import { expect, test, type Locator, type Page, type Request, type TestInfo } from '@playwright/test'

const HEALTH_URL_PART = '/api/v1/health'

const EXPECTED_REJECTION_MESSAGE = 'Failed to load resource: net::ERR_FAILED'
const EXPECTED_503_MESSAGE =
  'Failed to load resource: the server responded with a status of 503 (Service Unavailable)'
const EXPECTED_503_BODY = { status: 'error', database: 'disconnected' }

interface ConsoleEntry {
  text: string
  url: string | null
}

interface FailedRequest {
  url: string
  failure: string | null
}

interface ApiResponse {
  url: string
  status: number
  body: unknown
}

interface ErrorResponse {
  url: string
  status: number
}

interface Diagnostics {
  consoleErrors: ConsoleEntry[]
  pageErrors: string[]
  failedRequests: FailedRequest[]
  apiResponses: ApiResponse[]
  errorResponses: ErrorResponse[]
}

interface Settlement {
  pending: Set<Request>
  parses: Promise<void>[]
}

function installDiagnostics(page: Page): { diagnostics: Diagnostics; settlement: Settlement } {
  const diagnostics: Diagnostics = {
    consoleErrors: [],
    pageErrors: [],
    failedRequests: [],
    apiResponses: [],
    errorResponses: [],
  }
  const settlement: Settlement = { pending: new Set(), parses: [] }

  page.on('console', (message) => {
    if (message.type() === 'error') {
      diagnostics.consoleErrors.push({
        text: message.text(),
        url: message.location()?.url ?? null,
      })
    }
  })
  page.on('pageerror', (error) => {
    diagnostics.pageErrors.push(error.message)
  })
  page.on('request', (request) => {
    settlement.pending.add(request)
  })
  const forgetRequest = (request: Request) => {
    settlement.pending.delete(request)
  }
  page.on('requestfinished', forgetRequest)
  page.on('requestfailed', (request) => {
    forgetRequest(request)
    diagnostics.failedRequests.push({
      url: request.url(),
      failure: request.failure()?.errorText ?? null,
    })
  })
  page.on('response', (response) => {
    const pathname = new URL(response.url()).pathname
    if (pathname.startsWith('/api/')) {
      const parse: Promise<void> = response.json().then(
        (body: unknown) => {
          diagnostics.apiResponses.push({ url: response.url(), status: response.status(), body })
        },
        () => {
          diagnostics.apiResponses.push({ url: response.url(), status: response.status(), body: null })
        },
      )
      settlement.parses.push(parse)
    } else if (response.status() >= 400) {
      diagnostics.errorResponses.push({ url: response.url(), status: response.status() })
    }
  })

  return { diagnostics, settlement }
}

async function settleDiagnostics(settlement: Settlement) {
  // Event-aware settlement: every tracked request finished, then every queued
  // response-body parse drained (including parses attached by late responses).
  // No arbitrary sleeps.
  await expect.poll(() => settlement.pending.size, { timeout: 15000 }).toBe(0)
  for (;;) {
    const batch = settlement.parses.splice(0, settlement.parses.length)
    if (batch.length === 0) {
      break
    }
    await Promise.all(batch)
    await expect.poll(() => settlement.pending.size, { timeout: 15000 }).toBe(0)
  }
}

function expectedHealthUrl(page: Page): string {
  return new URL(HEALTH_URL_PART, page.url()).href
}

async function assertNoHorizontalOverflow(page: Page) {
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }))
  expect(
    overflow.scrollWidth,
    `horizontal overflow: scrollWidth ${overflow.scrollWidth} > clientWidth ${overflow.clientWidth}`,
  ).toBeLessThanOrEqual(overflow.clientWidth)
}

async function assertContentReadable(page: Page, locator: Locator, label: string) {
  await expect(locator, `${label} visible`).toBeVisible()
  const box = await locator.boundingBox()
  expect(box, `${label} has a layout box`).not.toBeNull()
  const viewport = page.viewportSize() ?? { width: 1440, height: 900 }
  expect(box!.x, `${label} not clipped on the left`).toBeGreaterThanOrEqual(0)
  expect(box!.y, `${label} not clipped at the top`).toBeGreaterThanOrEqual(0)
  expect(
    box!.x + box!.width,
    `${label} not clipped on the right (box ${box!.x + box!.width} > viewport ${viewport.width})`,
  ).toBeLessThanOrEqual(viewport.width)
  expect(box!.width, `${label} has readable width`).toBeGreaterThan(0)
  expect(box!.height, `${label} has readable height`).toBeGreaterThan(0)
}

function isHealthOwned(url: string): boolean {
  try {
    return new URL(url).pathname.startsWith(HEALTH_URL_PART)
  } catch {
    return false
  }
}

function healthDiagnostics(diagnostics: Diagnostics): Diagnostics {
  return {
    consoleErrors: diagnostics.consoleErrors.filter(e => e.url !== null && isHealthOwned(e.url)),
    pageErrors: diagnostics.pageErrors,
    failedRequests: diagnostics.failedRequests.filter(f => isHealthOwned(f.url)),
    apiResponses: diagnostics.apiResponses.filter(r => isHealthOwned(r.url)),
    errorResponses: diagnostics.errorResponses.filter(r => isHealthOwned(r.url)),
  }
}

function assertExactSuccess(diagnostics: Diagnostics, expectedUrl: string) {
  const health = healthDiagnostics(diagnostics)
  expect(health.apiResponses.length).toBeGreaterThan(0)
  for (const response of health.apiResponses) {
    expect(response.url).toBe(expectedUrl)
    expect(response.status).toBe(200)
    expect(response.body).toMatchObject({ status: 'ok', database: 'connected' })
    expect(typeof (response.body as { timestamp?: unknown }).timestamp).toBe('string')
  }
  expect(health.consoleErrors).toEqual([])
  expect(health.pageErrors).toEqual([])
  expect(health.failedRequests).toEqual([])
  expect(health.errorResponses).toEqual([])
}

function assertExactRejection(diagnostics: Diagnostics, expectedUrl: string) {
  const health = healthDiagnostics(diagnostics)
  expect(health.failedRequests.length).toBeGreaterThanOrEqual(1)
  for (const failed of health.failedRequests) {
    expect(failed.url).toBe(expectedUrl)
    expect(failed.failure).toBe('net::ERR_FAILED')
  }
  expect(health.consoleErrors.length).toBeGreaterThanOrEqual(1)
  for (const entry of health.consoleErrors) {
    expect(entry.url).toBe(expectedUrl)
    expect(entry.text).toBe(EXPECTED_REJECTION_MESSAGE)
  }
  expect(health.apiResponses).toEqual([])
  expect(health.errorResponses).toEqual([])
  expect(health.pageErrors).toEqual([])
}

function assertExact503(diagnostics: Diagnostics, expectedUrl: string) {
  const health = healthDiagnostics(diagnostics)
  expect(health.apiResponses.length).toBeGreaterThanOrEqual(1)
  for (const response of health.apiResponses) {
    expect(response.url).toBe(expectedUrl)
    expect(response.status).toBe(503)
    expect(response.body).toEqual(EXPECTED_503_BODY)
  }
  expect(health.failedRequests).toEqual([])
  expect(health.errorResponses).toEqual([])
  expect(health.consoleErrors.length).toBeGreaterThanOrEqual(1)
  for (const entry of health.consoleErrors) {
    expect(entry.url).toBe(expectedUrl)
    expect(entry.text).toBe(EXPECTED_503_MESSAGE)
  }
  expect(health.pageErrors).toEqual([])
}

async function captureState(page: Page, testInfo: TestInfo, state: string) {
  await page.screenshot({ path: `test-results/screenshots/${testInfo.project.name}-${state}.png` })
}

test.describe('Health Check Flow', () => {
  test('shows the real health result from Laravel and PostgreSQL', async ({ page }, testInfo) => {
    const { diagnostics, settlement } = installDiagnostics(page)

    await page.goto('/')

    const heading = page.getByRole('heading', { name: 'Health Status' })
    await assertContentReadable(page, heading, 'health heading')
    await assertContentReadable(page, page.getByText('Status: ok'), 'status line')
    await assertContentReadable(page, page.getByText('Database: connected'), 'database line')
    const timestamp = page.getByText(/Timestamp: /)
    await assertContentReadable(page, timestamp, 'timestamp line')
    await expect(timestamp).toContainText(/Timestamp: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/)

    await settleDiagnostics(settlement)

    await assertNoHorizontalOverflow(page)
    await captureState(page, testInfo, 'success')

    assertExactSuccess(diagnostics, expectedHealthUrl(page))
  })

  test('holds the loading state until the health request resolves', async ({ page }, testInfo) => {
    const { diagnostics, settlement } = installDiagnostics(page)

    let release!: () => void
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    await page.route(`**${HEALTH_URL_PART}`, async (route) => {
      await gate
      await route.continue()
    })

    await page.goto('/')

    await expect(page.getByRole('status')).toContainText('Loading health status...')
    expect(await page.getByRole('alert').count()).toBe(0)
    expect(await page.getByText('Status: ok').count()).toBe(0)
    expect(healthDiagnostics(diagnostics).failedRequests).toEqual([])
    expect(healthDiagnostics(diagnostics).apiResponses).toEqual([])
    expect(healthDiagnostics(diagnostics).errorResponses).toEqual([])
    expect(healthDiagnostics(diagnostics).consoleErrors).toEqual([])
    expect(diagnostics.pageErrors).toEqual([])
    await assertNoHorizontalOverflow(page)
    await captureState(page, testInfo, 'pending')

    release()
    await settleDiagnostics(settlement)

    const heading = page.getByRole('heading', { name: 'Health Status' })
    await assertContentReadable(page, heading, 'health heading after release')
    await expect(page.getByText('Status: ok')).toBeVisible()
    expect(await page.getByRole('status').count()).toBe(0)

    assertExactSuccess(diagnostics, expectedHealthUrl(page))
  })

  test('shows an understandable error when the health request is rejected', async ({
    page,
  }, testInfo) => {
    const { diagnostics, settlement } = installDiagnostics(page)

    await page.route(`**${HEALTH_URL_PART}`, (route) => route.abort('failed'))

    await page.goto('/')

    const alert = page.getByRole('alert')
    await assertContentReadable(page, alert, 'error alert')
    expect(await page.getByRole('status').count()).toBe(0)
    expect(await page.getByText('Status: ok').count()).toBe(0)

    await settleDiagnostics(settlement)

    await assertNoHorizontalOverflow(page)
    await captureState(page, testInfo, 'error-rejection')

    assertExactRejection(diagnostics, expectedHealthUrl(page))
  })

  test('shows an understandable error for an HTTP 503 health response', async ({
    page,
  }, testInfo) => {
    const { diagnostics, settlement } = installDiagnostics(page)

    await page.route(`**${HEALTH_URL_PART}`, (route) =>
      route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify(EXPECTED_503_BODY),
      }),
    )

    await page.goto('/')

    const alert = page.getByRole('alert')
    await assertContentReadable(page, alert, 'error alert')
    await expect(page.getByText('HTTP 503')).toBeVisible()
    expect(await page.getByRole('status').count()).toBe(0)
    expect(await page.getByText('Status: error').count()).toBe(0)

    await settleDiagnostics(settlement)

    await assertNoHorizontalOverflow(page)
    await captureState(page, testInfo, 'error-503')

    assertExact503(diagnostics, expectedHealthUrl(page))
  })

  test('rejects injected wrong-origin and late diagnostics', async ({ page }) => {
    const { diagnostics, settlement } = installDiagnostics(page)

    await page.route(`**${HEALTH_URL_PART}`, (route) => route.abort('failed'))

    await page.goto('/')

    await expect(page.getByRole('alert')).toBeVisible({ timeout: 15000 })
    await settleDiagnostics(settlement)
    const expectedUrl = expectedHealthUrl(page)

    // Baseline: the real induced diagnostics pass the exact correlation.
    assertExactRejection(diagnostics, expectedUrl)

    // A previously accepted wrong-origin console error is now rejected.
    expect(() =>
      assertExactRejection(
        {
          ...diagnostics,
          consoleErrors: [
            ...diagnostics.consoleErrors,
            { text: EXPECTED_REJECTION_MESSAGE, url: 'http://wrong-origin:9999/api/v1/health' },
          ],
        },
        expectedUrl,
      ),
    ).toThrow()

    // A previously unchecked incorrect failure code is now rejected.
    expect(() =>
      assertExactRejection(
        {
          ...diagnostics,
          failedRequests: [{ url: expectedUrl, failure: 'net::ERR_CONNECTION_REFUSED' }],
        },
        expectedUrl,
      ),
    ).toThrow()

    // A late/delayed second response with the wrong status is now rejected.
    expect(() =>
      assertExact503(
        {
          ...diagnostics,
          apiResponses: [
            ...diagnostics.apiResponses,
            { url: expectedUrl, status: 500, body: EXPECTED_503_BODY },
          ],
          consoleErrors: [{ text: EXPECTED_503_MESSAGE, url: expectedUrl }],
        },
        expectedUrl,
      ),
    ).toThrow()

    // A 503 body with extra fields is now rejected (exact body, not subset).
    expect(() =>
      assertExact503(
        {
          ...diagnostics,
          apiResponses: [
            {
              url: expectedUrl,
              status: 503,
              body: { ...EXPECTED_503_BODY, trace: 'SYNTHETIC-LEAK-MARKER' },
            },
          ],
          consoleErrors: [{ text: EXPECTED_503_MESSAGE, url: expectedUrl }],
        },
        expectedUrl,
      ),
    ).toThrow()
  })

  test('settlement tracks duplicate gated health requests by identity', async ({ page }) => {
    const { diagnostics, settlement } = installDiagnostics(page)

    let seen = 0
    let firstResponded = false
    let secondGated = false
    let releaseSecond!: () => void
    const secondGate = new Promise<void>((resolve) => {
      releaseSecond = resolve
    })
    await page.route(`**${HEALTH_URL_PART}`, async (route) => {
      seen += 1
      if (seen === 1) {
        await route.fulfill({
          status: 503,
          contentType: 'application/json',
          body: JSON.stringify(EXPECTED_503_BODY),
        })
        firstResponded = true
      } else {
        secondGated = true
        await secondGate
        await route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ status: 'error' }),
        })
      }
    })

    let finishedApiRequests = 0
    page.on('requestfinished', (request) => {
      const pathname = new URL(request.url()).pathname
      if (pathname.startsWith('/api/')) {
        finishedApiRequests += 1
      }
    })

    await page.goto('/')

    await expect(page.getByRole('alert')).toBeVisible({ timeout: 15000 })

    // Deterministic precondition with no sleeps: the first response was sent
    // and finished while the identical second request is verifiably held
    // behind an unreleased gate.
    await expect
      .poll(() => firstResponded && secondGated && finishedApiRequests >= 1, { timeout: 15000 })
      .toBe(true)

    // Key-collapsed tracking reports zero pending here because the finished
    // first request removes the shared method+URL key while the identical
    // second request is still outstanding.
    const healthPending = [...settlement.pending].filter(r => {
      try { return new URL(r.url()).pathname.startsWith(HEALTH_URL_PART) } catch { return false }
    })
    expect(healthPending.length).toBe(1)

    releaseSecond()
    await settleDiagnostics(settlement)

    // The released second response is observed with its wrong status.
    expect(diagnostics.apiResponses.some((response) => response.status === 500)).toBe(true)
    const remainingHealth = [...settlement.pending].filter(r => {
      try { return new URL(r.url()).pathname.startsWith(HEALTH_URL_PART) } catch { return false }
    })
    expect(remainingHealth.length).toBe(0)
  })
})
