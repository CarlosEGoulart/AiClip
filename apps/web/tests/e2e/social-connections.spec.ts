/**
 * Social Connections OAuth Flow - E2E Tests
 * Tests the full OAuth flow for connecting social accounts
 * Viewports: 390x844 (mobile), 768x1024 (tablet), 1440x900 (desktop)
 */

import { test, expect } from '@playwright/test';

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

const PLATFORMS = ['youtube', 'instagram', 'tiktok'] as const;

test.describe.configure({ retries: 1 });

for (const viewport of VIEWPORTS) {
  test.describe(`Social Connections OAuth Flow - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    test.beforeEach(async ({ page }) => {
      // Navigate to the app
      await page.goto('/');
      
      // Wait for auth page to load
      await page.waitForLoadState('networkidle');
      
      // Check if we need to log in (mock auth for testing)
      // In a real test, we'd use a test user or mock the auth state
      await page.waitForSelector('.auth-page', { timeout: 10000 }).catch(() => {});
    });

    test('should display social connections page when authenticated', async ({ page }) => {
      // Mock authenticated state by setting localStorage/session
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      // Navigate directly to connections page
      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Check page loads with correct title
      await expect(page.locator('h1:has-text("Social Connections")')).toBeVisible();
      
      // Check header has back button
      await expect(page.locator('header .back-button')).toBeVisible();
      
      // Check connect button exists
      await expect(page.locator('button:has-text("Connect Account")')).toBeVisible();
    });

    test('should open connect modal when clicking Connect Account', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Click connect button
      await page.click('button:has-text("Connect Account")');

      // Verify modal opens
      await expect(page.locator('[role="dialog"][aria-modal="true"]')).toBeVisible();
      await expect(page.locator('h2:has-text("Connect Social Account")')).toBeVisible();

      // Verify all three platform buttons are present
      for (const platform of PLATFORMS) {
        await expect(page.locator(`button[aria-label="Connect ${platform.charAt(0).toUpperCase() + platform.slice(1)}"]`)).toBeVisible();
      }
    });

    test('should navigate platform selection with keyboard', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Open modal
      await page.click('button:has-text("Connect Account")');
      await expect(page.locator('[role="dialog"]')).toBeVisible();

      // First platform button should be focused
      const firstButton = page.locator('.platform-button').first();
      await expect(firstButton).toBeFocused();

      // Navigate with ArrowRight
      await page.keyboard.press('ArrowRight');
      const secondButton = page.locator('.platform-button').nth(1);
      await expect(secondButton).toBeFocused();

      // Navigate with ArrowRight again
      await page.keyboard.press('ArrowRight');
      const thirdButton = page.locator('.platform-button').nth(2);
      await expect(thirdButton).toBeFocused();

      // Navigate with ArrowLeft (should wrap)
      await page.keyboard.press('ArrowLeft');
      await expect(secondButton).toBeFocused();

      // Navigate with ArrowLeft again
      await page.keyboard.press('ArrowLeft');
      await expect(firstButton).toBeFocused();
    });

    test('should close modal with Escape key', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      await page.click('button:has-text("Connect Account")');
      await expect(page.locator('[role="dialog"]')).toBeVisible();

      // Press Escape
      await page.keyboard.press('Escape');
      await expect(page.locator('[role="dialog"]')).not.toBeVisible();
    });

    test('should close modal with close button', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      await page.click('button:has-text("Connect Account")');
      await expect(page.locator('[role="dialog"]')).toBeVisible();

      // Click close button
      await page.click('button[aria-label="Close modal"]');
      await expect(page.locator('[role="dialog"]')).not.toBeVisible();
    });

    test('should trap focus within modal', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      await page.click('button:has-text("Connect Account")');
      await expect(page.locator('[role="dialog"]')).toBeVisible();

      // Get all focusable elements in modal
      const focusableElements = page.locator('[role="dialog"] button, [role="dialog"] [href], [role="dialog"] input, [role="dialog"] select, [role="dialog"] textarea, [role="dialog"] [tabindex]:not([tabindex="-1"])');
      const count = await focusableElements.count();
      expect(count).toBeGreaterThan(0);

      // Tab through elements - should cycle within modal
      for (let i = 0; i < count + 2; i++) {
        await page.keyboard.press('Tab');
        const activeElement = await page.evaluate(() => document.activeElement);
        const isInModal = await page.locator('[role="dialog"]').evaluate((dialog) => dialog.contains(activeElement));
        expect(isInModal).toBeTruthy();
      }
    });

    test('should show loading state when connecting', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      await page.click('button:has-text("Connect Account")');
      await expect(page.locator('[role="dialog"]')).toBeVisible();

      // Click first platform (YouTube)
      await page.click('button[aria-label="Connect YouTube"]');

      // Should show loading state
      await expect(page.locator('text=Connecting…')).toBeVisible();
      await expect(page.locator('.platform-button')).toHaveAttribute('disabled', '');
    });

    test('should display connection cards when connections exist', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
        // Mock connections data
        localStorage.setItem('mockConnections', JSON.stringify([
          {
            id: 1,
            platform: 'youtube',
            platform_label: 'YouTube',
            display_name: 'My Channel',
            status: 'connected',
            avatar_url: null,
            channel_url: 'https://youtube.com/@mychannel'
          },
          {
            id: 2,
            platform: 'instagram',
            platform_label: 'Instagram',
            display_name: '@myhandle',
            status: 'connected',
            avatar_url: null,
            channel_url: 'https://instagram.com/@myhandle'
          }
        ]));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Wait for connections to load
      await page.waitForSelector('.connection-card', { timeout: 5000 });

      // Check connection cards are displayed
      const cards = page.locator('.connection-card');
      await expect(cards).toHaveCount(2);

      // Check first card (YouTube)
      const firstCard = cards.first();
      await expect(firstCard.locator('.card-name')).toHaveText('My Channel');
      await expect(firstCard.locator('.card-platform')).toHaveText('YouTube');
      await expect(firstCard.locator('.card-status')).toHaveText('Connected');

      // Check second card (Instagram)
      const secondCard = cards.nth(1);
      await expect(secondCard.locator('.card-name')).toHaveText('@myhandle');
      await expect(secondCard.locator('.card-platform')).toHaveText('Instagram');
    });

    test('should show empty state when no connections', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
        localStorage.setItem('mockConnections', JSON.stringify([]));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Check empty state
      await expect(page.locator('.connections-empty')).toBeVisible();
      await expect(page.locator('h3:has-text("No connections yet")')).toBeVisible();
      await expect(page.locator('button:has-text("Connect your first account")')).toBeVisible();
    });

    test('should have no console errors', async ({ page }) => {
      const errors: string[] = [];
      page.on('console', (msg) => {
        if (msg.type() === 'error') {
          errors.push(msg.text());
        }
      });

      page.on('pageerror', (error) => {
        errors.push(error.message);
      });

      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      await page.click('button:has-text("Connect Account")');
      await page.waitForTimeout(500);
      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);

      // Filter out expected errors (network errors from mocked OAuth)
      const unexpectedErrors = errors.filter(
        (e) => !e.includes('net::ERR_FAILED') && 
               !e.includes('Failed to fetch') &&
               !e.includes('net::ERR_CONNECTION_REFUSED')
      );

      expect(unexpectedErrors).toHaveLength(0);
    });

    test('should have no 4xx/5xx network errors', async ({ page }) => {
      const failedRequests: string[] = [];
      
      page.on('response', (response) => {
        if (response.status() >= 400) {
          failedRequests.push(`${response.status()} ${response.url()}`);
        }
      });

      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      await page.click('button:has-text("Connect Account")');
      await page.waitForTimeout(500);
      await page.keyboard.press('Escape');
      await page.waitForTimeout(500);

      // Filter out expected 404s for mock OAuth endpoints
      const unexpectedFailures = failedRequests.filter(
        (r) => !r.includes('/api/auth/') && !r.includes('/oauth/')
      );

      expect(unexpectedFailures).toHaveLength(0);
    });

    test('should have visible focus rings on interactive elements', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Check focus ring on connect button
      await page.keyboard.press('Tab');
      const connectButton = page.locator('button:has-text("Connect Account")');
      await expect(connectButton).toBeFocused();
      
      const focusRing = await connectButton.evaluate((el) => {
        const styles = window.getComputedStyle(el);
        return styles.outlineWidth !== '0px' || styles.boxShadow.includes('rgb');
      });
      expect(focusRing).toBeTruthy();

      // Check focus ring on platform buttons in modal
      await page.click('button:has-text("Connect Account")');
      await page.keyboard.press('Tab');
      const firstPlatformButton = page.locator('.platform-button').first();
      await expect(firstPlatformButton).toBeFocused();
    });

    test('should meet touch target minimum (44px)', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Check all buttons meet 44px minimum
      const buttons = page.locator('button');
      const count = await buttons.count();

      for (let i = 0; i < count; i++) {
        const button = buttons.nth(i);
        const box = await button.boundingBox();
        if (box) {
          expect(box.height).toBeGreaterThanOrEqual(44);
          expect(box.width).toBeGreaterThanOrEqual(44);
        }
      }

      // Open modal and check platform buttons
      await page.click('button:has-text("Connect Account")');
      const platformButtons = page.locator('.platform-button');
      const platformCount = await platformButtons.count();

      for (let i = 0; i < platformCount; i++) {
        const button = platformButtons.nth(i);
        const box = await button.boundingBox();
        if (box) {
          expect(box.height).toBeGreaterThanOrEqual(44);
          expect(box.width).toBeGreaterThanOrEqual(44);
        }
      }
    });

    test('should respect prefers-reduced-motion', async ({ page }) => {
      // Set reduced motion preference
      await page.emulateMedia({ reducedMotion: 'reduce' });

      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Open modal - animations should be disabled
      await page.click('button:has-text("Connect Account")');
      await expect(page.locator('[role="dialog"]')).toBeVisible();

      // Check that animations are disabled
      const animationDuration = await page.locator('[role="dialog"]').evaluate((el) => {
        return window.getComputedStyle(el).animationDuration;
      });
      expect(animationDuration).toBe('0.01ms');

      // Close modal
      await page.keyboard.press('Escape');
    });

    test('back button navigates to dashboard', async ({ page }) => {
      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      // Navigate to dashboard first
      await page.goto('/');
      await page.waitForLoadState('networkidle');

      // Then navigate to connections
      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      // Click back button
      await page.click('header .back-button');

      // Should navigate back to dashboard
      await expect(page).toHaveURL('/');
    });
  });
}

test.describe('Visual Regression - Social Connections', () => {
  for (const viewport of VIEWPORTS) {
    test(`should match visual baseline - ${viewport.name}`, async ({ page }) => {
      test.use({ viewport: { width: viewport.width, height: viewport.height } });

      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(500); // Allow animations to complete

      // Take screenshot
      await expect(page).toHaveScreenshot(`social-connections-${viewport.name}.png`, {
        fullPage: true,
        animations: 'disabled',
        threshold: 0.2,
      });
    });

    test(`should match visual baseline - connect modal - ${viewport.name}`, async ({ page }) => {
      test.use({ viewport: { width: viewport.width, height: viewport.height } });

      await page.evaluate(() => {
        localStorage.setItem('authenticated', 'true');
        localStorage.setItem('user', JSON.stringify({
          id: 1,
          name: 'Test User',
          email: 'test@example.com'
        }));
      });

      await page.goto('/connections');
      await page.waitForLoadState('networkidle');

      await page.click('button:has-text("Connect Account")');
      await expect(page.locator('[role="dialog"]')).toBeVisible();
      await page.waitForTimeout(300);

      await expect(page.locator('[role="dialog"]')).toHaveScreenshot(`connect-modal-${viewport.name}.png`, {
        animations: 'disabled',
        threshold: 0.2,
      });
    });
  }
});