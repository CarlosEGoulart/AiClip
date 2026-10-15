import { describe, it, expect, vi, beforeEach, afterEach, type Mock } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ReviewPage } from './ReviewPage';
import type { ClipReview } from '../types';

/*
 * TC-FE-05 (ratified test-plan §4).
 *
 * ReviewPage is rendered WITHOUT any Router provider: the production tree
 * (main.tsx -> App -> AuthenticatedShell -> ProjectMediaSection -> ReviewPage)
 * contains no Router, and react-router hooks throw outside Router context.
 * A MemoryRouter wrapper here previously masked that crash.
 *
 * URL-sync assertions use window.location / window.history (jsdom) because
 * the deep-linking contract is implemented with the native History API.
 *
 * The hooks module is mocked to isolate ReviewPage's own behavior; the
 * query-client wrapper is kept because the production App root provides
 * React Query context (see src/__tests__/ClipReviewSection.test.tsx for the
 * full production-topology mount test with the real hooks).
 */

vi.mock('../hooks', () => ({
  useClipReviews: vi.fn(),
  useUpdateClipReviewStatus: vi.fn(),
  useSubmitClipReviewFeedback: vi.fn(),
}));

import { useClipReviews, useUpdateClipReviewStatus, useSubmitClipReviewFeedback } from '../hooks';

const makeClip = (overrides: Partial<ClipReview> = {}): ClipReview => ({
  id: 1,
  derived_asset_id: 100,
  status: 'pending',
  feedback: null,
  video_url: '/api/v1/clip-reviews/1/video?signature=signature-1&expires=9999999999',
  thumbnail_url: null,
  duration_ms: 61000,
  width: 1080,
  height: 1920,
  candidate_index: 0,
  render_profile_version: 'v1',
  created_at: '2026-10-01T00:00:00.000000Z',
  updated_at: '2026-10-01T00:00:00.000000Z',
  ...overrides,
});

const clipOne = makeClip({
  id: 11,
  derived_asset_id: 101,
  status: 'approved',
  candidate_index: 0,
  video_url: '/api/v1/clip-reviews/11/video?signature=signature-11&expires=1111111111',
});
const clipTwo = makeClip({
  id: 22,
  derived_asset_id: 102,
  status: 'pending',
  candidate_index: 1,
  video_url: '/api/v1/clip-reviews/22/video?signature=signature-22&expires=2222222222',
});
const clipThree = makeClip({
  id: 33,
  derived_asset_id: 103,
  status: 'pending',
  candidate_index: 2,
  video_url: '/api/v1/clip-reviews/33/video?signature=signature-33&expires=3333333333',
});
const loadedClips = [clipOne, clipTwo, clipThree];

const clipReviewIdParam = (): string | null =>
  new URLSearchParams(window.location.search).get('clipReviewId');

describe('ReviewPage', () => {
  const projectId = 1;
  const projectName = 'Test Project';
  const onBack = vi.fn();

  const createWrapper = () => {
    const queryClient = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });
    // Production topology: React Query context only. No Router provider
    // exists in the production tree, so none is provided here.
    return ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>
        {children}
      </QueryClientProvider>
    );
  };

  const renderPage = () =>
    render(<ReviewPage projectId={projectId} projectName={projectName} onBack={onBack} />, {
      wrapper: createWrapper(),
    });

  const mockLoaded = (clips: ClipReview[] = loadedClips) => {
    (useClipReviews as Mock).mockReturnValue({
      data: { data: clips, links: {}, meta: {} },
      isLoading: false,
      refetch: vi.fn(),
    });
  };

  const mockLoading = () => {
    (useClipReviews as Mock).mockReturnValue({
      data: undefined,
      isLoading: true,
      refetch: vi.fn(),
    });
  };

  beforeEach(() => {
    vi.clearAllMocks();
    window.history.replaceState(null, '', '/');
    mockLoading();
    (useUpdateClipReviewStatus as Mock).mockReturnValue({
      mutateAsync: vi.fn().mockResolvedValue(undefined),
      isPending: false,
    });
    (useSubmitClipReviewFeedback as Mock).mockReturnValue({
      mutateAsync: vi.fn().mockResolvedValue(undefined),
      isPending: false,
    });
  });

  afterEach(() => {
    cleanup();
    window.history.replaceState(null, '', '/');
  });

  it('mounts without a Router provider (production topology)', () => {
    mockLoaded();
    renderPage();

    expect(screen.getByRole('heading', { name: projectName })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Back to project' })).toBeInTheDocument();
  });

  it('shows the loading state while reviews are being fetched', () => {
    mockLoading();
    renderPage();

    expect(screen.getByLabelText(/loading reviews/i)).toBeInTheDocument();
  });

  it('loads clip reviews via the hook', () => {
    mockLoaded();
    renderPage();

    expect(useClipReviews).toHaveBeenCalledWith(projectId);
  });

  it('shows the header with project name, back button, and clip counter', () => {
    mockLoaded();
    renderPage();

    expect(screen.getByRole('heading', { name: projectName })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Back to project' })).toBeInTheDocument();
    // The first pending clip (clipTwo at index 1) is auto-selected on load.
    expect(screen.getByText('Clip 2 of 3')).toBeInTheDocument();
  });

  it('selects the first pending clip on load and writes the clipReviewId URL param', () => {
    mockLoaded();
    renderPage();

    expect(screen.getByLabelText('Clip video')).toHaveAttribute('src', clipTwo.video_url);
    expect(clipReviewIdParam()).toBe(String(clipTwo.id));
  });

  it('deep-links to the clip referenced by the clipReviewId URL parameter', () => {
    window.history.replaceState(null, '', `/?clipReviewId=${clipThree.id}`);
    mockLoaded();
    renderPage();

    expect(screen.getByLabelText('Clip video')).toHaveAttribute('src', clipThree.video_url);
    expect(screen.getByText('Clip 3 of 3')).toBeInTheDocument();
    expect(clipReviewIdParam()).toBe(String(clipThree.id));
  });

  it('selects nothing when the deep-linked clip does not exist', () => {
    window.history.replaceState(null, '', '/?clipReviewId=999');
    mockLoaded();
    renderPage();

    expect(screen.queryByLabelText('Clip video')).not.toBeInTheDocument();
    expect(screen.getByText('Select a clip to review')).toBeInTheDocument();
  });

  it('shows the current clip status in ReviewActions', () => {
    window.history.replaceState(null, '', `/?clipReviewId=${clipOne.id}`);
    mockLoaded();
    renderPage();

    expect(screen.getByRole('button', { name: 'Approve clip' })).toHaveAttribute(
      'aria-pressed',
      'true'
    );
    expect(screen.getByRole('button', { name: 'Reject clip' })).toHaveAttribute(
      'aria-pressed',
      'false'
    );
  });

  it('renders the FeedbackForm for a rejected clip but not for an approved clip', () => {
    const rejectedClip = makeClip({
      id: 44,
      derived_asset_id: 104,
      status: 'rejected',
      candidate_index: 3,
      video_url: '/api/v1/clip-reviews/44/video?signature=signature-44&expires=4444444444',
    });

    window.history.replaceState(null, '', `/?clipReviewId=${rejectedClip.id}`);
    mockLoaded([clipOne, rejectedClip]);
    renderPage();
    expect(screen.getByRole('group', { name: 'Feedback form' })).toBeInTheDocument();

    cleanup();
    window.history.replaceState(null, '', `/?clipReviewId=${clipOne.id}`);
    mockLoaded([clipOne, rejectedClip]);
    renderPage();
    expect(screen.queryByRole('group', { name: 'Feedback form' })).not.toBeInTheDocument();
    expect(screen.getByText(/Feedback is optional for approved clips/i)).toBeInTheDocument();
  });

  it('navigates to the next clip with the N key and updates the URL', () => {
    mockLoaded();
    renderPage();
    expect(screen.getByLabelText('Clip video')).toHaveAttribute('src', clipTwo.video_url);

    fireEvent.keyDown(document.body, { key: 'n' });

    expect(screen.getByLabelText('Clip video')).toHaveAttribute('src', clipThree.video_url);
    expect(clipReviewIdParam()).toBe(String(clipThree.id));
  });

  it('navigates to the previous clip with the P key and updates the URL', () => {
    window.history.replaceState(null, '', `/?clipReviewId=${clipThree.id}`);
    mockLoaded();
    renderPage();

    fireEvent.keyDown(document.body, { key: 'p' });

    expect(screen.getByLabelText('Clip video')).toHaveAttribute('src', clipTwo.video_url);
    expect(clipReviewIdParam()).toBe(String(clipTwo.id));
  });

  it('does not navigate past the first or last clip with P and N', () => {
    window.history.replaceState(null, '', `/?clipReviewId=${clipOne.id}`);
    mockLoaded();
    renderPage();

    // P at the first clip is a no-op.
    fireEvent.keyDown(document.body, { key: 'p' });
    expect(screen.getByLabelText('Clip video')).toHaveAttribute('src', clipOne.video_url);
    expect(clipReviewIdParam()).toBe(String(clipOne.id));

    // N walks to the last clip and then no-ops there.
    fireEvent.keyDown(document.body, { key: 'n' });
    fireEvent.keyDown(document.body, { key: 'n' });
    fireEvent.keyDown(document.body, { key: 'n' });
    expect(screen.getByLabelText('Clip video')).toHaveAttribute('src', clipThree.video_url);
    expect(screen.getByText('Clip 3 of 3')).toBeInTheDocument();
    expect(clipReviewIdParam()).toBe(String(clipThree.id));
  });

  it('shows the empty state when there are no clips and leaves the URL untouched', () => {
    mockLoaded([]);
    renderPage();

    expect(screen.getByText('No clips')).toBeInTheDocument();
    expect(screen.getByText('Select a clip to review')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Clips to Review' })).toBeInTheDocument();
    expect(screen.queryByLabelText('Clip video')).not.toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Review actions' })).not.toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Feedback form' })).not.toBeInTheDocument();
    expect(clipReviewIdParam()).toBeNull();
  });

  it('calls onBack when the back button is clicked', async () => {
    mockLoaded([]);
    renderPage();

    await userEvent.click(screen.getByRole('button', { name: 'Back to project' }));

    expect(onBack).toHaveBeenCalled();
  });
});
