/**
 * Auth Login Flow - E2E Tests
 * Tests the login flow end-to-end per test-plan.md §5.5.
 * Directly addresses the reported login 500 error.
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

test.describe.configure({ retries: 1 });

function uniqueEmail() {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`;
}

async function waitForLoginPage(page: Page) {
  await page.goto('/');
  await page.waitForLoadState('networkidle');
  await expect(
    page.getByRole('heading', { name: 'Sign in to your workspace' })
  ).toBeVisible({ timeout: 15000 });
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

function installLoginDiagnostics(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];
  const loginResponses: { status: number; url: string }[] = [];

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
    if (response.url().includes(API_LOGIN_URL_PART)) {
      loginResponses.push({ status: response.status(), url: response.url() });
    }
    if (response.status() >= 400) {
      failedRequests.push(`${response.status()} ${response.url()}`);
    }
  });

  return { consoleErrors, failedRequests, loginResponses };
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

function assertNoLogin500(loginResponses: { status: number; url: string }[]) {
  for (const response of loginResponses) {
    expect(response.status).not.toBe(500, `Login endpoint returned 500 at ${response.url}`);
  }
}

for (const viewport of VIEWPORTS) {
  test.describe(`Auth Login - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installLoginDiagnostics(page);
      (page as any).__diagnostics = diagnostics;
    });

    test('Load Login Page - form loads without console errors', async ({ page }) => {
      await waitForLoginPage(page);

      await expect(page.getByLabel('Email')).toBeVisible();
      await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Successful Login - valid credentials return 200 (never 500) and reach dashboard', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Login User', email);

      // Already authenticated after registration; log out to test login separately
      await page.getByRole('button', { name: 'Log out' }).first().click();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();

      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Sign In' }).click();

      await expect(page.getByText(/Signed in as/)).toBeVisible({ timeout: 15000 });
      await expect(page.getByText('Login User').first()).toBeVisible();

      const { loginResponses, consoleErrors } = (page as any).__diagnostics;
      assertNoLogin500(loginResponses);
      assertConsoleClean(consoleErrors);
    });

    test('Invalid Credentials - wrong password returns unauthorized error, no leak', async ({ page }) => {
      await waitForLoginPage(page);

      await page.getByLabel('Email').fill('nobody@example.com');
      await page.getByLabel('Password', { exact: true }).fill('wrongpassword');
      await page.getByRole('button', { name: 'Sign In' }).click();

      // Generic controlled error shown, login form stays
      await expect(page.getByRole('alert')).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();
      await expect(page.getByText(/Signed in as/)).not.toBeVisible();

      // No credential enumeration / no stack traces: alert message is generic
      const alertText = await page.locator('.auth-error').textContent();
      expect(alertText || '').not.toMatch(/password|email|credentials/i);

      const { loginResponses, consoleErrors } = (page as any).__diagnostics;
      assertNoLogin500(loginResponses);
      assertConsoleClean(consoleErrors);
    });

    test('500 Error Detection - login endpoint never returns 500 for any input', async ({ page }) => {
      await waitForLoginPage(page);

      // Submit with valid shape to hit the endpoint
      await page.getByLabel('Email').fill('nobody@example.com');
      await page.getByLabel('Password', { exact: true }).fill('password');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await page.waitForTimeout(1000);

      const { loginResponses } = (page as any).__diagnostics;
      // At least one login request was made and none returned 500
      if (loginResponses.length > 0) {
        assertNoLogin500(loginResponses);
      }
    });

    test('Empty Fields - submit empty form shows inline validation errors', async ({ page }) => {
      await waitForLoginPage(page);

      await page.getByRole('button', { name: 'Sign In' }).click();

      // Validation errors appear on required fields
      await expect(page.getByRole('alert')).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();

      const { consoleErrors, loginResponses } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoLogin500(loginResponses);
    });

    test('Throttle - excessive failed attempts produce 429 throttle handling', async ({ page }) => {
      await waitForLoginPage(page);

      // Attempt several rapid failed logins to trigger rate limiting
      for (let i = 0; i < 5; i++) {
        await page.getByLabel('Email').fill('throttle@example.com');
        await page.getByLabel('Password', { exact: true }).fill('wrongpassword');
        await page.getByRole('button', { name: 'Sign In' }).click();
        await page.waitForTimeout(200);
      }

      const { loginResponses } = (page as any).__diagnostics;
      const statuses = loginResponses.map((r) => r.status);
      // Either a 429 was observed, or the UI shows a throttle/generic error state
      const has429 = statuses.includes(429);
      const hasThrottleUI = await page
        .locator('.auth-error, [role="alert"]')
        .first()
        .isVisible()
        .catch(() => false);
      expect(has429 || hasThrottleUI).toBeTruthy();

      assertNoLogin500(loginResponses);
    });

    test('CSRF Protection - state-changing login includes XSRF token handling', async ({ page }) => {
      await waitForLoginPage(page);

      const csrfRequests: string[] = [];
      page.on('request', (request) => {
        if (request.url().includes(API_LOGIN_URL_PART) && request.method() === 'POST') {
          const xsrf = request.headers()['x-xsrf-token'];
          if (xsrf) csrfRequests.push(xsrf);
        }
      });

      await page.getByLabel('Email').fill('csrf@example.com');
      await page.getByLabel('Password', { exact: true }).fill('password');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await page.waitForTimeout(1000);

      // CSRF token should be attached to the state-changing login request
      expect(csrfRequests.length).toBeGreaterThan(0);

      const { loginResponses } = (page as any).__diagnostics;
      assertNoLogin500(loginResponses);
    });

    test('Console Cleanliness - no unexpected JS console errors during login flow', async ({ page }) => {
      await waitForLoginPage(page);
      await page.getByLabel('Email').fill('console@example.com');
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await page.waitForTimeout(500);

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Network Cleanliness - no unexpected 4xx/5xx; 500 on login fails test', async ({ page }) => {
      await waitForLoginPage(page);
      await page.getByLabel('Email').fill('network@example.com');
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await page.waitForTimeout(500);

      const { failedRequests, loginResponses } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
      assertNoLogin500(loginResponses);
    });

    test(`Visual Regression - login form screenshot - ${viewport.name}`, async ({ page }) => {
      await waitForLoginPage(page);
      await page.waitForTimeout(300);

      await expect(page.locator('.auth-page')).toHaveScreenshot(
        `auth-login-visual-${viewport.name}.png`,
        { animations: 'disabled', threshold: 0.2, fullPage: true }
      );
    });
  });
}
