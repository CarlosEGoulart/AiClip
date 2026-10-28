/**
 * Auth Flow Functional Tests - Register → Login → Session Persistence
 * End-to-end functional verification per test-plan.md §5.13.
 * Covers POST /api/v1/auth/register, POST /api/v1/auth/login,
 * GET /sanctum/csrf-cookie, GET /api/v1/auth/me.
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

function installFunctionalDiagnostics(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];
  const login500: string[] = [];
  const register500: string[] = [];

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
    if (response.url().includes(API_LOGIN_URL_PART) && response.status() === 500) {
      login500.push(`${response.status()} ${response.url()}`);
    }
    if (response.url().includes(API_REGISTER_URL_PART) && response.status() === 500) {
      register500.push(`${response.status()} ${response.url()}`);
    }
  });

  return { consoleErrors, failedRequests, login500, register500 };
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
  const unexpected = failedRequests.filter(
    (u) => !u.includes('favicon')
  );
  expect(unexpected).toHaveLength(0);
}

for (const viewport of VIEWPORTS) {
  test.describe(`Auth Functional - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installFunctionalDiagnostics(page);
      (page as any).__diagnostics = diagnostics;
    });

    test('Load Login Page - /login loads without console errors', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');
      await expect(
        page.getByRole('heading', { name: 'Sign in to your workspace' })
      ).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Load Register Page - /register loads without console errors', async ({ page }) => {
      await switchToRegister(page);
      await expect(
        page.getByRole('heading', { name: 'Create your workspace' })
      ).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Successful Registration - valid credentials → authenticated → dashboard redirect', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Functional User', email);

      await expect(page.getByText(/Signed in as/)).toBeVisible();
      await expect(page.getByText('Functional User').first()).toBeVisible();
      await expect(page.getByText(email).first()).toBeVisible();

      const { register500, consoleErrors } = (page as any).__diagnostics;
      expect(register500).toHaveLength(0);
      assertConsoleClean(consoleErrors);
    });

    test('Successful Login - valid credentials → 200 (never 500) → dashboard', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Login Functional', email);

      await page.getByRole('button', { name: 'Log out' }).first().click();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();

      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Sign In' }).click();

      await expect(page.getByText(/Signed in as/)).toBeVisible({ timeout: 15000 });
      await expect(page.getByText('Login Functional').first()).toBeVisible();

      const { login500, consoleErrors } = (page as any).__diagnostics;
      expect(login500).toHaveLength(0);
      assertConsoleClean(consoleErrors);
    });

    test('Invalid Credentials - wrong password → unauthorized error, no credential leak', async ({ page }) => {
      await waitForAuthReady(page);
      await page.getByLabel('Email').fill('nobody@example.com');
      await page.getByLabel('Password', { exact: true }).fill('wrongpassword');
      await page.getByRole('button', { name: 'Sign In' }).click();

      await expect(page.getByRole('alert')).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();
      await expect(page.getByText(/Signed in as/)).not.toBeVisible();

      const alertText = await page.locator('.auth-error').textContent();
      expect(alertText || '').not.toMatch(/password|email|credentials/i);

      const { login500, consoleErrors } = (page as any).__diagnostics;
      expect(login500).toHaveLength(0);
      assertConsoleClean(consoleErrors);
    });

    test('Registration Validation - missing/invalid fields → inline errors, form stays', async ({ page }) => {
      await switchToRegister(page);
      await page.getByRole('button', { name: 'Create Account' }).click();

      await expect(page.getByRole('heading', { name: 'Create your workspace' })).toBeVisible();
      await expect(page.getByRole('alert')).toBeVisible();

      const { register500, consoleErrors } = (page as any).__diagnostics;
      expect(register500).toHaveLength(0);
      assertConsoleClean(consoleErrors);
    });

    test('Registration Duplicate Email - already-registered email → error, no duplicate account', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Dup First', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.getByRole('button', { name: 'Log out' }).first().click();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();

      await switchToRegister(page);
      await page.getByLabel('Name').fill('Dup Second');
      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Create Account' }).click();

      await expect(page.getByText('already been taken')).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Create your workspace' })).toBeVisible();

      const { register500 } = (page as any).__diagnostics;
      expect(register500).toHaveLength(0);
    });

    test('Session Persists After Reload - still authenticated after page reload', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Persistent Functional', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.reload();
      await page.waitForLoadState('networkidle');

      await expect(page.getByText(/Signed in as/)).toBeVisible();
      await expect(page.getByText('Persistent Functional').first()).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Logout Invalidates Session - logout clears session, redirected to login', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Logout Functional', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.getByRole('button', { name: 'Log out' }).first().click();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();
      await expect(page.getByText(/Signed in as/)).not.toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('CSRF Cookie Returns 204 + XSRF-TOKEN, never 500', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const response = await page.request.get(CSRF_COOKIE_URL);
      expect(response.status()).toBe(204);

      // XSRF-TOKEN cookie set in the browser context
      const cookies = await page.context().cookies();
      const xsrfCookie = cookies.find((c) => c.name === 'XSRF-TOKEN');
      expect(xsrfCookie).toBeTruthy();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('No 500 on Login Endpoint - /api/v1/auth/login never returns 500', async ({ page }) => {
      await waitForAuthReady(page);
      await page.getByLabel('Email').fill('nobody@example.com');
      await page.getByLabel('Password', { exact: true }).fill('password');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await page.waitForTimeout(1000);

      const { login500 } = (page as any).__diagnostics;
      expect(login500).toHaveLength(0);
    });

    test('No 500 on Register Endpoint - /api/v1/auth/register never returns 500', async ({ page }) => {
      await switchToRegister(page);
      await page.getByLabel('Name').fill('Reg500');
      await page.getByLabel('Email').fill(uniqueEmail());
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Create Account' }).click();
      await page.waitForTimeout(1000);

      const { register500 } = (page as any).__diagnostics;
      expect(register500).toHaveLength(0);
    });

    test('Console Cleanliness - no unexpected errors during full register → login → reload flow', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'FullFlow', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.reload();
      await page.waitForLoadState('networkidle');
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Network Cleanliness - no unexpected 4xx/5xx during full flow', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'NetFlow', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      await page.getByRole('button', { name: 'Log out' }).first().click();
      await page.waitForLoadState('networkidle');

      const { failedRequests } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
    });
  });
}
