import { test, expect } from '@playwright/test';

// Generate a minimal valid MP4 file (1-second black video)
const MINIMAL_MP4 = Buffer.from([
  0x00, 0x00, 0x00, 0x1C, 0x66, 0x74, 0x79, 0x70, 0x69, 0x73, 0x6F, 0x6D, 0x00, 0x00, 0x02, 0x00,
  0x69, 0x73, 0x6F, 0x6D, 0x69, 0x73, 0x6F, 0x32, 0x6D, 0x70, 0x34, 0x31,
]);

function uniqueEmail() {
  return `e2e-media-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`;
}

async function waitForAuthReady(page: import('@playwright/test').Page) {
  await page.goto('/');
  await expect(
    page.getByRole('heading', { name: 'Sign In' }).or(page.getByRole('heading', { name: 'Create Account' })),
  ).toBeVisible({ timeout: 15000 });
}

async function switchToRegister(page: import('@playwright/test').Page) {
  await waitForAuthReady(page);
  const registerHeading = page.getByRole('heading', { name: 'Create Account' });
  if (await registerHeading.isVisible().catch(() => false)) return;
  await page.getByRole('button', { name: 'Create one' }).click();
  await expect(registerHeading).toBeVisible();
}

async function registerUser(page: import('@playwright/test').Page, name: string, email: string) {
  await switchToRegister(page);
  await page.getByLabel('Name').fill(name);
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('password123');
  await page.getByLabel('Confirm Password', { exact: true }).fill('password123');
  await page.getByRole('button', { name: 'Create Account' }).click();
  await expect(page.getByText('Signed in as')).toBeVisible({ timeout: 15000 });
}

test.describe('Media Upload and Management', () => {
  test('upload video → appears in list → delete removes it', async ({ page }) => {
    // 1. Register and login
    const email = uniqueEmail();
    await registerUser(page, 'Test User', email);

    // 2. Create a project
    await page.getByLabel('Project Name').fill('Media Test Project');
    await page.getByRole('button', { name: 'Create Project' }).click();
    await expect(page.getByText('Media Test Project')).toBeVisible();

    // 3. Open the project media section
    await page.getByRole('button', { name: 'Open Media Test Project' }).click();
    await expect(page.getByRole('button', { name: 'Back to projects' })).toBeVisible();

    // 4. Upload a valid MP4 file
    const fileInput = page.locator('input[type="file"]');
    await fileInput.setInputFiles({
      name: 'test-video.mp4',
      mimeType: 'video/mp4',
      buffer: MINIMAL_MP4,
    });
    await page.getByRole('button', { name: 'Upload' }).click();

    // 5. Assert the media list shows the uploaded file
    await expect(page.getByText('test-video.mp4')).toBeVisible({ timeout: 15000 });

    // 6. Delete the media asset
    await page.getByRole('button', { name: 'Delete test-video.mp4' }).click();
    await page.getByRole('button', { name: 'Confirm delete test-video.mp4' }).click();

    // 7. Assert empty state
    await expect(page.getByText('No media assets yet')).toBeVisible({ timeout: 10000 });

    // 8. Go back to projects
    await page.getByRole('button', { name: 'Back to projects' }).click();
    await expect(page.getByText('Media Test Project')).toBeVisible();

    // 9. Delete the project
    await page.getByRole('button', { name: 'Delete Media Test Project' }).click();
    await page.getByRole('button', { name: 'Confirm delete Media Test Project' }).click();

    // 10. Assert the project is gone
    await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 });
  });

  test('rejects non-video file upload', async ({ page }) => {
    // 1. Register and login
    const email = uniqueEmail();
    await registerUser(page, 'Test User', email);

    // 2. Create a project
    await page.getByLabel('Project Name').fill('Test Project');
    await page.getByRole('button', { name: 'Create Project' }).click();
    await expect(page.getByText('Test Project')).toBeVisible();

    // 3. Open the project media section
    await page.getByRole('button', { name: 'Open Test Project' }).click();
    await expect(page.getByRole('button', { name: 'Back to projects' })).toBeVisible();

    // 4. Try to upload a text file
    const fileInput = page.locator('input[type="file"]');
    await fileInput.setInputFiles({
      name: 'test.txt',
      mimeType: 'text/plain',
      buffer: Buffer.from('This is not a video'),
    });
    await page.getByRole('button', { name: 'Upload' }).click();

    // 5. Assert error message is displayed
    await expect(page.locator('[role="alert"]')).toBeVisible({ timeout: 10000 });

    // 6. Assert no media item appears in the list
    await expect(page.getByText('test.txt')).not.toBeVisible();
  });
});
