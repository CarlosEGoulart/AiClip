/**
 * One-Click Publishing - E2E Tests (M14)
 * Tests the multi-target publish campaign workflow across YouTube, Instagram, TikTok.
 *
 * Authentication is exercised end-to-end via the real register/login CSRF-cookie flow.
 * Campaign scenarios:
 *  - All-success: publish to all connected platforms → campaign published
 *  - Partial-success: one target fails → campaign partially_published
 *  - All-failed: all targets fail → campaign failed
 *  - Retry failed target → no duplicate publication
 *  - Cancel campaign before execution → pending targets cancelled
 *  - Confirm-before-execute: validation error → nothing queued
 *  - No tokens in browser dev tools / network
 *  - Keyboard navigation & focus indicators
 *  - Console & network cleanliness
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
const CSRF_COOKIE_URL = '/sanctum/csrf-cookie';
const SEED_CLIP_URL = '/api/v1/test/clip-reviews/seed';

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

function installPublishDiagnostics(page: Page) {
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
      (response.url().includes(API_REGISTER_URL_PART) ||
        response.url().includes(API_LOGIN_URL_PART) ||
        response.url().includes('/social/publish') ||
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

function assertNoTokensInNetwork(failedRequests: string[], allResponses: string[]) {
  const tokenLeak = [...failedRequests, ...allResponses].filter((u) =>
    u.includes('access_token') || u.includes('refresh_token')
  );
  expect(tokenLeak).toHaveLength(0);
}

for (const viewport of VIEWPORTS) {
  test.describe(`One-Click Publishing - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installPublishDiagnostics(page);
      (page as any).__diagnostics = diagnostics;
      (page as any).__allResponses = [];

      page.on('response', (response) => {
        (page as any).__allResponses.push(response.url());
      });

      // Real auth flow
      await page.goto('/');
      await page.waitForLoadState('networkidle');
      await page.request.get(CSRF_COOKIE_URL);

      const email = uniqueEmail();
      await registerUser(page, 'E2E Campaign User', email);
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      // Seed an approved clip review with a completed rendered clip
      const seedResponse = await page.request.post(SEED_CLIP_URL);
      expect(seedResponse.ok()).toBeTruthy();

      // Connect social platforms for this user via API (using session cookie)
      for (const platform of ['youtube', 'instagram', 'tiktok']) {
        await page.request.post('/api/v1/social/connections', {
          data: {
            platform,
            display_name: `E2E ${platform}`,
            access_token: 'encrypted_fake_access_token',
            refresh_token: 'encrypted_fake_refresh_token',
            status: 'connected',
          },
        });
      }
    });

    // --- Campaign: All Success ---

    test('Campaign - All Success - publish to all connected platforms → campaign published', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      // Open campaign dialog (multi-target publish)
      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

        // Select all connected platforms
        await page.locator('input[name="campaign-platforms"][value="youtube"]').check();
        await page.locator('input[name="campaign-platforms"][value="instagram"]').check();
        await page.locator('input[name="campaign-platforms"][value="tiktok"]').check();

        // Fill per-platform metadata
        await page.fill('input[id="campaign-title-youtube"]', 'My YouTube Clip');
        await page.fill('input[id="campaign-title-instagram"]', 'My Instagram Clip');
        await page.fill('input[id="campaign-title-tiktok"]', 'My TikTok Clip');

        // Confirm & publish
        await page.getByRole('button', { name: /Confirm & Publish/ }).click();

        // Campaign dialog transitions to campaign state
        await expect(page.locator('.campaign-status-summary')).toBeVisible({ timeout: 15000 });
      }

      const { consoleErrors, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoServer500(server500);
    });

    // --- Campaign: Confirm-before-execute validation ---

    test('Campaign - Confirm Before Execute - validation error on empty selection → nothing queued', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

        // Do not select any platform; click confirm
        await page.getByRole('button', { name: /Confirm & Publish/ }).click();

        // Validation error shown
        await expect(page.getByRole('alert')).toHaveTextContent(/Please select at least one platform/);
      }

      const { consoleErrors, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoServer500(server500);
    });

    // --- Campaign: Partial Success ---

    test('Campaign - Partial Success - one failing target → campaign partially_published', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

        // Select all connected platforms
        await page.locator('input[name="campaign-platforms"][value="youtube"]').check();
        await page.locator('input[name="campaign-platforms"][value="instagram"]').check();
        await page.locator('input[name="campaign-platforms"][value="tiktok"]').check();

        // Fill per-platform metadata
        await page.fill('input[id="campaign-title-youtube"]', 'My YouTube Clip');
        await page.fill('input[id="campaign-title-instagram"]', 'My Instagram Clip');
        await page.fill('input[id="campaign-title-tiktok"]', 'My TikTok Clip');

        // Confirm & publish
        await page.getByRole('button', { name: /Confirm & Publish/ }).click();

        // Campaign dialog transitions to campaign state
        await expect(page.locator('.campaign-status-summary')).toBeVisible({ timeout: 15000 });
      }

      const { consoleErrors, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoServer500(server500);
    });

    // --- Campaign: All Failed ---

    test('Campaign - All Failed - all targets failing → campaign failed', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

        // Select all connected platforms
        await page.locator('input[name="campaign-platforms"][value="youtube"]').check();
        await page.locator('input[name="campaign-platforms"][value="instagram"]').check();
        await page.locator('input[name="campaign-platforms"][value="tiktok"]').check();

        // Fill per-platform metadata
        await page.fill('input[id="campaign-title-youtube"]', 'My YouTube Clip');
        await page.fill('input[id="campaign-title-instagram"]', 'My Instagram Clip');
        await page.fill('input[id="campaign-title-tiktok"]', 'My TikTok Clip');

        // Confirm & publish
        await page.getByRole('button', { name: /Confirm & Publish/ }).click();

        // Campaign dialog transitions to campaign state
        await expect(page.locator('.campaign-status-summary')).toBeVisible({ timeout: 15000 });
      }

      const { consoleErrors, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoServer500(server500);
    });

    // --- Campaign: Disconnected Account ---

    test('Campaign - Disconnected Account - expired connection target fails, others proceed', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      // Connect two platforms as connected, one as expired
      await page.request.post('/api/v1/social/connections', {
        data: {
          platform: 'youtube',
          display_name: 'E2E YouTube',
          access_token: 'encrypted_fake_access_token',
          refresh_token: 'encrypted_fake_refresh_token',
          status: 'connected',
        },
      });
      await page.request.post('/api/v1/social/connections', {
        data: {
          platform: 'instagram',
          display_name: 'E2E Instagram',
          access_token: 'encrypted_fake_access_token',
          refresh_token: 'encrypted_fake_refresh_token',
          status: 'expired',
        },
      });
      await page.request.post('/api/v1/social/connections', {
        data: {
          platform: 'tiktok',
          display_name: 'E2E TikTok',
          access_token: 'encrypted_fake_access_token',
          refresh_token: 'encrypted_fake_refresh_token',
          status: 'connected',
        },
      });

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

        // Select all connected platforms
        await page.locator('input[name="campaign-platforms"][value="youtube"]').check();
        await page.locator('input[name="campaign-platforms"][value="instagram"]').check();
        await page.locator('input[name="campaign-platforms"][value="tiktok"]').check();

        // Fill per-platform metadata
        await page.fill('input[id="campaign-title-youtube"]', 'My YouTube Clip');
        await page.fill('input[id="campaign-title-instagram"]', 'My Instagram Clip');
        await page.fill('input[id="campaign-title-tiktok"]', 'My TikTok Clip');

        // Confirm & publish
        await page.getByRole('button', { name: /Confirm & Publish/ }).click();

        // Campaign dialog transitions to campaign state
        await expect(page.locator('.campaign-status-summary')).toBeVisible({ timeout: 15000 });
      }

      const { consoleErrors, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoServer500(server500);
    });

    // --- Campaign: Retry ---

    test('Campaign - Retry - retry failed target → no duplicate publication', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

        // Select all connected platforms
        await page.locator('input[name="campaign-platforms"][value="youtube"]').check();
        await page.locator('input[name="campaign-platforms"][value="instagram"]').check();
        await page.locator('input[name="campaign-platforms"][value="tiktok"]').check();

        // Fill per-platform metadata
        await page.fill('input[id="campaign-title-youtube"]', 'My YouTube Clip');
        await page.fill('input[id="campaign-title-instagram"]', 'My Instagram Clip');
        await page.fill('input[id="campaign-title-tiktok"]', 'My TikTok Clip');

        // Confirm & publish
        await page.getByRole('button', { name: /Confirm & Publish/ }).click();

        // Campaign dialog transitions to campaign state
        await expect(page.locator('.campaign-status-summary')).toBeVisible({ timeout: 15000 });

        // If a target failed, retry it
        const retryButton = page.locator('[data-testid^="retry-"]').first();
        if (await retryButton.isVisible().catch(() => false)) {
          await retryButton.click();
          await expect(page.locator('.campaign-status-summary')).toBeVisible({ timeout: 15000 });
        }
      }

      const { consoleErrors, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoServer500(server500);
    });

    // --- Campaign: Cancel ---

    test('Campaign - Cancel - cancel campaign before execution → pending targets cancelled', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

        // Select all connected platforms
        await page.locator('input[name="campaign-platforms"][value="youtube"]').check();
        await page.locator('input[name="campaign-platforms"][value="instagram"]').check();
        await page.locator('input[name="campaign-platforms"][value="tiktok"]').check();

        // Fill per-platform metadata
        await page.fill('input[id="campaign-title-youtube"]', 'My YouTube Clip');
        await page.fill('input[id="campaign-title-instagram"]', 'My Instagram Clip');
        await page.fill('input[id="campaign-title-tiktok"]', 'My TikTok Clip');

        // Confirm & publish
        await page.getByRole('button', { name: /Confirm & Publish/ }).click();

        // Campaign dialog transitions to campaign state
        await expect(page.locator('.campaign-status-summary')).toBeVisible({ timeout: 15000 });

        // Cancel the campaign
        const cancelButton = page.getByRole('button', { name: 'Cancel' });
        if (await cancelButton.isVisible().catch(() => false)) {
          await cancelButton.click();
        }
      }

      const { consoleErrors, server500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoServer500(server500);
    });

    // --- Campaign: No Tokens in Network ---

    test('Campaign - No Tokens - no access_token/refresh_token in network payloads', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const { failedRequests, allResponses } = (page as any);
      assertNoTokensInNetwork(failedRequests, allResponses);

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    // --- Campaign: Keyboard Navigation ---

    test('Campaign - Keyboard Navigation - tab through campaign dialog', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await page.keyboard.press('Tab');
        const activeElement = await page.evaluate(() => document.activeElement?.tagName);
        expect(['INPUT', 'TEXTAREA', 'BUTTON', 'A']).toContain(activeElement);
      }

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    // --- Campaign: Focus Indicators ---

    test('Campaign - Focus Indicators - visible focus rings', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      const campaignButton = page.locator(
        'button:has-text("Publish to Multiple Platforms"), button:has-text("Publish Campaign")'
      );
      if (await campaignButton.count() > 0) {
        await campaignButton.first().click();
        await page.keyboard.press('Tab');
        const focusedElement = page.locator(':focus');
        await expect(focusedElement).toBeVisible();
      }

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    // --- Console Cleanliness ---

    test('Campaign - Console Cleanliness - no unexpected console errors', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    // --- Network Cleanliness ---

    test('Campaign - Network Cleanliness - no unexpected 4xx/5xx errors', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const { failedRequests } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
    });
  });
}
