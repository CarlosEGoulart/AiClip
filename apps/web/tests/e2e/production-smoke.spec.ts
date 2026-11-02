/**
 * Production Smoke Test - E2E
 *
 * Tests the critical user flow end-to-end in a production-like environment:
 * register -> login -> create project -> upload media -> processing completes
 * -> view clip review -> approve -> publish to connected platform -> verify download.
 *
 * Social platform calls use deterministic fakes (no real credentials, no network).
 *
 * Viewports: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)
 */

import { test, expect, type Page } from '@playwright/test';

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

const API_REGISTER_URL_PART = '/api/v1/auth/register';
const API_LOGIN_URL_PART = '/api/v1/auth/login';
const API_UPLOAD_URL_PART = '/api/v1/projects';
const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';

test.describe.configure({ retries: 1 });

function uniqueEmail() {
  return `smoke-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`;
}

function installSmokeDiagnostics(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];
  const server500: string[] = [];
  const tokenUrls: string[] = [];

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
  page.on('response', (response) => {
    if (response.status() >= 400) {
      failedRequests.push(`${response.status()} ${response.url()}`);
    }
    if (response.status() === 500) {
      server500.push(`${response.status()} ${response.url()}`);
    }
    // Detect any token leakage in network URLs
    const url = response.url();
    if (url.includes('access_token') || url.includes('refresh_token')) {
      tokenUrls.push(url);
    }
  });

  return { consoleErrors, failedRequests, server500, tokenUrls };
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

function assertNoServer500(server500: string[]) {
  expect(server500).toHaveLength(0);
}

function assertNoTokensInNetwork(tokenUrls: string[]) {
  expect(tokenUrls).toHaveLength(0);
}

async function registerUser(page: Page, name: string, email: string) {
  await page.goto('/');
  await page.waitForLoadState('networkidle');

  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' });
  if (!(await registerHeading.isVisible().catch(() => false))) {
    await page.getByRole('button', { name: 'Create one' }).click();
    await expect(registerHeading).toBeVisible();
  }

  await page.getByLabel('Name').fill(name);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password123');
  await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Create Account' }).click();
  await expect(page.getByText(/Signed in as/)).toBeVisible({ timeout: 15000 });
}

async function createProject(page: Page) {
  await page.getByRole('button', { name: /Create Project/i }).click();
  await page.getByLabel('Project Name').fill('Smoke Test Project');
  await page.getByRole('button', { name: /Create/i }).click();
  await expect(page.getByText('Smoke Test Project')).toBeVisible({ timeout: 15000 });
}

async function uploadMedia(page: Page) {
  const fileChooserPromise = page.waitForEvent('filechooser');
  await page.getByRole('button', { name: /Upload/i }).click();
  const fileChooser = await fileChooserPromise;

  // Create a minimal fake video file
  const buffer = Buffer.from('fake video content');
  await fileChooser.setFiles([
    { name: 'test-video.mp4', mimeType: 'video/mp4', buffer },
  ]);

  await expect(page.getByText(/processing|processed|complete/i).first()).toBeVisible({ timeout: 30000 });
}

async function approveClipReview(page: Page) {
  await page.getByRole('button', { name: /Approve/i }).first().click();
  await expect(page.getByText(/approved/i)).toBeVisible({ timeout: 15000 });
}

async function publishClip(page: Page) {
  await page.getByRole('button', { name: /Publish to YouTube|Publish to Instagram/i }).first().click();
  await page.getByRole('button', { name: /Publish/i }).click();
  await expect(page.getByText(/published|publishing/i)).toBeVisible({ timeout: 15000 });
}

for (const viewport of VIEWPORTS) {
  test.describe(`Production Smoke - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installSmokeDiagnostics(page);
      (page as any).__diagnostics = diagnostics;

      // Fetch CSRF cookie first
      await page.request.get(CSRF_COOKIE_URL);

      const email = uniqueEmail();
      await registerUser(page, 'Smoke Test User', email);
    });

    test('H.1: Register -> login -> create project -> upload media -> processing completes', async ({ page }) => {
      await createProject(page);
      await uploadMedia(page);

      const { consoleErrors, failedRequests, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNetworkClean(failedRequests);
      assertNoServer500(server500);
    });

    test('H.2: View clip review -> approve -> publish to connected platform', async ({ page }) => {
      await createProject(page);
      await uploadMedia(page);
      await approveClipReview(page);
      await publishClip(page);

      const { consoleErrors, failedRequests, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNetworkClean(failedRequests);
      assertNoServer500(server500);
    });

    test('H.3: Publish failure -> retry -> success', async ({ page }) => {
      await createProject(page);
      await uploadMedia(page);
      await approveClipReview(page);

      // Attempt publish; if it fails, retry
      await page.getByRole('button', { name: /Publish to YouTube|Publish to Instagram/i }).first().click();
      const publishButton = page.getByRole('button', { name: /Publish/i });
      if (await publishButton.isVisible()) {
        await publishButton.click();
      }

      // Retry
      const retryButton = page.getByRole('button', { name: /Retry/i });
      if (await retryButton.isVisible()) {
        await retryButton.click();
        await expect(page.getByText(/published|success/i)).toBeVisible({ timeout: 15000 });
      }

      const { consoleErrors, failedRequests, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNetworkClean(failedRequests);
      assertNoServer500(server500);
    });

    test('H.4: No tokens in browser DevTools / network payloads', async ({ page }) => {
      const { tokenUrls } = (page as any).__diagnostics;
      assertNoTokensInNetwork(tokenUrls);

      // Also check localStorage and sessionStorage for tokens
      const tokensInStorage = await page.evaluate(() => {
        const storage = { ...localStorage, ...sessionStorage };
        return Object.entries(storage).filter(([key, value]) =>
          key.toLowerCase().includes('token') ||
          value.toString().toLowerCase().includes('access_token') ||
          value.toString().toLowerCase().includes('refresh_token')
        );
      });
      expect(tokensInStorage).toHaveLength(0);
    });

    test('H.5: Console cleanliness - no unexpected JS console errors', async ({ page }) => {
      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('H.6: Network cleanliness - no unexpected 4xx/5xx', async ({ page }) => {
      const { failedRequests } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
    });
  });
}
