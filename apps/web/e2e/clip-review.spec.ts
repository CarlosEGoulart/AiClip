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
    if (message.type() !== 'error' && message.type() !== 'warning') return;

    // Chromium mirrors the whitelisted anonymous session probe (GET
    // /api/v1/auth/me -> 401, the single expected 4xx of the flow, see the
    // response whitelist below) into the console as a browser-generated
    // network log line: "Failed to load resource: the server responded
    // with a status of 401". That mirror is the same ratified event, not
    // an app defect: the response collector below still fails the run if
    // this URL is anything but a GET 401, and every other console error
    // or warning remains fatal.
    const url = message.location().url;
    const isSessionProbeMirror =
      message.type() === 'error' &&
      message
        .text()
        .startsWith('Failed to load resource: the server responded with a status of 401') &&
      url.endsWith('/api/v1/auth/me');

    if (!isSessionProbeMirror) {
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
  // TC-E2E-01 step 4 (ratified): the sidebar heading "Clips to Review"
  // renders "with an empty list" — the list must RENDER with zero items.
  // A zero-item list renders as an attached but empty div with an empty
  // bounding box, so Playwright visibility (non-empty bounding box) does
  // not apply to this element; attachment plus zero listitems is the
  // rendered-empty-list contract.
  await expect(page.getByRole('list', { name: 'Clip review list' })).toBeAttached();
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
    // followed by the real clip-reviews request (200, standard pagination
    // envelope).
    const csrfSeen = page.waitForResponse(isGet('/sanctum/csrf-cookie'));
    const clipReviewsResponse = await openReviewTab(page, projectId);
    const csrfResponse = await csrfSeen;
    expect(csrfResponse.status()).toBe(204);
    expect(clipReviewsResponse.status()).toBe(200);
    // The envelope is the standard pagination shape only — data.data,
    // data.links, data.meta: no storage_disk, storage_key, internal IDs,
    // or bucket names.
    const body = await clipReviewsResponse.json();
    expect(Object.keys(body.data).sort()).toEqual(['data', 'links', 'meta']);
    expect(body.data.data).toEqual([]);
    expect(body.data.meta.total).toBe(0);
    expect(body.data.meta.last_page).toBe(1);
    expect(body.data.links.next).toBeNull();
    expect(body.data.links.prev).toBeNull();

    await expectEmptyReviewSection(page, projectName);

    // No video player, review action buttons, or feedback form are rendered.
    await expect(page.getByLabel('Clip video')).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Review actions' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Feedback form' })).toHaveCount(0);

    // Clean up: delete the project via the UI delete flow. exact: targets
    // the review section's "Back to project" control only — the shell's
    // "Back to projects" control coexists in the DOM, and Playwright
    // role-name matching is substring-based by default, so without exact
    // the locator resolves to both buttons (strict mode violation).
    await page.getByRole('button', { name: 'Back to project', exact: true }).click();
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
    const firstBody = await firstResponse.json();
    expect(Object.keys(firstBody.data).sort()).toEqual(['data', 'links', 'meta']);
    expect(firstBody.data.data).toEqual([]);
    expect(firstBody.data.meta.total).toBe(0);
    expect(firstBody.data.meta.last_page).toBe(1);
    expect(firstBody.data.links.next).toBeNull();
    expect(firstBody.data.links.prev).toBeNull();
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
    const secondBody = await secondResponse.json();
    expect(Object.keys(secondBody.data).sort()).toEqual(['data', 'links', 'meta']);
    expect(secondBody.data.data).toEqual([]);
    expect(secondBody.data.meta.total).toBe(0);
    expect(secondBody.data.meta.last_page).toBe(1);
    expect(secondBody.data.links.next).toBeNull();
    expect(secondBody.data.links.prev).toBeNull();
    await expectEmptyReviewSection(page, projectName);

    // The review section's "Back to project" control returns to the
    // projects list. exact: disambiguates from the coexisting shell
    // control "Back to projects" (substring role-name matching).
    await page.getByRole('button', { name: 'Back to project', exact: true }).click();
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
    const body = await clipReviewsResponse.json();
    expect(Object.keys(body.data).sort()).toEqual(['data', 'links', 'meta']);
    expect(body.data.data).toEqual([]);
    expect(body.data.meta.total).toBe(0);
    expect(body.data.meta.last_page).toBe(1);
    expect(body.data.links.next).toBeNull();
    expect(body.data.links.prev).toBeNull();
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

  /*
   * Slice 2 helpers: seed a pending ClipReview via the test-seeding route,
   * then drive the full approve / reject / needs_changes UI workflow.
   */

  async function seedClipReview(page: Page, projectId: number) {
    // Use page.evaluate(fetch) instead of page.request.post(): the browser
    // context sends the session cookie (HttpOnly, SameSite=Lax) automatically,
    // while page.request.post() does not include SameSite=Lax cookies for
    // non-navigation requests, resulting in 401 from auth:sanctum.
    const result = await page.evaluate(async (pid) => {
      const cookieMap: Record<string, string> = {};
      for (const part of document.cookie.split(';')) {
        const eq = part.indexOf('=');
        if (eq === -1) continue;
        cookieMap[part.slice(0, eq).trim()] = part.slice(eq + 1).trim();
      }
      const xsrf = cookieMap['XSRF-TOKEN'] || '';
      const res = await fetch('/api/v1/test/clip-reviews/seed', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-XSRF-TOKEN': decodeURIComponent(xsrf),
        },
        body: JSON.stringify({ project_id: pid }),
      });
      const body = await res.json();
      return { status: res.status, body };
    }, projectId);

    expect(result.status, 'seed route status').toBe(200);
    expect(result.body.data.status).toBe('pending');
    expect(result.body.data.video_url).toBeDefined();
    return result.body.data.id as number;
  }

  async function selectClipAndVerifyPending(page: Page, clipReviewId: number) {
    // The clip appears in the "Clips to Review" sidebar list
    await expect(page.getByRole('listitem')).toHaveCount(1);

    // Click the clip to open it for review
    await page.getByRole('listitem').first().click();

    // Video player mounts with the clip's video URL
    await expect(page.getByLabel('Clip video')).toBeVisible();

    // Review actions are present
    await expect(page.getByRole('group', { name: 'Review actions' })).toBeVisible();

    // Pending state: Approve button is not pressed (aria-pressed="false")
    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveAttribute(
      'aria-pressed',
      'false'
    );

    // Sidebar listitem shows "Pending" badge
    await expect(page.getByRole('listitem').first()).toContainText('Pending');

    // clipReviewId deep-link param is present
    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId));
  }

  async function approveClip(page: Page, clipReviewId: number) {
    // Click Approve → confirmation dialog opens
    await page.getByRole('button', { name: 'Approve clip' }).click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    await expect(page.getByRole('heading', { name: /confirm approve/i })).toBeVisible();

    // Click Confirm
    await page.getByRole('button', { name: /confirm approve/i }).click();

    // Dialog closes
    await expect(page.getByRole('alertdialog')).not.toBeVisible();

    // Status badge transitions: Approve button is now pressed + disabled (approved = terminal)
    await expect(page.getByRole('button', { name: 'Approve clip' })).toHaveAttribute(
      'aria-pressed',
      'true'
    );
    await expect(page.getByRole('button', { name: 'Approve clip' })).toBeDisabled();

    // Sidebar badge shows Approved
    await expect(page.getByRole('listitem').first()).toContainText('Approved');

    // clipReviewId deep-link param remains consistent
    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId));
  }

  async function rejectClipWithFeedback(page: Page, clipReviewId: number) {
    // Click Reject → confirmation dialog opens
    await page.getByRole('button', { name: 'Reject clip' }).click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    await expect(page.getByRole('heading', { name: /confirm reject/i })).toBeVisible();
    await expect(page.getByText(/feedback will be required/i)).toBeVisible();

    // Click Confirm → dialog closes, feedback form appears
    await page.getByRole('button', { name: /confirm reject/i }).click();
    await expect(page.getByRole('alertdialog')).not.toBeVisible();

    // Feedback form appears (required for rejected)
    await expect(page.getByRole('group', { name: 'Feedback form' })).toBeVisible();

    // Submit button is disabled when feedback is empty (< 10 chars)
    await expect(page.getByRole('button', { name: 'Submit Feedback' })).toBeDisabled();

    // Enter feedback (>= 10 chars) and submit
    await page.locator('#feedback').fill('This clip needs better audio levels.');
    await expect(page.getByRole('button', { name: 'Submit Feedback' })).toBeEnabled();
    await page.getByRole('button', { name: 'Submit Feedback' }).click();

    // Status badge transitions: Reject button pressed + disabled
    await expect(page.getByRole('button', { name: 'Reject clip' })).toHaveAttribute(
      'aria-pressed',
      'true'
    );
    await expect(page.getByRole('button', { name: 'Reject clip' })).toBeDisabled();

    // Sidebar badge shows Rejected
    await expect(page.getByRole('listitem').first()).toContainText('Rejected');

    // clipReviewId deep-link param remains consistent
    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId));
  }

  async function needsChangesClipWithFeedback(page: Page, clipReviewId: number) {
    // Click Needs Changes → confirmation dialog opens
    await page.getByRole('button', { name: 'Needs Changes clip' }).click();
    await expect(page.getByRole('alertdialog')).toBeVisible();
    await expect(page.getByRole('heading', { name: /confirm needs changes/i })).toBeVisible();
    await expect(page.getByText(/feedback will be required/i)).toBeVisible();

    // Click Confirm → dialog closes, feedback form appears
    await page.getByRole('button', { name: /confirm needs changes/i }).click();
    await expect(page.getByRole('alertdialog')).not.toBeVisible();

    // Feedback form appears (required for needs_changes)
    await expect(page.getByRole('group', { name: 'Feedback form' })).toBeVisible();

    // Enter feedback (>= 10 chars) and submit
    await page.locator('#feedback').fill('Please adjust the transition timing.');
    await page.getByRole('button', { name: 'Submit Feedback' }).click();

    // Status badge transitions: Needs Changes button pressed + disabled
    await expect(
      page.getByRole('button', { name: 'Needs Changes clip' })
    ).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toBeDisabled();

    // Sidebar badge shows Needs Changes
    await expect(page.getByRole('listitem').first()).toContainText('Needs Changes');

    // clipReviewId deep-link param remains consistent
    expect(new URL(page.url()).searchParams.get('clipReviewId')).toBe(String(clipReviewId));
  }

  async function cleanupProject(page: Page, projectName: string) {
    await page.getByRole('button', { name: 'Back to project', exact: true }).click();
    await expect(page.getByRole('button', { name: `Open ${projectName}` })).toBeVisible();
    await page.getByRole('button', { name: `Delete ${projectName}` }).click();
    await page.getByRole('button', { name: `Confirm delete ${projectName}` }).click();
    await expect(page.getByText(/No projects yet/)).toBeVisible({ timeout: 10000 });
  }

  test('TC-E2E-05 approve happy path', async ({ page }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Clip Approver', email);

    const projectName = 'Approve Project';
    const projectId = await createProjectViaUi(page, projectName);

    await openProject(page, projectName);

    // Seed a pending review via the test-seeding route
    const clipReviewId = await seedClipReview(page, projectId);

    // Open the Review Clips tab
    const clipReviewsResponse = await openReviewTab(page, projectId);
    expect(clipReviewsResponse.status()).toBe(200);
    const body = await clipReviewsResponse.json();
    expect(body.data.data).toHaveLength(1);
    expect(body.data.meta.total).toBe(1);

    // Select the clip and verify pending state
    await selectClipAndVerifyPending(page, clipReviewId);

    // Approve the clip
    await approveClip(page, clipReviewId);

    // Clean up
    await cleanupProject(page, projectName);

    await expectClean(page, cleanliness);
  });

  test('TC-E2E-06 reject happy path (with feedback)', async ({ page }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Clip Rejecter', email);

    const projectName = 'Reject Project';
    const projectId = await createProjectViaUi(page, projectName);

    await openProject(page, projectName);

    // Seed a pending review
    const clipReviewId = await seedClipReview(page, projectId);

    // Open the Review Clips tab
    await openReviewTab(page, projectId);

    // Select the clip and verify pending state
    await selectClipAndVerifyPending(page, clipReviewId);

    // Reject the clip with feedback
    await rejectClipWithFeedback(page, clipReviewId);

    // Clean up
    await cleanupProject(page, projectName);

    await expectClean(page, cleanliness);
  });

  test('TC-E2E-07 needs_changes happy path (with feedback)', async ({ page }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Clip Requester', email);

    const projectName = 'Needs Changes Project';
    const projectId = await createProjectViaUi(page, projectName);

    await openProject(page, projectName);

    // Seed a pending review
    const clipReviewId = await seedClipReview(page, projectId);

    // Open the Review Clips tab
    await openReviewTab(page, projectId);

    // Select the clip and verify pending state
    await selectClipAndVerifyPending(page, clipReviewId);

    // Request changes with feedback
    await needsChangesClipWithFeedback(page, clipReviewId);

    // Clean up
    await cleanupProject(page, projectName);

    await expectClean(page, cleanliness);
  });

  test('TC-E2E-08 terminal state — no further transition after approve', async ({ page }) => {
    const cleanliness = trackCleanliness(page);

    const email = uniqueEmail();
    await waitForAuthReady(page);
    await registerUser(page, 'Clip Terminal', email);

    const projectName = 'Terminal Project';
    const projectId = await createProjectViaUi(page, projectName);

    await openProject(page, projectName);

    // Seed a pending review
    const clipReviewId = await seedClipReview(page, projectId);

    // Open the Review Clips tab
    await openReviewTab(page, projectId);

    // Select the clip
    await selectClipAndVerifyPending(page, clipReviewId);

    // Approve the clip (terminal state)
    await approveClip(page, clipReviewId);

    // With status "approved", Reject and Needs Changes buttons are disabled
    await expect(page.getByRole('button', { name: 'Reject clip' })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Needs Changes clip' })).toBeDisabled();

    // Status remains approved
    await expect(page.getByRole('listitem').first()).toContainText('Approved');

    // Clean up
    await cleanupProject(page, projectName);

    await expectClean(page, cleanliness);
  });
});
