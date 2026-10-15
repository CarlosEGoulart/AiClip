import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';

/*
 * Production-topology mount test for the embedded review section
 * (plan Phase 7.4 dependency; Phase 7.5 defect class).
 *
 * The real App tree (main.tsx -> App -> AuthProvider -> AuthenticatedShell ->
 * ProjectMediaSection -> "Review Clips" tab -> ReviewPage) must mount the
 * review section without a Router provider and with the production React
 * Query wiring. Nothing in the component tree is mocked here: the clip-review
 * hooks run for real; only the network transport (fetch) is stubbed.
 */

const user = { id: 7, name: 'Review User', email: 'review@example.test', email_verified_at: null };
const project = {
  id: 1,
  name: 'Review Project',
  description: null,
  created_at: '2026-10-01T00:00:00.000000Z',
  updated_at: '2026-10-01T00:00:00.000000Z',
};

const json = (status: number, body?: unknown) =>
  new Response(body === undefined ? null : JSON.stringify(body), { status });

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  fetchMock.mockReset().mockImplementation(async (url) => {
    const target = String(url);
    if (target === '/api/v1/auth/me') return json(200, { user });
    if (target === '/api/v1/projects') return json(200, { data: [project] });
    if (target === '/api/v1/projects/1/media') return json(200, { data: [] });
    if (target === '/api/v1/projects/1/clip-reviews') return json(200, { data: [] });
    if (target === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=test%3D; path=/';
      return json(204);
    }
    if (target === '/api/v1/health') return json(200, { status: 'ok', database: 'connected' });
    return json(200, {});
  });
  vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
  cleanup();
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
  window.history.replaceState(null, '', '/');
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('embedded clip review section (production topology)', () => {
  it('mounts the empty review section through the real App tree', async () => {
    render(<App />);

    // Authenticated shell restores the session and lists the project.
    expect(await screen.findByText('Signed in as')).toBeVisible();
    const openButton = await screen.findByRole('button', { name: 'Open Review Project' });
    fireEvent.click(openButton);
    expect(await screen.findByRole('button', { name: 'Back to projects' })).toBeVisible();
    expect(screen.getByRole('tab', { name: 'Review Clips' })).toBeVisible();

    // Opening the review tab mounts ReviewPage with the REAL hooks
    // (no Router provider, production React Query context).
    fireEvent.click(screen.getByRole('tab', { name: 'Review Clips' }));

    // The empty review section renders after the real fetch resolves
    // {"data": []}.
    expect(await screen.findByText('Select a clip to review')).toBeVisible();
    expect(screen.getByText('No clips')).toBeVisible();
    expect(screen.getByRole('heading', { name: 'Clips to Review' })).toBeVisible();
    expect(screen.queryByLabelText('Clip video')).not.toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Review actions' })).not.toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Feedback form' })).not.toBeInTheDocument();
  });
});
