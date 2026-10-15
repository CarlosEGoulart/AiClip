import { test, expect, type Page, type Response } from '@playwright/test';

/*
 * Clip Review E2E — test-plan §6 (plan Phase 7.4).
 *
 * Reachable UI (verified topology): App -> AuthenticatedShell -> project
 * list -> `Open {project}` -> ProjectMediaSection -> "Review Clips" tab ->
 * embedded ReviewPage. There is no /projects/{id}/review route, and the e2e
 * environment provisions no MinIO and no render worker, so no ClipReview row
 * can exist there: the reachable state is the review section's empty state
 * (spec "Known Limitations and Coverage Boundary").
 *
 * Data setup follows the established pattern in e2e/media.spec.ts: a unique
 * user is registered through the UI, the project is created through the UI,
 * and the project id is captured from the real 201 response. No API
 * interception, no seed users, no route navigation: every request, including
 * GET /api/v1/projects/{id}/clip-reviews, runs against the real Laravel
 * server.
 *
 * TC-E2E-03 (responsive viewports) relies on the three viewport projects
 * configured in playwright.config.ts (mobile 390x844, tablet 768x1024,
 * desktop 1440x900); viewport sizes are never set manually here. Per its
 * "During TC-E2E-01 through TC-E2E-03" definition, the TC-E2E-04 console and
 * network cleanliness checks are enforced inside each of the three tests
 * below, so they run in every viewport project.
 */

interface Cleanliness {
  consoleProblems: string[];
  pageErrors: string[];
  unexpectedHttp: string[];
}

interface FocusTarget {
  key: string;
  selector: string;
}

const FOCUS_TARGETS: FocusTarget[] = [
  { key: 'back-to-projects', selector: 'button[aria-label="Back to projects"]' },
  { key: 'media-tab', selector: '#media-tab' },
  { key: 'review-tab', selector: '#review-tab' },
  { key: 'back-to-project', selector: 'button[aria-label="Back to project"]' },
  { key: 'logout', selector: '.logout-button' },
];

function uniqueEmail() {
  return `e2e-clip-review-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@test.example.com`;
}

function isGet(pathname: string) {
  return (response: Response) =>
    new URL(response.url()).pathname === pathname && response.request().method() === 'GET';
}

/*
 * TC-E2E-04 collectors: browser console errors/warnings, uncaught exceptions,
 * and any 4xx/5xx response. The anonymous GET /api/v1/auth/me session probe
 * (401 while signed out) is the single expected 4xx of the flow.
 */
function trackCleanliness(page: Page): Cleanliness {
  const cleanliness: Cleanliness = {
    consoleProblems: [],
    pageErrors: [],
    unexpectedHttp: [],
  };

  page.on('console', (message) => {
    if (message.type() === 'error' || message.type() === 'warning') {
      cleanliness.consoleProblems.push(`${message.type()}: ${message.text()}`);
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

  return cleanliness;
}

async function expectClean(page: Page, cleanliness: Cleanliness): Promise<void> {
  expect(cleanliness.consoleProblems, 'browser console problems').toEqual([]);
  expect(cleanliness.pageErrors, 'uncaught page errors').toEqual([]);
  expect(cleanliness.unexpectedHttp, 'unexpected 4xx/5xx responses').toEqual([]);

  // No tokens or credentials persisted in browser storage (the empty review
  // flow never mounts the feedback draft form).
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
  const created = page.waitForResponse(
    (response) =>
      new URL(response.url()).pathname === '/api/v1/projects' &&
      response.request().method() === 'POST',
  );
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
  await expect(page.getByRole('tab', { name: 'Review Clips' })).toBeVisible();
}

async function openReviewTab(page: Page, projectId: number): Promise<Response> {
  const clipReviewsSeen = page.waitForResponse(
    isGet(`/api/v1/projects/${projectId}/clip-reviews`),
  );
  await page.getByRole('tab', { name: 'Review Clips' }).click();
  return clipReviewsSeen;
}

async function expectEmptyReviewSection(page: Page, projectName: string) {
  await expect(page.locator('.review-header')).toContainText(projectName);
  await expect(page.getByText('No clips')).toBeVisible();
  await expect(page.getByText('Select a clip to review')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Clips to Review' })).toBeVisible();
  await expect(page.getByRole('list', { name: 'Clip review list' })).toBeVisible();
  await expect(page.getByRole('listitem')).toHaveCount(0);
}

test.describe('Clip Review Section (embedded)', () => {
  test('TC-E2E-01 review section discoverability and empty state', async ({ page }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Clip Reviewer', email);

    const projectName = 'Clip Review Project';
    const projectId = await createProjectViaUi(page, projectName);

    await openProject(page, projectName);

    // Clicking the Review Clips tab issues the real CSRF handshake (204)
    // followed by the real clip-reviews request (200, {"data": []}).
    const csrfSeen = page.waitForResponse(isGet('/sanctum/csrf-cookie'));
    const clipReviewsResponse = await openReviewTab(page, projectId);
    const csrfResponse = await csrfSeen;
    expect(csrfResponse.status()).toBe(204);
    expect(clipReviewsResponse.status()).toBe(200);
    // The envelope contains only the data array: no storage_disk,
    // storage_key, internal IDs, or bucket names.
    expect(await clipReviewsResponse.json()).toEqual({ data: [] });

    await expectEmptyReviewSection(page, projectName);

    // No video player, review action buttons, or feedback form are rendered.
    await expect(page.getByLabelText('Clip video')).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Review actions' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Feedback form' })).toHaveCount(0);

    // Clean up: delete the project via the UI delete flow.
    await page.getByRole('button', { name: 'Back to project' }).click();
    await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible();
    await page.getByRole('button', { name: `Delete ${projectName}` }).click();
    await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click();
    await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 });

    await expectClean(page, cleanliness);
  });

  test('TC-E2E-02 empty section non-interactivity and navigation', async ({ page }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Clip Navigator', email);

    const projectName = 'Navigation Project';
    const projectId = await createProjectViaUi(page, projectName);

    await openProject(page, projectName);
    const firstResponse = await openReviewTab(page, projectId);
    expect(firstResponse.status()).toBe(200);
    expect(await firstResponse.json()).toEqual({ data: [] });
    await expectEmptyReviewSection(page, projectName);

    // No review action buttons exist in the DOM.
    await expect(page.getByRole('group', { name: 'Review actions' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toHaveCount(0);

    // Pressing the clip navigation keys changes nothing in the empty state:
    // no URL change (no clipReviewId parameter), no error, no state change.
    const urlBefore = page.url();
    await page.keyboard.press('n');
    await page.keyboard.press('N');
    await page.keyboard.press('p');
    await page.keyboard.press('P');
    expect(page.url()).toBe(urlBefore);
    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBeNull();
    await expectEmptyReviewSection(page, projectName);

    // Switch to the Media tab and back: the review section re-renders the
    // same empty state and the clip-reviews request succeeds again.
    await page.getByRole('tab', { name: 'Media' }).click();
    await expect(
      page.getByText('No media assets yet. Upload a video to get started.'),
    ).toBeVisible();
    const secondResponse = await openReviewTab(page, projectId);
    expect(secondResponse.status()).toBe(200);
    expect(await secondResponse.json()).toEqual({ data: [] });
    await expectEmptyReviewSection(page, projectName);

    // The review section's "Back to project" control returns to the
    // projects list.
    await page.getByRole('button', { name: 'Back to project' }).click();
    await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible();

    await expectClean(page, cleanliness);
  });

  test('TC-E2E-03 responsive viewports: real review section', async ({ page }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Clip Explorer', email);

    const projectName = 'Responsive Project';
    const projectId = await createProjectViaUi(page, projectName);

    await openProject(page, projectName);
    const clipReviewsResponse = await openReviewTab(page, projectId);
    expect(clipReviewsResponse.status()).toBe(200);
    expect(await clipReviewsResponse.json()).toEqual({ data: [] });
    await expectEmptyReviewSection(page, projectName);

    // No horizontal overflow at the configured viewport.
    const overflow = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
    }));
    expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.innerWidth);

    // Header, sidebar, and empty-state text are visible and not clipped by
    // the viewport.
    const clippedElements = await page.evaluate(() => {
      const viewportWidth = window.innerWidth;
      const elements = [
        document.querySelector('.review-header'),
        document.querySelector('.review-sidebar'),
        document.querySelector('.review-empty'),
      ];
      return elements.filter((element) => {
        if (!element) return true;
        const box = element.getBoundingClientRect();
        return box.left < -0.5 || box.right > viewportWidth + 0.5;
      }).length;
    });
    expect(clippedElements, 'elements clipped by the viewport').toBe(0);

    // Interactive controls (tabs, back controls, logout) are touch-target
    // sized (>= 44px) at every configured viewport.
    const touchTargets = await page.evaluate(() => {
      const height = (selector: string) =>
        document.querySelector(selector)?.getBoundingClientRect().height ?? 0;
      return {
        'back-to-projects': height('button[aria-label="Back to projects"]'),
        'media-tab': height('#media-tab'),
        'review-tab': height('#review-tab'),
        'back-to-project': height('button[aria-label="Back to project"]'),
        logout: height('.logout-button'),
      };
    });
    for (const [control, height] of Object.entries(touchTargets)) {
      expect(height, `${control} touch-target height`).toBeGreaterThanOrEqual(44);
    }

    // The layout reflows without overlap: one column below 1024px, two
    // columns (player + sidebar) from 1024px up.
    const gridColumns = await page.evaluate(
      () =>
        getComputedStyle(document.querySelector('.review-layout')).gridTemplateColumns
          .split(' ')
          .filter(Boolean).length,
    );
    const viewportWidth = page.viewportSize()?.width ?? 0;
    expect(gridColumns, 'review layout grid column count').toBe(viewportWidth < 1024 ? 1 : 2);

    // Keyboard navigation reaches every named control with a visible focus
    // indicator. The controls before the review tab are reached backwards.
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
          visible: parseFloat(style.outlineWidth) > 0 && style.outlineStyle !== 'none',
        };
      }, FOCUS_TARGETS);

    for (let i = 0; i < 10 && focusVisible['back-to-projects'] === undefined; i++) {
      await page.keyboard.press('Shift+Tab');
      const hit = await recordFocusedTarget();
      if (hit) focusVisible[hit.key] = hit.visible;
    }
    for (let i = 0; i < 10 && focusVisible.logout === undefined; i++) {
      await page.keyboard.press('Tab');
      const hit = await recordFocusedTarget();
      if (hit) focusVisible[hit.key] = hit.visible;
    }

    expect(Object.keys(focusVisible), 'reached focus targets').toHaveLength(FOCUS_TARGETS.length);
    for (const target of FOCUS_TARGETS) {
      expect(focusVisible[target.key], `${target.key} visible focus indicator`).toBe(true);
    }

    await expectClean(page, cleanliness);
  });
});
