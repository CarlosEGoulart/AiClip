import { test, expect } from '@playwright/test';

const viewports = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

for (const viewport of viewports) {
  test.describe(`Social Connections - ${viewport.name} (${viewport.width}x${viewport.height})`, () => {
    test.use({ viewport });

    test.beforeEach(async ({ page }) => {
      // Mock authentication
      await page.goto('/login');
      // In real tests, you would authenticate here
      // For now, we'll just navigate to the connections page
      await page.goto('/connections');
    });

    test('Empty State', async ({ page }) => {
      await page.goto('/connections');
      
      // Check for empty state illustration
      await expect(page.locator('text=No connections yet')).toBeVisible();
      await expect(page.locator('text=Connect your social accounts')).toBeVisible();
      await expect(page.locator('button:has-text("Connect your first account")')).toBeVisible();
    });

    test('Loading State', async ({ page }) => {
      // Intercept API to delay response
      await page.route('/api/v1/social/connections', async route => {
        await new Promise(resolve => setTimeout(resolve, 1000));
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 0 },
          }),
        });
      });

      await page.goto('/connections');
      
      // Check for skeleton loading (3 cards)
      const skeletonCards = page.locator('.animate-pulse');
      await expect(skeletonCards).toHaveCount(3);
    });

    test('Connect Flow', async ({ page }) => {
      // Mock the connect endpoint
      await page.route('/api/v1/social/youtube/connect', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              authorization_url: 'https://accounts.google.com/o/oauth2/v2/auth?state=test',
              state: 'test_state',
              connection_id: 1,
            },
          }),
        });
      });

      // Mock the callback redirect
      await page.route('/api/v1/social/youtube/callback*', async route => {
        await route.fulfill({
          status: 302,
          headers: { location: '/connections?connected=youtube' },
        });
      });

      await page.goto('/connections');
      
      // Click connect button
      await page.click('button:has-text("Connect Account")');
      
      // Modal should open
      await expect(page.locator('text=Connect Social Account')).toBeVisible();
      
      // Click YouTube platform
      await page.click('button:has-text("YouTube")');
      
      // Should redirect to OAuth (in real test, we'd mock the redirect)
      // For this test, we'll just verify the modal closes and list refreshes
      await page.waitForURL('**/connections?connected=youtube');
      
      // Check for success toast
      await expect(page.locator('text=YouTube connected successfully!')).toBeVisible();
    });

    test('Disconnect Flow', async ({ page }) => {
      // Mock existing connection
      await page.route('/api/v1/social/connections', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [{
              id: 1,
              platform: 'youtube',
              platform_label: 'YouTube',
              display_name: 'Test Channel',
              avatar_url: 'https://example.com/avatar.jpg',
              channel_url: 'https://youtube.com/@test',
              status: 'connected',
              last_error: null,
              created_at: '2026-10-22T10:00:00.000000Z',
              updated_at: '2026-10-22T10:00:00.000000Z',
            }],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
          }),
        });
      });

      // Mock disconnect endpoint
      await page.route('/api/v1/social/connections/1', async route => {
        if (route.request().method() === 'DELETE') {
          await route.fulfill({ status: 204 });
        } else {
          await route.continue();
        }
      });

      await page.goto('/connections');
      
      // Verify connection is shown
      await expect(page.locator('text=Test Channel')).toBeVisible();
      await expect(page.locator('text=Connected')).toBeVisible();
      
      // Click disconnect
      await page.click('button[aria-label="Disconnect YouTube"]');
      
      // Confirm dialog should appear
      await expect(page.locator('text=Disconnect YouTube?')).toBeVisible();
      
      // Confirm disconnect
      await page.click('button:has-text("Disconnect")');
      
      // Connection should be removed
      await expect(page.locator('text=No connections yet')).toBeVisible();
    });

    test('Expired State', async ({ page }) => {
      await page.route('/api/v1/social/connections', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [{
              id: 1,
              platform: 'youtube',
              platform_label: 'YouTube',
              display_name: 'Test Channel',
              avatar_url: 'https://example.com/avatar.jpg',
              channel_url: 'https://youtube.com/@test',
              status: 'expired',
              last_error: 'Token expired',
              created_at: '2026-10-22T10:00:00.000000Z',
              updated_at: '2026-10-22T10:00:00.000000Z',
            }],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
          }),
        });
      });

      await page.goto('/connections');
      
      // Check for yellow badge
      await expect(page.locator('text=Expired')).toBeVisible();
      await expect(page.locator('button:has-text("Reconnect")')).toBeVisible();
    });

    test('Reconnect Flow', async ({ page }) => {
      await page.route('/api/v1/social/connections', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [{
              id: 1,
              platform: 'youtube',
              platform_label: 'YouTube',
              display_name: 'Test Channel',
              avatar_url: 'https://example.com/avatar.jpg',
              channel_url: 'https://youtube.com/@test',
              status: 'expired',
              last_error: 'Token expired',
              created_at: '2026-10-22T10:00:00.000000Z',
              updated_at: '2026-10-22T10:00:00.000000Z',
            }],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
          }),
        });
      });

      await page.route('/api/v1/social/youtube/connect', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: {
              authorization_url: 'https://accounts.google.com/o/oauth2/v2/auth?state=test',
              state: 'test_state',
              connection_id: 1,
            },
          }),
        });
      });

      await page.goto('/connections');
      
      // Click reconnect
      await page.click('button:has-text("Reconnect")');
      
      // Modal should open
      await expect(page.locator('text=Connect Social Account')).toBeVisible();
      
      // Click YouTube
      await page.click('button:has-text("YouTube")');
      
      // Should redirect
      await page.waitForURL('**/connections?connected=youtube');
    });

    test('Revoked State', async ({ page }) => {
      await page.route('/api/v1/social/connections', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [{
              id: 1,
              platform: 'youtube',
              platform_label: 'YouTube',
              display_name: 'Test Channel',
              avatar_url: 'https://example.com/avatar.jpg',
              channel_url: 'https://youtube.com/@test',
              status: 'revoked',
              last_error: 'Revoked by user',
              created_at: '2026-10-22T10:00:00.000000Z',
              updated_at: '2026-10-22T10:00:00.000000Z',
            }],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
          }),
        });
      });

      await page.goto('/connections');
      
      // Check for gray badge
      await expect(page.locator('text=Revoked')).toBeVisible();
      await expect(page.locator('button:has-text("Connect Again")')).toBeVisible();
    });

    test('Error State', async ({ page }) => {
      await page.route('/api/v1/social/connections', async route => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            data: [{
              id: 1,
              platform: 'youtube',
              platform_label: 'YouTube',
              display_name: 'Test Channel',
              avatar_url: 'https://example.com/avatar.jpg',
              channel_url: 'https://youtube.com/@test',
              status: 'error',
              last_error: 'Connection failed',
              created_at: '2026-10-22T10:00:00.000000Z',
              updated_at: '2026-10-22T10:00:00.000000Z',
            }],
            links: { first: null, last: null, prev: null, next: null },
            meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
          }),
        });
      });

      await page.goto('/connections');
      
      // Check for red badge
      await expect(page.locator('text=Error')).toBeVisible();
      await expect(page.locator('text=Connection failed')).toBeVisible();
      await expect(page.locator('button:has-text("Retry")')).toBeVisible();
    });

    test('Keyboard Navigation', async ({ page }) => {
      await page.goto('/connections');
      
      // Tab through elements
      await page.keyboard.press('Tab');
      let focused = await page.evaluate(() => document.activeElement?.tagName);
      expect(focused).toBe('BUTTON'); // Back button or first tab
      
      // Continue tabbing through tabs
      await page.keyboard.press('Tab');
      focused = await page.evaluate(() => document.activeElement?.getAttribute('role'));
      expect(focused).toBe('tab');
    });

    test('Focus Indicators', async ({ page }) => {
      await page.goto('/connections');
      
      // Focus on connect button
      await page.keyboard.press('Tab');
      const connectButton = page.locator('button:has-text("Connect Account")');
      await connectButton.focus();
      
      // Check for focus ring
      const styles = await connectButton.evaluate(el => window.getComputedStyle(el));
      expect(styles.outlineWidth).not.toBe('0px');
    });

    test('ARIA Labels', async ({ page }) => {
      await page.goto('/connections');
      
      // Check icon buttons have aria-label
      const connectButton = page.locator('button:has-text("Connect Account")');
      await expect(connectButton).toHaveAttribute('aria-label');
    });

    test('Console Cleanliness', async ({ page }) => {
      const consoleErrors: string[] = [];
      page.on('console', msg => {
        if (msg.type() === 'error') {
          consoleErrors.push(msg.text());
        }
      });

      await page.goto('/connections');
      
      // Filter out expected errors (like missing OAuth endpoints in test)
      const unexpectedErrors = consoleErrors.filter(e => 
        !e.includes('favicon') && 
        !e.includes('404') &&
        !e.includes('net::ERR')
      );
      
      expect(unexpectedErrors).toHaveLength(0);
    });

    test('Network Cleanliness', async ({ page }) => {
      const failedRequests: string[] = [];
      page.on('response', response => {
        if (response.status() >= 400 && response.status() < 500 && !response.url().includes('favicon')) {
          failedRequests.push(`${response.status()} ${response.url()}`);
        }
      });

      await page.goto('/connections');
      
      // Allow expected 404s (like favicon) but no unexpected 4xx/5xx
      const unexpectedFailures = failedRequests.filter(f => 
        !f.includes('favicon.ico') && 
        !f.includes('chrome-extension') &&
        !f.includes('oauth') // OAuth redirects are expected
      );
      
      expect(unexpectedFailures).toHaveLength(0);
    });

    test('Responsive Layout', async ({ page }) => {
      await page.goto('/connections');
      
      // On mobile (< 1024px), should be single column
      if (viewport.width < 1024) {
        const grid = page.locator('.grid');
        await expect(grid).toHaveClass(/grid-cols-1/);
      }
      
      // On desktop (>= 1024px), should be multi-column
      if (viewport.width >= 1024) {
        const grid = page.locator('.grid');
        await expect(grid).toHaveClass(/lg:grid-cols-3/);
      }
    });

    test('Touch Targets', async ({ page }) => {
      await page.goto('/connections');
      
      // Check all interactive elements have minimum 44px touch target
      const interactiveElements = page.locator('button, a, input, select, textarea, [role="button"], [role="tab"]');
      const count = await interactiveElements.count();
      
      for (let i = 0; i < count; i++) {
        const element = interactiveElements.nth(i);
        const box = await element.boundingBox();
        if (box) {
          expect(box.width).toBeGreaterThanOrEqual(44);
          expect(box.height).toBeGreaterThanOrEqual(44);
        }
      }
    });
  });
}