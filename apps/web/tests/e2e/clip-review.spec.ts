import { test, expect } from '@playwright/test';

test.describe('Clip Review E2E', () => {
  test.beforeEach(async ({ page }) => {
    // Login and navigate to review page
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/projects');
  });

  test('TC-E2E-01: Happy path - approve clip', async ({ page }) => {
    // Navigate to project review page
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    // Verify review list shows clip with Pending badge
    await expect(page.locator('text=Pending')).toBeVisible();

    // Click Review button
    await page.click('button:has-text("Review")');
    await page.waitForLoadState('networkidle');

    // Verify VideoPlayer is visible
    await expect(page.locator('[role="region"][aria-label="Video player"]')).toBeVisible();

    // Play video with Space
    await page.keyboard.press('Space');
    await page.waitForTimeout(500);

    // Click Approve
    await page.click('button:has-text("Approve")');
    await page.waitForSelector('[role="alertdialog"]');

    // Confirm approval
    await page.click('button:has-text("Confirm Approve")');
    await page.waitForLoadState('networkidle');

    // Verify status badge changes to Approved (green)
    await expect(page.locator('text=Approved')).toBeVisible();
    await expect(page.locator('.bg-green-100, .bg-green-900')).toBeVisible();

    // Reload page - status should persist
    await page.reload();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('text=Approved')).toBeVisible();
  });

  test('TC-E2E-02: Reject workflow', async ({ page }) => {
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    await page.click('button:has-text("Review")');
    await page.waitForLoadState('networkidle');

    await page.click('button:has-text("Reject")');
    await page.waitForSelector('[role="alertdialog"]');

    // Confirm rejection
    await page.click('button:has-text("Confirm Reject")');
    await page.waitForLoadState('networkidle');

    // Feedback form should appear
    await expect(page.locator('label:has-text("Feedback")')).toBeVisible();

    // Enter feedback (>= 10 chars)
    await page.fill('textarea[name="feedback"]', 'Caption timing is off at 0:15');

    // Submit feedback
    await page.click('button:has-text("Submit Feedback")');
    await page.waitForLoadState('networkidle');

    // Verify status badge Rejected (red)
    await expect(page.locator('text=Rejected')).toBeVisible();
    await expect(page.locator('.bg-red-100, .bg-red-900')).toBeVisible();

    // Reload - persists
    await page.reload();
    await expect(page.locator('text=Rejected')).toBeVisible();
  });

  test('TC-E2E-03: Needs Changes workflow', async ({ page }) => {
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    await page.click('button:has-text("Review")');
    await page.waitForLoadState('networkidle');

    await page.click('button:has-text("Needs Changes")');
    await page.waitForSelector('[role="alertdialog"]');

    await page.click('button:has-text("Confirm Needs Changes")');
    await page.waitForLoadState('networkidle');

    // Feedback form appears
    await expect(page.locator('label:has-text("Feedback")')).toBeVisible();

    await page.fill('textarea[name="feedback"]', 'Please adjust the transition timing');
    await page.click('button:has-text("Submit Feedback")');
    await page.waitForLoadState('networkidle');

    // Verify status badge Needs Changes (orange)
    await expect(page.locator('text=Needs Changes')).toBeVisible();
    await expect(page.locator('.bg-orange-100, .bg-orange-900')).toBeVisible();
  });

  test('TC-E2E-04: Keyboard navigation', async ({ page }) => {
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    await page.click('button:has-text("Review")');
    await page.waitForLoadState('networkidle');

    // Space - play/pause
    await page.keyboard.press('Space');
    await page.waitForTimeout(200);

    // ArrowRight - seek forward
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(100);

    // ArrowLeft - seek backward
    await page.keyboard.press('ArrowLeft');
    await page.waitForTimeout(100);

    // ArrowUp - volume up
    await page.keyboard.press('ArrowUp');
    await page.waitForTimeout(100);

    // ArrowDown - volume down
    await page.keyboard.press('ArrowDown');
    await page.waitForTimeout(100);

    // M - mute toggle
    await page.keyboard.press('M');
    await page.waitForTimeout(100);

    // F - fullscreen (may not work in headless)
    await page.keyboard.press('F');
    await page.waitForTimeout(100);

    // N - next clip (if multiple)
    await page.keyboard.press('N');
    await page.waitForTimeout(100);

    // P - previous clip
    await page.keyboard.press('P');
    await page.waitForTimeout(100);
  });

  test('TC-E2E-05: Responsive - mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    // Single column layout
    const grid = page.locator('[role="list"]');
    await expect(grid).toHaveClass(/grid-cols-1/);

    // Video player full width
    await expect(page.locator('[role="region"][aria-label="Video player"]')).toBeVisible();

    // Touch targets >= 44px
    const buttons = page.locator('button');
    for (const button of await buttons.all()) {
      const box = await button.boundingBox();
      if (box) {
        expect(box.height).toBeGreaterThanOrEqual(44);
      }
    }
  });

  test('TC-E2E-05: Responsive - tablet viewport', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    // Two column layout for list
    const grid = page.locator('[role="list"]');
    await expect(grid).toHaveClass(/sm:grid-cols-2/);
  });

  test('TC-E2E-05: Responsive - desktop viewport', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    // Side-by-side layout
    const grid = page.locator('[role="list"]');
    await expect(grid).toHaveClass(/lg:grid-cols-3/);
  });

  test('TC-E2E-06: Signed URL security', async ({ page }) => {
    await page.goto('/projects/1/review');
    await page.waitForLoadState('networkidle');

    await page.click('button:has-text("Review")');
    await page.waitForLoadState('networkidle');

    // Get video URL from API response or video element
    const video = page.locator('[role="region"][aria-label="Video player"] video');
    const videoUrl = await video.getAttribute('src');

    // Open in new incognito context (simulated by new page without auth)
    const unauthenticatedPage = await page.context().newPage();
    await unauthenticatedPage.goto(videoUrl || '');
    await expect(unauthenticatedPage).toHaveURL(/.*403|.*404|.*login/);
    await unauthenticatedPage.close();
  });
});