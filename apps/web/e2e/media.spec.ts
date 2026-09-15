import { test, expect } from '@playwright/test';

// Generate a minimal valid MP4 file (1-second black video)
const MINIMAL_MP4 = Buffer.from([
  0x00, 0x00, 0x00, 0x1C, 0x66, 0x74, 0x79, 0x70, 0x69, 0x73, 0x6F, 0x6D, 0x00, 0x00, 0x02, 0x00,
  0x69, 0x73, 0x6F, 0x6D, 0x69, 0x73, 0x6F, 0x32, 0x6D, 0x70, 0x34, 0x31,
]);

test.describe('Media Upload and Management', () => {
  test('upload video → appears in list → delete removes it', async ({ page }) => {
    // 1. Register and login
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // Register a new user
    const email = `test-${Date.now()}@example.com`;
    await page.fill('input[name="name"]', 'Test User');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', 'password123');
    await page.fill('input[name="password_confirmation"]', 'password123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/');

    // 2. Create a project
    await page.fill('input[aria-label="Project Name"]', 'Media Test Project');
    await page.click('button[aria-label="Create Project"]');
    await page.waitForSelector('text=Media Test Project');

    // 3. Navigate to project (select it)
    await page.click('button[aria-label="Open Media Test Project"]');
    await page.waitForSelector('text=Media Test Project');

    // 4. Upload a valid MP4 file
    const fileInput = page.locator('input[type="file"]');
    await fileInput.setInputFiles({
      name: 'test-video.mp4',
      mimeType: 'video/mp4',
      buffer: MINIMAL_MP4,
    });
    await page.click('button:text("Upload")');

    // 5. Assert the media list shows the uploaded file
    await page.waitForSelector('text=test-video.mp4');
    await expect(page.locator('text=test-video.mp4')).toBeVisible();

    // 6. Delete the media asset
    await page.click('button[aria-label="Delete test-video.mp4"]');
    await page.click('button[aria-label="Confirm delete test-video.mp4"]');

    // 7. Assert the media list is empty
    await page.waitForSelector('text=No media assets yet');
    await expect(page.locator('text=No media assets yet')).toBeVisible();

    // 8. Go back to projects
    await page.click('button[aria-label="Back to Projects"]');
    await page.waitForSelector('text=Media Test Project');

    // 9. Delete the project
    await page.click('button[aria-label="Delete Media Test Project"]');
    await page.click('button[aria-label="Confirm delete Media Test Project"]');

    // 10. Assert the project is gone
    await page.waitForSelector('text=No projects yet');
    await expect(page.locator('text=No projects yet')).toBeVisible();
  });

  test('rejects non-video file upload', async ({ page }) => {
    // Register and login
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    const email = `test-${Date.now()}@example.com`;
    await page.fill('input[name="name"]', 'Test User');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', 'password123');
    await page.fill('input[name="password_confirmation"]', 'password123');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/');

    // Create a project
    await page.fill('input[aria-label="Project Name"]', 'Test Project');
    await page.click('button[aria-label="Create Project"]');
    await page.waitForSelector('text=Test Project');

    // Navigate to project
    await page.click('button[aria-label="Open Test Project"]');
    await page.waitForSelector('text=Test Project');

    // Try to upload a text file
    const fileInput = page.locator('input[type="file"]');
    await fileInput.setInputFiles({
      name: 'test.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('This is not a video'),
    });
    await page.click('button:text("Upload")');

    // Assert error message is displayed
    await page.waitForSelector('[role="alert"]');
    await expect(page.locator('[role="alert"]')).toBeVisible();

    // Assert no media item appears in the list
    await expect(page.locator('text=test.txt')).not.toBeVisible();
  });
});
