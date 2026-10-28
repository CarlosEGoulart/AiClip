/**
 * CSRF Cookie Endpoint Tests - E2E
 * Verifies GET /sanctum/csrf-cookie returns 204 and sets the XSRF-TOKEN cookie
 * per test-plan.md §5.10. Directly addresses the reported 500 error on this endpoint.
 *
 * Viewports: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)
 */

import { test, expect } from '@playwright/test';

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';

test.describe.configure({ retries: 1 });

function installCsrfDiagnostics(page: import('@playwright/test').Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];

  page.on('console', (msg) => {
    if (msg.type() === 'error') {
      consoleErrors.push(msg.text());
    }
  });
  page.on('pageerror', (error) => {
    consoleErrors.push(`pageerror: ${error.message}`);
  });
  page.on('requestfailed', (request) => {
    failedRequests.push(request.url());
  });

  return { consoleErrors, failedRequests };
}

function assertConsoleClean(consoleErrors: string[]) {
  const unexpected = consoleErrors.filter(
    (e) =>
      !e.includes('net::ERR_FAILED') &&
      !e.includes('Failed to fetch') &&
      !e.includes('net::ERR_CONNECTION_REFUSED') &&
      !e.includes('favicon') &&
      !e.includes('pageerror:')
  );
  expect(unexpected).toHaveLength(0);
}

function assertNetworkClean(failedRequests: string[]) {
  const unexpected = failedRequests.filter((u) => !u.includes('favicon'));
  expect(unexpected).toHaveLength(0);
}

for (const viewport of VIEWPORTS) {
  test.describe(`Auth CSRF Cookie - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installCsrfDiagnostics(page);
      (page as any).__diagnostics = diagnostics;
      // Establish the browsing context on the app before probing the CSRF endpoint
      await page.goto('/');
      await page.waitForLoadState('networkidle');
    });

    test('CSRF Cookie - Returns 204 (never 500)', async ({ page }) => {
      const response = await page.request.get(CSRF_COOKIE_URL);
      expect(response.status()).toBe(204);

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('CSRF Cookie - Sets XSRF-TOKEN cookie accessible by the frontend', async ({ page }) => {
      await page.request.get(CSRF_COOKIE_URL);

      const cookies = await page.context().cookies();
      const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
      expect(xsrfCookie).toBeTruthy();
      expect(xsrfCookie!.value).toBeTruthy();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('CSRF Cookie - Console Cleanliness - no unexpected console errors', async ({ page }) => {
      await page.request.get(CSRF_COOKIE_URL);
      await page.waitForTimeout(300);

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('CSRF Cookie - Network Cleanliness - endpoint reachable, no failed requests', async ({ page }) => {
      const response = await page.request.get(CSRF_COOKIE_URL);
      expect(response.status()).toBeLessThan(400);

      const { failedRequests } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
    });
  });
}
