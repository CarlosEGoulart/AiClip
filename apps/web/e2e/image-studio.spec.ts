import { test, expect, type Page, type Response } from '@playwright/test';

/*
 * Image Studio E2E — test-plan §7 (plan Step 6).
 *
 * Reachable UI (verified topology): App -> AuthenticatedShell -> project
 * list -> `Open {project}` -> ProjectMediaSection -> "Image Studio" tab ->
 * ImageStudio. There is no /images browser route and no client-side
 * router. Data setup follows e2e/media.spec.ts: a unique user is
 * registered through the UI, the project is created through the UI, and
 * the project id is captured from the real 201 response. No API
 * interception and no test-seeding route: the generate endpoint itself
 * creates the real fixture rows through the real API.
 *
 * Environment: the Playwright webServer runs Laravel with
 * QUEUE_CONNECTION=sync (Issue #110 requirement), so generation
 * completes during the POST and the first gallery refetch observes the
 * terminal state. No MinIO and no Python worker are involved: the bound
 * provider is the deterministic pure-PHP fake and the gallery fetches no
 * image bytes (no byte-delivery URL exists in this slice).
 *
 * TC-E2E-01 and TC-E2E-02 run in every configured viewport project
 * (mobile 390x844, tablet 768x1024, desktop 1440x900); viewport sizes
 * are never set manually. TC-E2E-03 (console/network/API cleanliness,
 * no secrets in browser storage, no storage/bucket requests) is enforced
 * inside both tests.
 */

interface Cleanliness {
  consoleProblems: string[];
  pageErrors: string[];
  unexpectedHttp: string[];
  storageRequests: string[];
}

interface FocusTarget {
  key: string;
  selector: string;
}

const IMAGE_FIELD_WHITELIST = [
  'created_at',
  'height',
  'id',
  'mime_type',
  'parameters',
  'project_id',
  'prompt',
  'size_bytes',
  'status',
  'updated_at',
  'width',
].sort();

const IMAGE_STUDIO_FOCUS_TARGETS: FocusTarget[] = [
  { key: 'image-studio-tab', selector: '#image-studio-tab' },
  { key: 'prompt', selector: '#image-prompt' },
  { key: 'style-preset', selector: '#image-style-preset' },
  { key: 'dimensions', selector: '#image-dimensions' },
  { key: 'generate', selector: '.image-studio button[type="submit"]' },
];

function uniqueEmail() {
  return `e2e-image-studio-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`;
}

function matches(method: string, pathname: string) {
  return (response: Response) =>
    new URL(response.url()).pathname === pathname && response.request().method() === method;
}

/*
 * TC-E2E-03 collectors: browser console errors/warnings, uncaught
 * exceptions, any 4xx/5xx response (the anonymous GET /api/v1/auth/me 401
 * session probe is the single ratified exception), and any request to a
 * storage/bucket URL (no image byte delivery exists in this slice).
 */
function trackCleanliness(page: Page): Cleanliness {
  const cleanliness: Cleanliness = {
    consoleProblems: [],
    pageErrors: [],
    unexpectedHttp: [],
    storageRequests: [],
  };

  page.on('console', (message) => {
    if (message.type() !== 'error' && message.type() !== 'warning') return;

    // Chromium mirrors the whitelisted anonymous session probe (GET
    // /api/v1/auth/me -> 401) into the console as a browser-generated
    // network log line. That mirror is the same ratified event, not an
    // app defect; every other console error or warning remains fatal.
    const url = message.location().url;
    const isSessionProbeMirror =
      message.type() === 'error' &&
      message
        .text()
        .startsWith('Failed to load resource: the server responded with a status of 401') &&
      url.endsWith('/api/v1/auth/me');

    if (!isSessionProbeMirror) {
      cleanliness.consoleProblems.push(
        `${message.type()}: ${message.text()} (${message.location().url})`,
      );
    }
  });

  page.on('pageerror', (error) => {
    cleanliness.pageErrors.push(String(error));
  });

  page.on('response', (response) => {
    if (response.status() < 400) return;

    const request = response.request();
    const pathname = new URL(response.url()).pathname;
    const isAnonymousSessionProbe =
      pathname === '/api/v1/auth/me' && response.status() === 401 && request.method() === 'GET';

    if (!isAnonymousSessionProbe) {
      cleanliness.unexpectedHttp.push(`${response.status()} ${request.method()} ${pathname}`);
    }
  });

  page.on('request', (request) => {
    const url = request.url();
    if (/storage|amazonaws|bucket|:9000/.test(url)) {
      cleanliness.storageRequests.push(`${request.method()} ${url}`);
    }
  });

  return cleanliness;
}

async function expectClean(page: Page, cleanliness: Cleanliness): Promise<void> {
  expect(cleanliness.consoleProblems, 'browser console problems').toEqual([]);
  expect(cleanliness.pageErrors, 'uncaught page errors').toEqual([]);
  expect(cleanliness.unexpectedHttp, 'unexpected 4xx/5xx responses').toEqual([]);
  expect(cleanliness.storageRequests, 'storage/bucket requests').toEqual([]);

  // No tokens or credentials persisted in browser storage.
  const browserStorage = await page.evaluate(() => ({
    localStorageKeys: Object.keys(window.localStorage).length,
    sessionStorageKeys: Object.keys(window.sessionStorage).length,
  }));
  expect(browserStorage.localStorageKeys, 'localStorage entries').toBe(0);
  expect(browserStorage.sessionStorageKeys, 'sessionStorage entries').toBe(0);
}

async function waitForAuthReady(page: Page) {
  await page.goto('/');
  await expect(
    page
      .getByRole('heading', { name: 'Sign In' })
      .or(page.getByRole('heading', { name: 'Create Account' })),
  ).toBeVisible({ timeout: 15000 });
}

async function switchToRegister(page: Page) {
  await waitForAuthReady(page);
  const registerHeading = page.getByRole('heading', { name: 'Create Account' });
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
  await expect(page.getByText('Signed in as')).toBeVisible({ timeout: 15000 });
}

async function createProjectViaUi(page: Page, projectName: string): Promise<number> {
  await page.getByLabel('Project Name').fill(projectName);
  const created = page.waitForResponse(matches('POST', '/api/v1/projects'));
  await page.getByRole('button', { name: 'Create Project' }).click();
  const projectResponse = await created;
  expect(projectResponse.status()).toBe(201);
  const body = await projectResponse.json();
  expect(body.data).toBeDefined();
  await expect(page.getByText(projectName)).toBeVisible();
  return body.data.id as number;
}

async function openProject(page: Page, projectName: string) {
  await page.getByRole('button', { name: `Open ${projectName}` }).click();
  await expect(page.getByRole('button', { name: 'Back to projects' })).toBeVisible();
  await expect(page.getByRole('tab', { name: 'Image Studio' })).toBeVisible();
}

async function openImageStudioTab(page: Page): Promise<Response> {
  const imagesSeen = page.waitForResponse(matches('GET', '/api/v1/images'));
  await page.getByRole('tab', { name: 'Image Studio' }).click();
  const response = await imagesSeen;
  expect(response.status()).toBe(200);

  const body = await response.json();
  // Standard pagination envelope wrapped in the top-level data key.
  expect(Object.keys(body.data).sort()).toEqual(['data', 'links', 'meta']);
  expect(body.data.data).toEqual([]);
  expect(body.data.meta.total).toBe(0);

  // Fresh project: the gallery renders the empty state.
  await expect(page.getByText('No images yet')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Image Studio' })).toBeVisible();

  return response;
}

const PROMPT = 'A serene mountain lake at sunrise';

/*
 * The full real generate flow: prompt + presets -> POST /images/generate
 * (202 with a pending resource and the field whitelist) -> immediate
 * local pending entry -> refetch (200) -> Generated badge with dimensions
 * and a non-zero size.
 */
async function runGenerateFlow(page: Page) {
  await page.getByLabel('Prompt').fill(PROMPT);
  await page.getByLabel('Style preset').selectOption('cinematic');
  await page.getByLabel('Dimensions').selectOption('vertical');

  const generateSeen = page.waitForResponse(matches('POST', '/api/v1/images/generate'));
  const refetchSeen = page.waitForResponse(matches('GET', '/api/v1/images'));
  await page.getByRole('button', { name: 'Generate' }).click();

  // The 202 body is the enqueue-time pending contract: the sync queue
  // has already completed generation server-side, yet the response
  // reports the pending state (resource serialized from the created
  // row, no refresh after dispatch).
  const generateResponse = await generateSeen;
  expect(generateResponse.status()).toBe(202);
  const generateBody = await generateResponse.json();
  expect(generateBody.data.status).toBe('pending');
  expect(Object.keys(generateBody.data).sort()).toEqual(IMAGE_FIELD_WHITELIST);
  expect(JSON.stringify(generateBody)).not.toContain('storage_disk');
  expect(JSON.stringify(generateBody)).not.toContain('storage_key');
  expect(JSON.stringify(generateBody)).not.toContain('user_id');
  expect(JSON.stringify(generateBody)).not.toContain('bucket');

  // The 202 pending resource is merged into the gallery immediately as a
  // local pending entry. In the synchronous e2e queue the window between
  // that merge and the first refetch is a single request round trip, so
  // this observation is a best-effort snapshot: the deterministic
  // local-pending-entry behavior is covered by TC-FE-04, and the
  // terminal state is asserted deterministically below either way.
  const pendingBadge = page.getByText('Pending');
  if (await pendingBadge.isVisible()) {
    await expect(pendingBadge).toHaveClass(/bg-gray-100/);
  }

  // The first refetch returns 200 with the standard pagination envelope
  // and no storage internals anywhere in the payload.
  const refetchResponse = await refetchSeen;
  expect(refetchResponse.status()).toBe(200);
  const refetchBody = await refetchResponse.json();
  expect(Object.keys(refetchBody.data).sort()).toEqual(['data', 'links', 'meta']);
  expect(JSON.stringify(refetchBody)).not.toContain('storage_disk');
  expect(JSON.stringify(refetchBody)).not.toContain('storage_key');
  expect(JSON.stringify(refetchBody)).not.toContain('user_id');
  expect(JSON.stringify(refetchBody)).not.toContain('bucket');

  // The gallery converges on the terminal state: exactly one entry, the
  // green Generated badge, the requested dimensions, and a non-zero
  // formatted file size. The pending badge and its in-progress hint are
  // gone.
  await expect(page.getByText('Generated')).toBeVisible({ timeout: 10000 });
  await expect(page.getByText('Generated')).toHaveClass(/bg-green-100/);
  await expect(page.getByText('Pending')).toHaveCount(0);
  await expect(page.getByText('Generating…')).toHaveCount(0);
  await expect(page.getByText('1080 × 1920')).toBeVisible();
  await expect(page.getByText(/KB/)).toBeVisible();
  await expect(page.getByText(PROMPT)).toBeVisible();
  await expect(page.getByRole('listitem')).toHaveCount(1);
}

async function cleanupProject(page: Page, projectName: string) {
  await page.getByRole('button', { name: 'Back to projects' }).click();
  await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible();
  await page.getByRole('button', { name: `Delete ${projectName}` }).click();
  await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click();
  await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 });
}

test.describe('Image Studio (embedded)', () => {
  test('TC-E2E-01 generate-image flow: 202 pending, refetch to generated, whitelist', async ({
    page,
  }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Image Creator', email);

    const projectName = 'Image Studio Project';
    await createProjectViaUi(page, projectName);
    await openProject(page, projectName);
    await openImageStudioTab(page);

    await runGenerateFlow(page);

    await cleanupProject(page, projectName);
    await expectClean(page, cleanliness);
  });

  test('TC-E2E-02 responsive layout, touch targets, and focus at the configured viewport', async ({
    page,
  }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Image Explorer', email);

    const projectName = 'Responsive Studio Project';
    await createProjectViaUi(page, projectName);
    await openProject(page, projectName);
    await openImageStudioTab(page);

    await runGenerateFlow(page);

    // No horizontal overflow at the configured viewport.
    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }));
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);

    // Single column below 1024px with the form above the gallery; form
    // and gallery side-by-side from 1024px up.
    const layout = await page.evaluate(() => {
      const grid = document.querySelector('.image-studio-layout');
      if (!grid) return null;
      const [form, gallery] = Array.from(grid.children) as HTMLElement[];
      const formBox = form.getBoundingClientRect();
      const galleryBox = gallery.getBoundingClientRect();
      return {
        columns: getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length,
        formTop: formBox.top,
        galleryTop: galleryBox.top,
        formLeft: formBox.left,
        galleryLeft: galleryBox.left,
        formBottom: formBox.bottom,
        galleryBottom: galleryBox.bottom,
      };
    });
    expect(layout).not.toBeNull();
    const viewportWidth = page.viewportSize()?.width ?? 0;
    if (viewportWidth < 1024) {
      expect(layout?.columns, 'layout grid column count').toBe(1);
      expect(layout!.formTop, 'form above gallery in single column').toBeLessThan(
        layout!.galleryTop,
      );
    } else {
      expect(layout?.columns, 'layout grid column count').toBe(2);
      expect(layout!.formLeft, 'form left of gallery side-by-side').toBeLessThan(
        layout!.galleryLeft,
      );
    }

    // Header, form, and gallery are fully inside the viewport (no
    // clipping at any configured width).
    const clipped = await page.evaluate(() => {
      const viewportWidth = window.innerWidth;
      const elements = [
        document.querySelector('.image-studio header'),
        document.querySelector('#image-prompt'),
        document.querySelector('#image-style-preset'),
        document.querySelector('#image-dimensions'),
        document.querySelector('.image-studio button[type="submit"]'),
        document.querySelector('.image-studio [role="list"]'),
      ];
      return elements.filter((element) => {
        if (!element) return true;
        const box = element.getBoundingClientRect();
        return box.left < -0.5 || box.right > viewportWidth + 0.5;
      }).length;
    });
    expect(clipped, 'elements clipped by the viewport').toBe(0);

    // Interactive controls are touch-target sized (>= 44px): the studio
    // controls and the Image Studio tab.
    const touchTargets = await page.evaluate(() => {
      const height = (selector: string) =>
        document.querySelector(selector)?.getBoundingClientRect().height ?? 0;
      return {
        'image-studio-tab': height('#image-studio-tab'),
        prompt: height('#image-prompt'),
        'style-preset': height('#image-style-preset'),
        dimensions: height('#image-dimensions'),
        generate: height('.image-studio button[type="submit"]'),
        'back-to-projects': height('button[aria-label="Back to projects"]'),
      };
    });
    for (const [control, height] of Object.entries(touchTargets)) {
      expect(height, `${control} touch-target height`).toBeGreaterThanOrEqual(44);
    }

    // Keyboard navigation reaches the studio controls in logical order
    // with a visible focus indicator (outline or focus ring).
    await page.getByRole('tab', { name: 'Image Studio' }).click();
    const focusVisible: Record<string, boolean> = {};
    const recordFocusedTarget = () =>
      page.evaluate((targets: FocusTarget[]) => {
        const element = document.activeElement;
        if (!element) return null;
        const match = targets.find((target) => document.querySelector(target.selector) === element);
        if (!match) return null;
        const style = getComputedStyle(element);
        return {
          key: match.key,
          visible:
            (parseFloat(style.outlineWidth) > 0 && style.outlineStyle !== 'none') ||
            style.boxShadow !== 'none',
        };
      }, IMAGE_STUDIO_FOCUS_TARGETS);

    let hit = await recordFocusedTarget();
    if (hit) focusVisible[hit.key] = hit.visible;

    for (let i = 0; i < 4; i++) {
      await page.keyboard.press('Tab');
      hit = await recordFocusedTarget();
      if (hit) focusVisible[hit.key] = hit.visible;
    }

    expect(Object.keys(focusVisible), 'reached focus targets').toHaveLength(
      IMAGE_STUDIO_FOCUS_TARGETS.length,
    );
    for (const target of IMAGE_STUDIO_FOCUS_TARGETS) {
      expect(focusVisible[target.key], `${target.key} visible focus indicator`).toBe(true);
    }

    await cleanupProject(page, projectName);
    await expectClean(page, cleanliness);
  });
});
