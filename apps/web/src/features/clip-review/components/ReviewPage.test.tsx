import { describe, it, expect, vi, beforeEach, type Mock } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ReviewPage } from './ReviewPage';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';

// Mock hooks
vi.mock('../hooks', () => ({
  useClipReviews: vi.fn(),
  useUpdateClipReviewStatus: vi.fn(),
  useSubmitClipReviewFeedback: vi.fn(),
}));

import { useClipReviews, useUpdateClipReviewStatus, useSubmitClipReviewFeedback } from '../hooks';

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
    return ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/review']}>{children}</MemoryRouter>
      </QueryClientProvider>
    );
  };

  beforeEach(() => {
    vi.clearAllMocks();
    // Mock the hook to return loading state initially
    (useClipReviews as Mock).mockReturnValue({
      data: undefined,
      isLoading: true,
      refetch: vi.fn(),
    });
    (useUpdateClipReviewStatus as Mock).mockReturnValue({
      mutateAsync: vi.fn().mockResolvedValue(undefined),
      isPending: false,
    });
    (useSubmitClipReviewFeedback as Mock).mockReturnValue({
      mutateAsync: vi.fn().mockResolvedValue(undefined),
      isPending: false,
    });
  });

  it('shows loading state initially', () => {
    render(<ReviewPage projectId={projectId} projectName={projectName} onBack={onBack} />, { wrapper: createWrapper() });

    expect(screen.getByLabelText(/loading reviews/i)).toBeInTheDocument();
  });

  it('shows header with project name and back button', () => {
    // Mock data loaded
    (useClipReviews as Mock).mockReturnValue({
      data: { data: [], links: {}, meta: {} },
      isLoading: false,
      refetch: vi.fn(),
    });

    render(<ReviewPage projectId={projectId} projectName={projectName} onBack={onBack} />, { wrapper: createWrapper() });

    expect(screen.getByText(projectName)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /back to project/i })).toBeInTheDocument();
  });

  it('loads clip reviews via hook', () => {
    render(<ReviewPage projectId={projectId} projectName={projectName} onBack={onBack} />, { wrapper: createWrapper() });

    expect(useClipReviews).toHaveBeenCalledWith(projectId);
  });

  it('shows empty state when no clips', () => {
    (useClipReviews as Mock).mockReturnValue({
      data: { data: [], links: {}, meta: {} },
      isLoading: false,
      refetch: vi.fn(),
    });

    render(<ReviewPage projectId={projectId} projectName={projectName} onBack={onBack} />, { wrapper: createWrapper() });

    expect(screen.getByText(/select a clip to review/i)).toBeInTheDocument();
  });

  it('calls onBack when back button clicked', async () => {
    (useClipReviews as Mock).mockReturnValue({
      data: { data: [], links: {}, meta: {} },
      isLoading: false,
      refetch: vi.fn(),
    });

    render(<ReviewPage projectId={projectId} projectName={projectName} onBack={onBack} />, { wrapper: createWrapper() });

    await userEvent.click(screen.getByRole('button', { name: /back to project/i }));

    expect(onBack).toHaveBeenCalled();
  });
});