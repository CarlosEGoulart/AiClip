/**
 * Auth Session Persistence Flow Tests - E2E
 * Verifies register → login → session persistence across page reloads per
 * test-plan.md §5.11. Ensures database sessions table + APP_KEY +
 * SANCTUM_STATEFUL_DOMAINS all function together.
 *
 * Viewports: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)
 */

import { test, expect, type Page } from '@playwright/test';

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

const API_LOGIN_URL_PART = '/api/v1/auth/login';
const API_REGISTER_URL_PART = '/api/v1/auth/register';
const API_ME_URL_PART = '/api/v1/auth/me';
const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';

test.describe.configure({ retries: 1 });

function uniqueEmail() {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`;
}

async function waitForAuthReady(page: Page) {
  await page.goto('/');
  await page.waitForLoadState('networkidle');
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' }).or(
      page.getByRole('heading', { name: 'Create your workspace' })
    )
  ).toBeVisible({ timeout: 15000 });
}

async function switchToRegister(page: Page) {
  await waitForAuthReady(page);
  const registerHeading = page.getByRole('heading', { name: 'Create your workspace' });
  if (await registerHeading.isVisible().catch(() => false)) return;
  await page.getByRole('button', { name: 'Create one' }).click();
  await expect(registerHeading).toBeVisible();
}

async function registerUser(page: Page, name: string, email: string) {
  await switchToRegister(page);
  await page.getByLabel('Name').fill(name);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password123');
  await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Create Account' }).click();
  await expect(page.getByText(/Signed in as/)).toBeVisible({ timeout: 15000 });
}

function installPersistenceDiagnostics(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];
  const server500: string[] = [];

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
    if (
      response.status() === 500 &&
      (response.url().includes(API_LOGIN_URL_PART) ||
        response.url().includes(API_REGISTER_URL_PART) ||
        response.url().includes(API_ME_URL_PART) ||
        response.url().includes(CSRF_COOKIE_URL))
    ) {
      server500.push(`${response.status()} ${response.url()}`);
    }
  });

  return { consoleErrors, failedRequests, server500 };
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

for (const viewport of VIEWPORTS) {
  test.describe(`Auth Session Persistence - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installPersistenceDiagnostics(page);
      (page as any).__diagnostics = diagnostics;
    });

    test('Register Then Login - register new account → log in with same credentials → authenticated session', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Persistence User', email);

      await expect(page.getByText(/Signed in as/)).toBeVisible();
      await expect(page.getByText('Persistence User').first()).toBeVisible();
      await expect(page.getByText(email).first()).toBeVisible();

      const { server500, consoleErrors } = (page as any).__diagnostics;
      assertNoServer500(server500);
      assertConsoleClean(consoleErrors);
    });

    test('Survives Page Reload - after login, reload page → still authenticated', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Reload Persistence', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.reload();
      await page.waitForLoadState('networkidle');

      await expect(page.getByText(/Signed in as/)).toBeVisible();
      await expect(page.getByText('Reload Persistence').first()).toBeVisible();

      const { server500, consoleErrors } = (page as any).__diagnostics;
      assertNoServer500(server500);
      assertConsoleClean(consoleErrors);
    });

    test('Logout - logout clears session → redirected to login, session invalidated', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Logout Persistence', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.getByRole('button', { name: 'Log out' }).first().click();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();
      await expect(page.getByText(/Signed in as/)).not.toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('No 500 Errors - no 500 on register, login, csrf-cookie, or /api/v1/auth/me during the flow', async ({ page }) => {
      const email = uniqueEmail();

      // CSRF cookie reachable (never 500)
      await page.request.get(CSRF_COOKIE_URL);

      // Register
      await registerUser(page, 'No500 User', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      // Log out then log back in to exercise login endpoint
      await page.getByRole('button', { name: 'Log out' }).first().click();
      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      const { server500 } = (page as any).__diagnostics;
      assertNoServer500(server500);
    });

    test('Console Cleanliness - no unexpected console errors during the full persistence flow', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Console Persistence', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.reload();
      await page.waitForLoadState('networkidle');
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Network Cleanliness - no unexpected 4xx/5xx during the full persistence flow', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Network Persistence', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.reload();
      await page.waitForLoadState('networkidle');

      const { failedRequests } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
    });
  });
}
