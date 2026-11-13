import { Page, APIResponse } from '@playwright/test'

export interface CleanlinessReport {
  consoleErrors: string[]
  pageErrors: string[]
  failedResponses: string[]
  storageKeys: string[]
}

/**
 * Attaches cleanliness collectors to a Playwright page.
 *
 * Collects:
 * - console error messages
 * - uncaught page errors
 * - failed API responses (4xx/5xx)
 * - storage keys (localStorage/sessionStorage) after test actions
 *
 * Returns a report object that can be asserted with expectClean().
 */
export function trackCleanliness(page: Page): CleanlinessReport {
  const report: CleanlinessReport = {
    consoleErrors: [],
    pageErrors: [],
    failedResponses: [],
    storageKeys: [],
  }

  page.on('console', (msg) => {
    if (msg.type() === 'error' || msg.type() === 'warning') {
      report.consoleErrors.push(`${msg.type()}: ${msg.text()} (${msg.location().url})`)
    }
  })

  page.on('pageerror', (error) => {
    report.pageErrors.push(error.message)
  })

  page.on('response', (response: APIResponse) => {
    const status = response.status()
    if (status >= 400) {
      report.failedResponses.push(`${status} ${response.url()}`)
    }
  })

  return report
}

/**
 * Asserts that the page remained clean during the test.
 *
 * Fails if any console errors, page errors, failed responses, or storage
 * pollution were collected. Removes all attached listeners afterward.
 */
export function expectClean(page: Page, cleanliness: CleanlinessReport): void {
  page.removeAllListeners('console')
  page.removeAllListeners('pageerror')
  page.removeAllListeners('response')

  const errors: string[] = []

  if (cleanliness.consoleErrors.length > 0) {
    errors.push(`Console errors:\n  ${cleanliness.consoleErrors.join('\n  ')}`)
  }

  if (cleanliness.pageErrors.length > 0) {
    errors.push(`Page errors:\n  ${cleanliness.pageErrors.join('\n  ')}`)
  }

  if (cleanliness.failedResponses.length > 0) {
    errors.push(`Failed responses:\n  ${cleanliness.failedResponses.join('\n  ')}`)
  }

  if (errors.length > 0) {
    throw new Error(`Page cleanliness assertion failed:\n${errors.join('\n')}`)
  }
}
