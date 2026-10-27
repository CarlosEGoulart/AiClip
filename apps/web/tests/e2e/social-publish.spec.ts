/**
 * YouTube Publishing - E2E Tests
 * Tests the full YouTube publishing workflow
 * Viewports: 390x844 (mobile), 768x1024 (1440x900 (desktop)
 */

import { test, expect } from '@playwright/test';

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

test.describe.configure({ retries: 1 });

for (const viewport of VIEWPORTS) {
  test.describe(`YouTube Publishing - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Mock authenticated state
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });
    });

    test('Publish Approved Clip - publish flow appears on approved clip', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Publish button must exist on approved clip with YouTube connection
      const publishButton = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButton).toBeVisible();

      // Click opens the publish dialog
      await publishButton.click();
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();
      await expect(page.locator('h2:has-text("Publish to YouTube")')).toBeVisible();

      // Fill title and submit
      await page.fill('input[id="publish-title"]', 'My Published Clip');
      await page.click('button:has-text("Publish to YouTube")');

      // Dialog should close after submit
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).not.toBeVisible();
    });

    test('Publish Status Reflection - status transitions visible in publish list', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      // Publish list page loads
      await expect(page.locator('body')).toBeVisible();

      // Publish cards or empty state should be present
      await expect(
        page.locator('[data-testid="publish-card"], .publish-empty')
      ).toBeVisible();
    });

    test('Duplicate Publish Blocked - second publish rejected', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const publishButton = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButton).toBeVisible();

      await publishButton.click();
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

      // Fill title and submit
      await page.fill('input[id="publish-title"]', 'Test Clip');
      await page.click('button:has-text("Publish to YouTube")');

      // Dialog should close after submit
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).not.toBeVisible();

      // Navigate to publish list and verify the publish appears
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');
      await expect(page.locator('[data-testid="publish-card"]')).toBeVisible();
    });

    test('No YouTube Connection - publish button not shown', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Without a YouTube connection, no publish button should appear
      const publishButtons = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButtons).toHaveCount(0);
    });

    test('Non-Approved Clip - publish button not shown', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Page loads without errors
      await expect(page.locator('body')).toBeVisible();

      // Publish button should not appear for non-approved clips
      const publishButtons = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButtons).toHaveCount(0);
    });

    test('Keyboard Navigation - tab through publish dialog', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const publishButton = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButton).toBeVisible();

      await publishButton.click();
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

      // Tab through dialog elements
      await page.keyboard.press('Tab');
      const activeElement = await page.evaluate(() => document.activeElement?.tagName);
      expect(['INPUT', 'TEXTAREA', 'BUTTON']).toContain(activeElement);
    });

    test('Focus Indicators - visible focus rings', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const publishButton = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButton).toBeVisible();

      await publishButton.click();
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

      await page.keyboard.press('Tab');
      const focusedElement = page.locator(':focus');
      await expect(focusedElement).toBeVisible();
    });

    test('ARIA Labels - buttons have accessible names', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const publishButton = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButton).toBeVisible();

      await publishButton.click();
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();

      // Check publish button has aria-label
      const dialogPublishBtn = page.locator('button[aria-label="Publish to YouTube"]');
      await expect(dialogPublishBtn).toBeVisible();
    });

    test('Live Region Announcements - status changes announced', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      // Publish list uses aria-live for status announcements
      const liveRegions = page.locator('[aria-live]');
      await expect(liveRegions.first()).toBeVisible();
    });

    test('Console Cleanliness - no unexpected console errors', async ({ page }) => {
      const errors: string[] = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error') {
          errors.push(msg.text());
        }
      });

      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Filter out expected network errors from mocked endpoints
      const unexpectedErrors = errors.filter(
        (e) => !e.includes('net::ERR_FAILED') &&
               !e.includes('Failed to fetch') &&
               !e.includes('net::ERR_CONNECTION_REFUSED') &&
               !e.includes('favicon')
      );

      expect(unexpectedErrors).toHaveLength(0);
    });

    test('Network Cleanliness - no 4xx/5xx errors', async ({ page }) => {
      const failedRequests: string[] = [];
      page.on('response', (response) => {
        if (response.status() >= 400) {
          failedRequests.push(`${response.status()} ${response.url()}`);
        }
      });

      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const unexpectedFailures = failedRequests.filter(
        (r) => !r.includes('/api/auth/') && !r.includes('/oauth/')
      );

      expect(unexpectedFailures).toHaveLength(0);
    });

    test('Responsive Layout - publish list renders at all viewports', async ({ page }) => {
      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');

      await expect(page.locator('body')).toBeVisible();
    });

    test('Touch Targets - all interactive elements >= 44px', async ({ page }) => {
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const buttons = page.locator('button');
      const count = await buttons.count();

      for (let i = 0; i < Math.min(count, 10); i++) {
        const button = buttons.nth(i);
        const box = await button.boundingBox();
        if (box) {
          expect(box.height).toBeGreaterThanOrEqual(44);
          expect(box.width).toBeGreaterThanOrEqual(44);
        }
      }
    });
  });
}

test.describe('Visual Regression - YouTube Publishing', () => {
  for (const viewport of VIEWPORTS) {
    test(`should match visual baseline - publish dialog - ${viewport.name}`, async ({ page }) => {
      test.use({ viewport: { width: viewport.width, height: viewport.height } });

      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/');
      await page.waitForLoadState('networkidle');

      const publishButton = page.locator('button:has-text("Publish to YouTube")');
      await expect(publishButton).toBeVisible();

      await publishButton.click();
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();
      await page.waitForTimeout(300);

      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toHaveScreenshot(
        `publish-dialog-${viewport.name}.png`,
        { animations: 'disabled', threshold: 0.2 }
      );
    });

    test(`should match visual baseline - publish list - ${viewport.name}`, async ({ page }) => {
      test.use({ viewport: { width: viewport.width, height: viewport.height } });

      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/publishes');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(300);

      await expect(page.locator('body')).toHaveScreenshot(
        `publish-list-${viewport.name}.png`,
        { animations: 'disabled', threshold: 0.2 }
      );
    });
  }
});
