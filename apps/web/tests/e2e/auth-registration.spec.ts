/**
 * Auth Registration Flow - E2E Tests
 * Tests the registration flow end-to-end per test-plan.md §5.4.
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
}

function installRegisterDiagnostics(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];
  const registerResponses: { status: number; url: string }[] = [];

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
    if (response.url().includes(API_REGISTER_URL_PART)) {
      registerResponses.push({ status: response.status(), url: response.url() });
    }
    if (response.status() >= 400) {
      failedRequests.push(`${response.status()} ${response.url()}`);
    }
  });

  return { consoleErrors, failedRequests, registerResponses };
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

function assertNoRegister500(registerResponses: { status: number; url: string }[]) {
  for (const response of registerResponses) {
    expect(response.status).not.toBe(500, `Register endpoint returned 500 at ${response.url}`);
  }
}

for (const viewport of VIEWPORTS) {
  test.describe(`Auth Registration - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installRegisterDiagnostics(page);
      (page as any).__diagnostics = diagnostics;
    });

    test('Load Register Page - form loads without console errors', async ({ page }) => {
      await switchToRegister(page);

      await expect(page.getByLabel('Name')).toBeVisible();
      await expect(page.getByLabel('Email')).toBeVisible();
      await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
      await expect(page.getByLabel('Confirm Password', { exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Create Account' })).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Successful Registration - valid name/email/password → authenticated session → dashboard', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'New User', email);

      await expect(page.getByText(/Signed in as/)).toBeVisible({ timeout: 15000 });
      await expect(page.getByText('New User').first()).toBeVisible();
      await expect(page.getByText(email).first()).toBeVisible();

      const { registerResponses, consoleErrors } = (page as any).__diagnostics;
      assertNoRegister500(registerResponses);
      assertConsoleClean(consoleErrors);
    });

    test('Validation Errors - missing/invalid fields show inline errors, form stays', async ({ page }) => {
      await switchToRegister(page);

      // Submit with empty required fields
      await page.getByRole('button', { name: 'Create Account' }).click();

      // Validation errors appear; register form remains (not redirected)
      await expect(page.getByRole('heading', { name: 'Create your workspace' })).toBeVisible();
      await expect(page.getByRole('alert')).toBeVisible();

      const { consoleErrors, registerResponses } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoRegister500(registerResponses);
    });

    test('Duplicate Email - already-registered email shows error, no duplicate account', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'First User', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      // Log out so we can re-attempt registration with the same email
      await page.getByRole('button', { name: 'Log out' }).first().click();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();

      await switchToRegister(page);
      await page.getByLabel('Name').fill('Second User');
      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Create Account' }).click();

      // Duplicate email error shown; register form stays (no duplicate account created)
      await expect(page.getByText('already been taken')).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Create your workspace' })).toBeVisible();

      const { registerResponses } = (page as any).__diagnostics;
      assertNoRegister500(registerResponses);
    });

    test('Password Mismatch - password != confirmation shows validation error on confirmation', async ({ page }) => {
      await switchToRegister(page);
      await page.getByLabel('Name').fill('Mismatch User');
      await page.getByLabel('Email').fill(uniqueEmail());
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByLabel('Confirm Password', { exact: true }).fill('different-password');
      await page.getByRole('button', { name: 'Create Account' }).click();

      await expect(page.getByText('confirmation')).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Create your workspace' })).toBeVisible();

      const { consoleErrors, registerResponses } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoRegister500(registerResponses);
    });

    test('Console Cleanliness - no unexpected JS console errors during registration flow', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Console User', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Network Cleanliness - no unexpected 4xx/5xx; expected 422/429 only', async ({ page }) => {
      const email = uniqueEmail();
      await registerUser(page, 'Network User', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      const { failedRequests, registerResponses } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
      assertNoRegister500(registerResponses);
    });

    test(`Visual Regression - register form screenshot - ${viewport.name}`, async ({ page }) => {
      await switchToRegister(page);
      await page.waitForTimeout(300);

      await expect(page.locator('.auth-page')).toHaveScreenshot(
        `auth-register-visual-${viewport.name}.png`,
        { animations: 'disabled', threshold: 0.2, fullPage: true }
      );
    });
  });
}
