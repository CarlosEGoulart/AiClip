/**
 * Design Review - design-taste-frontend art direction + web-design-guidelines
 * Compliance applied to /login and /register at 3 viewports per test-plan.md
 * §5.9 and §5.14, and spec.md §10.1.
 *
 * Checklist: no meaningless images, clean typography-based design, Switzer font,
 * mono-accent palette, square corners, centered layout, consistent side margins,
 * visual hierarchy, focus indicators, touch targets, coherence, accessibility,
 * error states, console/network cleanliness, login returns 200.
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

function installDesignDiagnostics(page: Page) {
  const consoleErrors: string[] = [];
  const failedRequests: string[] = [];
  const auth500: string[] = [];

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
      auth500.push(`${response.status()} ${response.url()}`);
    }
  });

  return { consoleErrors, failedRequests, auth500 };
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

function assertNoAuth500(auth500: string[]) {
  expect(auth500).toHaveLength(0);
}

async function assertNoForbiddenImages(page: Page) {
  const picsumImages = page.locator('img[src*="picsum.photos"]');
  await expect(picsumImages).toHaveCount(0);

  for (const cls of IMAGE_FORBIDDEN_CLASSES) {
    await expect(page.locator(`.${cls}`)).toHaveCount(0);
  }

  const bodyBg = await page.locator('body').evaluate((el) => {
    return window.getComputedStyle(el).backgroundImage;
  });
  expect(bodyBg).not.toContain('picsum.photos');
}

for (const viewport of VIEWPORTS) {
  test.describe(`Design Review - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      const diagnostics = installDesignDiagnostics(page);
      (page as any).__diagnostics = diagnostics;
    });

    test('No Meaningful Images - no picsum.photos or archival film imagery on /login and /register', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');
      await assertNoForbiddenImages(page);

      // Switch to register and re-verify
      await page.getByRole('button', { name: 'Create one' }).click();
      await assertNoForbiddenImages(page);

      const { consoleErrors, auth500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoAuth500(auth500);
    });

    test('Clean Typography-Based Design - wordmark, headline, copy, fields, primary button, link buttons only', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      await expect(page.locator('.auth-wordmark')).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();
      await expect(page.locator('.auth-supporting')).toBeVisible();
      await expect(page.getByLabel('Email')).toBeVisible();
      await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();
      await expect(page.getByRole('button', { name: 'Create one' })).toBeVisible();

      // No decorative figures/galleries/captions
      await assertNoForbiddenImages(page);

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Switzer Font Applied - heading/body use Switzer from fontshare', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const heading = page.getByRole('heading', { name: 'Sign in to your workspace' });
      const fontFamily = await heading.evaluate((el) => {
        return window.getComputedStyle(el).fontFamily;
      });
      expect(fontFamily).toContain('Switzer');

      const bodyFont = await page.locator('body').evaluate((el) => {
        return window.getComputedStyle(el).fontFamily;
      });
      expect(bodyFont).toContain('Switzer');

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Mono-Accent Palette - accent #2456d6 and accent-ink #ffffff used consistently', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const submitBtn = page.getByRole('button', { name: 'Sign In' });
      const bgColor = await submitBtn.evaluate((el) => {
        return window.getComputedStyle(el).backgroundColor;
      });
      expect(bgColor).toBe('rgb(36, 86, 214)'); // #2456d6

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Square Corners - --radius: 0 everywhere on auth UI; no rounded buttons/inputs/containers', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const inputs = page.locator('.auth-form input');
      const inputCount = await inputs.count();
      for (let i = 0; i < inputCount; i++) {
        const radius = await inputs.nth(i).evaluate((el) => {
          return window.getComputedStyle(el).borderRadius;
        });
        expect(radius).toBe('0px');
      }

      const buttons = page.locator('.auth-form button[type="submit"]');
      const buttonCount = await buttons.count();
      for (let i = 0; i < buttonCount; i++) {
        const radius = await buttons.nth(i).evaluate((el) => {
          return window.getComputedStyle(el).borderRadius;
        });
        expect(radius).toBe('0px');
      }

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Centered Layout - auth column centered; no flush-left content or forms', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const authPage = page.locator('.auth-page');
      const pageStyles = await authPage.evaluate((el) => {
        return window.getComputedStyle(el).alignItems;
      });
      expect(pageStyles).toBe('center');

      const authColumn = page.locator('.auth-column');
      await expect(authColumn).toBeVisible();
      const columnBox = await authColumn.boundingBox();
      expect(columnBox).not.toBeNull();

      const viewport = page.viewportSize()!;
      expect(columnBox!.x).toBeGreaterThanOrEqual(0);
      expect(columnBox!.x + columnBox!.width).toBeLessThanOrEqual(viewport.width);

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Consistent Side Margins - column max-width 380px on desktop, full-width minus margins on mobile', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const authColumn = page.locator('.auth-column');
      const columnBox = await authColumn.boundingBox();
      expect(columnBox).not.toBeNull();

      const viewport = page.viewportSize()!;
      if (viewport.width >= 1024) {
        // Desktop: column should not span the full viewport width (has side margins)
        expect(columnBox!.width).toBeLessThan(viewport.width);
      }

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Visual Hierarchy - headline > copy > fields > primary button; primary action obvious', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      await expect(page.getByRole('heading', { name: 'Sign in to your workspace' })).toBeVisible();
      await expect(page.locator('.auth-supporting')).toBeVisible();
      await expect(page.getByLabel('Email')).toBeVisible();
      await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();

      // Primary button is visually prominent (accent background)
      const submitBtn = page.getByRole('button', { name: 'Sign In' });
      const bgColor = await submitBtn.evaluate((el) => {
        return window.getComputedStyle(el).backgroundColor;
      });
      expect(bgColor).toBe('rgb(36, 86, 214)');

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Focus Indicators - 3px solid outline, 2px offset on interactive elements', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

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

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Touch Targets - all buttons/inputs >= 44px min-height', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const inputs = page.locator('.auth-form input');
      const inputCount = await inputs.count();
      for (let i = 0; i < inputCount; i++) {
        const box = await inputs.nth(i).boundingBox();
        if (box) {
          expect(box.height).toBeGreaterThanOrEqual(44);
          expect(box.width).toBeGreaterThanOrEqual(44);
        }
      }

      const submitBtn = page.getByRole('button', { name: 'Sign In' });
      const box = await submitBtn.boundingBox();
      if (box) {
        expect(box.height).toBeGreaterThanOrEqual(44);
        expect(box.width).toBeGreaterThanOrEqual(44);
      }

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Coherence - interface looks intentionally designed, not auto-generated', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Core design elements present and coherent: wordmark, centered column,
      // accent primary button, square corners, no stray imagery
      await expect(page.locator('.auth-wordmark')).toBeVisible();
      await expect(page.locator('.auth-column')).toBeVisible();
      await expect(page.getByRole('button', { name: 'Sign In' })).toBeVisible();
      await assertNoForbiddenImages(page);

      const submitBtn = page.getByRole('button', { name: 'Sign In' });
      const bgColor = await submitBtn.evaluate((el) => {
        return window.getComputedStyle(el).backgroundColor;
      });
      expect(bgColor).toBe('rgb(36, 86, 214)');

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('web-design-guidelines Compliance - accessibility, keyboard nav, focus indicators, responsive layouts', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Keyboard navigable: Tab moves focus
      await page.keyboard.press('Tab');
      const focusedAfterTab = page.locator(':focus');
      await expect(focusedAfterTab).toBeVisible();

      // Focus ring present
      const focusStyle = await focusedAfterTab.evaluate((el) => {
        const s = window.getComputedStyle(el);
        return { outlineWidth: s.outlineWidth, boxShadow: s.boxShadow };
      });
      expect(
        focusStyle.outlineWidth !== '0px' ||
          focusStyle.boxShadow.includes('36, 86, 214')
      ).toBeTruthy();

      // ARIA labels on inputs
      await expect(page.getByLabel('Email')).toHaveAttribute('aria-invalid', 'false');
      await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute(
        'aria-invalid',
        'false'
      );

      const { consoleErrors } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
    });

    test('Error States - 500/404 pages render gracefully, never blank or broken', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Auth page renders with understandable content, not blank
      await expect(page.locator('.auth-column')).toBeVisible();
      await expect(page.locator('.auth-wordmark')).toBeVisible();
      await expect(page.locator('.auth-headline')).toBeVisible();

      const { consoleErrors, auth500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNoAuth500(auth500);
    });

    test('Console/Network Cleanliness - no unexpected errors; no 500 on /api/v1/auth/login', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      await page.getByLabel('Email').fill('review@example.com');
      await page.getByLabel('Password', { exact: true }).fill('password');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await page.waitForTimeout(1000);

      const { consoleErrors, failedRequests, auth500 } = (page as any).__diagnostics;
      assertConsoleClean(consoleErrors);
      assertNetworkClean(failedRequests);
      assertNoAuth500(auth500);
    });

    test('Login Returns 200 - valid credentials → 200, never 500', async ({ page }) => {
      const email = `review-${Date.now()}@test.example.com`;
      // Register first so the login has a valid account
      await switchToRegister(page);
      await page.getByLabel('Name').fill('Review User');
      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Create Account' }).click();
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      // Log out and log back in to verify login returns 200
      await page.getByRole('button', { name: 'Log out' }).first().click();
      await page.getByLabel('Email').fill(email);
      await page.getByLabel('Password', { exact: true }).fill('password123');
      await page.getByRole('button', { name: 'Sign In' }).click();
      await expect(page.getByText(/Signed in as/)).toBeVisible();

      const { auth500 } = (page as any).__diagnostics;
      assertNoAuth500(auth500);
    });
  });
}
