import { test, expect } from '@playwright/test';
import type { Request } from '@playwright/test';
import type { MediaAsset } from '../src/features/media/types';

// Generate a minimal valid MP4 file (1-second black video)
const MINIMAL_MP4 = Buffer.from([
  0x00, 0x00, 0x00, 0x1C, 0x66, 0x74, 0x79, 0x70, 0x69, 0x73, 0x6F, 0x6D, 0x00, 0x00, 0x02, 0x00,
  0x69, 0x73, 0x6F, 0x6D, 0x69, 0x73, 0x6F, 0x32, 0x6D, 0x70, 0x34, 0x31,
]);

function uniqueEmail() {
  return `e2e-media-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`;
}

function signal() {
  let resolve!: () => void;
  const promise = new Promise<void>(done => { resolve = done; });
  return { promise, resolve };
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

    // Create the real project before intercepting only its Media requests.
    await page.getByLabel('Project Name').fill('Media Test Project');
    const created = page.waitForResponse(response =>
      new URL(response.url()).pathname === '/api/v1/projects' && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Create Project' }).click();
    const projectResponse = await created;
    expect(projectResponse.status()).toBe(201);
    const { data: project } = await projectResponse.json();
    await expect(page.getByText('Media Test Project')).toBeVisible();

    const listPath = `/api/v1/projects/${project.id}/media`;
    const uploadPath = `${listPath}/upload`;
    const asset: MediaAsset = {
      id: 1,
      project_id: project.id,
      original_name: 'test-video.mp4',
      mime_type: 'video/mp4',
      size_bytes: MINIMAL_MP4.length,
      status: 'stored',
      created_at: '2026-09-16T12:00:00.000000Z',
      updated_at: '2026-09-16T12:00:00.000000Z',
    };
    const deletePath = `/api/v1/media/${asset.id}`;
    const initialGet = signal();
    const releaseGets = signal();
    const heldGets: Request[] = [];

    // Every route is ready before Open can mount the Media effect.
    await page.route('**/sanctum/csrf-cookie', async (route) => {
      if (route.request().method() === 'GET') await route.fulfill({ status: 204 });
      else await route.continue();
    });
    await page.route(`**${uploadPath}`, async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({ status: 201, json: { data: asset } });
      } else await route.continue();
    });
    await page.route(`**${listPath}`, async (route) => {
      if (route.request().method() !== 'GET') return route.continue();
      // All initial requests, including StrictMode replays, share this barrier.
      heldGets.push(route.request());
      initialGet.resolve();
      await releaseGets.promise;
      await route.fulfill({ status: 200, json: { data: [] } });
    });
    await page.route(`**${deletePath}`, async (route) => {
      if (route.request().method() === 'DELETE') {
        await route.fulfill({ status: 204 });
      } else await route.continue();
    });

    try {
      await page.getByRole('button', { name: 'Open Media Test Project' }).click();
      await expect(page.getByRole('button', { name: 'Back to projects' })).toBeVisible();
      await initialGet.promise;
      await expect(page.getByRole('status')).toHaveText('Loading media assets...');

      await page.getByLabel('Upload Video').setInputFiles({
        name: asset.original_name,
        mimeType: asset.mime_type,
        buffer: MINIMAL_MP4,
      });
      const uploaded = page.waitForResponse(response =>
        new URL(response.url()).pathname === uploadPath && response.request().method() === 'POST');
      await page.getByRole('button', { name: 'Upload', exact: true }).click();
      const uploadResponse = await uploaded;
      expect(uploadResponse.status()).toBe(201);
      expect(await uploadResponse.json()).toEqual({ data: asset });

      // A chooser filename is not proof that the real list rendered the upload.
      const item = page.getByRole('listitem', { name: `Media: ${asset.original_name}`, exact: true });
      await expect(item).toBeVisible();
      await expect(item).toHaveCount(1);
      await expect(item).toContainText('stored');
      expect(heldGets.length).toBeGreaterThan(0);

      releaseGets.resolve();
      for (const request of heldGets) {
        const response = await request.response();
        expect(response).not.toBeNull();
        expect(response!.status()).toBe(200);
        expect(await response!.finished()).toBeNull();
        expect(await response!.json()).toEqual({ data: [] });
      }
      await expect(item).toBeVisible();
      await expect(item).toHaveCount(1);

      await page.getByRole('button', { name: 'Delete test-video.mp4' }).click();
      await page.getByRole('button', { name: 'Cancel delete test-video.mp4' }).click();
      await expect(item).toBeVisible();
      await page.getByRole('button', { name: 'Delete test-video.mp4' }).click();
      const deleted = page.waitForResponse(response =>
        new URL(response.url()).pathname === deletePath && response.request().method() === 'DELETE');
      await page.getByRole('button', { name: 'Confirm delete test-video.mp4' }).click();
      const deleteResponse = await deleted;
      expect(deleteResponse.status()).toBe(204);
      await expect(item).toHaveCount(0);
      await expect(page.getByText('No media assets yet. Upload a video to get started.')).toBeVisible();

      // Confirm Media removal before leaving, then preserve real project cleanup.
      await page.getByRole('button', { name: 'Back to projects' }).click();
      await expect(page.getByText('Media Test Project')).toBeVisible();
      await page.getByRole('button', { name: 'Delete Media Test Project' }).click();
      await page.getByRole('button', { name: 'Confirm delete Media Test Project' }).click();
      await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 });
    } finally {
      // Drain held route handlers even when a pre-release assertion fails in RED.
      releaseGets.resolve();
      await page.unrouteAll({ behavior: 'wait' });
    }
  });

  test('rejects non-video file upload', async ({ page }) => {
    // 1. Register and login
    const email = uniqueEmail();
    await registerUser(page, 'Test User', email);

    // 2. Create a project
    await page.getByLabel('Project Name').fill('Test Project');
    const created = page.waitForResponse(response =>
      new URL(response.url()).pathname === '/api/v1/projects' && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Create Project' }).click();
    const projectResponse = await created;
    expect(projectResponse.status()).toBe(201);
    const { data: project } = await projectResponse.json();
    await expect(page.getByText('Test Project')).toBeVisible();

    // Prepare the list before entry; upload/CSRF/422 validation remain real.
    const listPath = `/api/v1/projects/${project.id}/media`;
    await page.route(`**${listPath}`, async route => {
      if (route.request().method() === 'GET') await route.fulfill({ status: 200, json: { data: [] } });
      else await route.continue();
    });

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
    const rejected = page.waitForResponse(response =>
      new URL(response.url()).pathname === `${listPath}/upload` && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Upload', exact: true }).click();
    const rejection = await rejected;
    expect(rejection.status()).toBe(422);
    expect((await rejection.json()).errors.file).toBeDefined();

    // 5. Assert error message is displayed
    await expect(page.locator('[role="alert"]')).toBeVisible({ timeout: 10000 });
    await expect(page.getByRole('alert')).toHaveText('Validation failed. Please check the file type and size.');

    // 6. Assert no media item appears in the list
    await expect(page.getByText('test.txt')).not.toBeVisible();
    await expect(page.getByRole('listitem', { name: /^Media:/ })).toHaveCount(0);
    await expect(page.getByText('No media assets yet. Upload a video to get started.')).toBeVisible();
  });
});
