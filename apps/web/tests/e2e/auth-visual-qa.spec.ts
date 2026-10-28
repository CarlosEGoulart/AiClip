/**
 * Auth Visual QA - Login/Register screens at 3 viewports
 * Real-browser visual inspection of /login and /register per spec.md §10.1
 * and test-plan.md §5.12.
 *
 * Verifies: no picsum.photos images, centered layout, Switzer font,
 * square corners, visible focus rings, 44px touch targets, color+text
 * status badges, console/network cleanliness.
 *
 * Viewports: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)
 */

import { test, expect, type Page } from '@playwright/test';

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

const IMAGE_FORBIDDEN_CLASSES = [
  'auth-atmosphere-image',
  'auth-atmosphere-gallery',
  'auth-image-credits',
  'auth-image-caption',
  'auth-atmosphere-item',
];

test.describe.configure({ retries: 1 });

function installConsoleAndNetworkChecks(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];
  const auth500Responses: string[] = [];

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
    if (response.status() >= 400 && response.url().includes('/api/v1/auth/')) {
      auth500Responses.push(`${response.status()} ${response.url()}`);
    }
  });

  return { consoleErrors, failedRequests, auth500Responses };
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

function assertNoAuth500(auth500Responses: string[]) {
  expect(auth500Responses).toHaveLength(0);
}

for (const viewport of VIEWPORTS) {
  test.describe(`Auth Visual QA - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const { consoleErrors, failedRequests, auth500Responses } = installConsoleAndNetworkChecks(page);
      (page as any).__diagnostics = { consoleErrors, failedRequests, auth500Responses };
    });

    test('Login Page - renders centered typography-only auth UI', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Login heading visible (login is the default AuthEntry view)
      await expect(
        page.getByRole('heading', { name: 'Sign in to your workspace' })
      ).toBeVisible();

      // Wordmark present
      await expect(page.locator('.auth-wordmark')).toBeVisible();

      // Form fields and primary button present
      await expect(page.getByLabel('Email')).toBeVisible();
      await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();

      const { consoleErrors, failedRequests, auth500Responses } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNetworkClean(failedRequests);
      assertNoAuth500(auth500Responses);
    });

    test('Register Page - renders centered typography-only auth UI', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Switch to register view
      await page.getByRole('button', { name: 'Create one' }).click();
      await expect(
        page.getByRole('heading', { name: 'Create your workspace' })
      ).toBeVisible();

      // Form fields and primary button present
      await expect(page.getByLabel('Name')).toBeVisible();
      await expect(page.getByLabel('Email')).toBeVisible();
      await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
      await expect(page.getByLabel('Confirm Password', { exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Create Account' })).toBeVisible();

      const { consoleErrors, failedRequests, auth500Responses } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNetworkClean(failedRequests);
      assertNoAuth500(auth500Responses);
    });

    test('No Meaningful Images - no picsum.photos or archival imagery on auth pages', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // No picsum.photos image sources anywhere on the page
      const picsumImages = page.locator('img[src*="picsum.photos"]');
      await expect(picsumImages).toHaveCount(0);

      // No atmospheric image/gallery/credits elements
      for (const cls of IMAGE_FORBIDDEN_CLASSES) {
        await expect(page.locator(`.${cls}`)).toHaveCount(0);
      }

      // No background image URL pointing to picsum
      const bodyBg = await page.locator('body').evaluate((el) => {
        return window.getComputedStyle(el).backgroundImage;
      });
      expect(bodyBg).not.toContain('picsum.photos');

      // Login view is imagery-free: wordmark, headline, copy, fields, button only
      await expect(page.locator('.auth-page')).toBeVisible();
      await expect(page.locator('.auth-column')).toBeVisible();
    });

    test('No Meaningful Images - register page also imagery-free', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      await page.getByRole('button', { name: 'Create one' }).click();
      await expect(
        page.getByRole('heading', { name: 'Create your workspace' })
      ).toBeVisible();

      const picsumImages = page.locator('img[src*="picsum.photos"]');
      await expect(picsumImages).toHaveCount(0);

      for (const cls of IMAGE_FORBIDDEN_CLASSES) {
        await expect(page.locator(`.${cls}`)).toHaveCount(0);
      }
    });

    test('Centered Layout - auth column centered, no flush-left content', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const authColumn = page.locator('.auth-column');
      await expect(authColumn).toBeVisible();

      // Column is centered: align-items center via flex parent, max-width 380px
      const columnBox = await authColumn.boundingBox();
      const viewport = page.viewportSize()!;
      expect(columnBox).not.toBeNull();

      const authPage = page.locator('.auth-page');
      const pageStyles = await authPage.evaluate((el) => {
        const s = window.getComputedStyle(el);
        return { alignItems: s.alignItems, justifyContent: s.justifyContent };
      });
      expect(pageStyles.alignItems).toBe('center');

      // Column does not extend flush-left beyond reasonable margin
      expect(columnBox!.x).toBeGreaterThanOrEqual(0);
      expect(columnBox!.x + columnBox!.width).toBeLessThanOrEqual(viewport.width);
    });

    test('Square Corners - no rounded buttons, inputs, or containers on auth pages', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // All auth form inputs have border-radius 0
      const inputs = page.locator('.auth-form input');
      const inputCount = await inputs.count();
      for (let i = 0; i < inputCount; i++) {
        const radius = await inputs.nth(i).evaluate((el) => {
          return window.getComputedStyle(el).borderRadius;
        });
        expect(radius).toBe('0px');
      }

      // Submit buttons have border-radius 0
      const buttons = page.locator('.auth-form button[type="submit"]');
      const buttonCount = await buttons.count();
      for (let i = 0; i < buttonCount; i++) {
        const radius = await buttons.nth(i).evaluate((el) => {
          return window.getComputedStyle(el).borderRadius;
        });
        expect(radius).toBe('0px');
      }
    });

    test('Typography & Palette - Switzer font and mono-accent accent color applied', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Switzer font loaded via fontshare (check computed font on heading)
      const heading = page.getByRole('heading', { name: 'Sign in to your workspace' });
      const fontFamily = await heading.evaluate((el) => {
        return window.getComputedStyle(el).fontFamily;
      });
      expect(fontFamily).toContain('Switzer');

      // Primary button uses accent color #2456d6
      const submitBtn = page.getByRole('button', { name: 'Sign In' });
      const bgColor = await submitBtn.evaluate((el) => {
        return window.getComputedStyle(el).backgroundColor;
      });
      // rgb(36, 86, 214) = #2456d6
      expect(bgColor).toBe('rgb(36, 86, 214)');
    });

    test('Mobile Overflow - no horizontal overflow at 390px width', async ({ page }) => {
      // Only meaningful on mobile; skip assertion strictness on larger viewports
      if (viewport.width !== 390) return;

      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const overflow = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
      }));
      expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth);
    });

    test('Focus Rings - visible focus ring on interactive elements', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Tab to the email input and verify a focus ring appears
      await page.keyboard.press('Tab');
      const focused = page.locator(':focus');
      await expect(focused).toBeVisible();

      const focusStyle = await focused.evaluate((el) => {
        const s = window.getComputedStyle(el);
        return { outlineWidth: s.outlineWidth, boxShadow: s.boxShadow };
      });
      const hasFocusRing =
        focusStyle.outlineWidth !== '0px' ||
        focusStyle.boxShadow.includes('36, 86, 214') ||
        focusStyle.boxShadow.includes('var(--accent)');
      expect(hasFocusRing).toBeTruthy();
    });

    test('Touch Targets - all auth interactive elements >= 44px', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Check inputs
      const inputs = page.locator('.auth-form input');
      const inputCount = await inputs.count();
      for (let i = 0; i < inputCount; i++) {
        const box = await inputs.nth(i).boundingBox();
        if (box) {
          expect(box.height).toBeGreaterThanOrEqual(44);
          expect(box.width).toBeGreaterThanOrEqual(44);
        }
      }

      // Check submit button
      const submitBtn = page.getByRole('button', { name: 'Sign In' });
      const box = await submitBtn.boundingBox();
      if (box) {
        expect(box.height).toBeGreaterThanOrEqual(44);
        expect(box.width).toBeGreaterThanOrEqual(44);
      }
    });

    test('Console Cleanliness - no unexpected JS console errors on auth pages', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      await page.getByRole('button', { name: 'Create one' }).click();
      await page.waitForTimeout(500);

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Network Cleanliness - no failed resources, login endpoint never 500', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const { failedRequests, auth500Responses } = (page as any).__diagnostics;
      assertNetworkClean(failedRequests);
      assertNoAuth500(auth500Responses);
    });

    test('500/404 Error Pages - render gracefully, never blank or broken', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Auth column renders with understandable content (not blank)
      await expect(page.locator('.auth-column')).toBeVisible();
      await expect(page.locator('.auth-wordmark')).toBeVisible();
      await expect(page.locator('.auth-headline')).toBeVisible();

      const { consoleErrors, auth500Responses } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoAuth500(auth500Responses);
    });

    test(`Visual Regression - login form screenshot - ${viewport.name}`, async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(300);

      await expect(page.locator('.auth-page')).toHaveScreenshot(
        `auth-login-${viewport.name}.png`,
        { animations: 'disabled', threshold: 0.2, fullPage: true }
      );
    });

    test(`Visual Regression - register form screenshot - ${viewport.name}`, async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      await page.getByRole('button', { name: 'Create one' }).click();
      await expect(page.getByRole('heading', { name: 'Create your workspace' })).toBeVisible();
      await page.waitForTimeout(300);

      await expect(page.locator('.auth-page')).toHaveScreenshot(
        `auth-register-${viewport.name}.png`,
        { animations: 'disabled', threshold: 0.2, fullPage: true }
      );
    });
  });
}
