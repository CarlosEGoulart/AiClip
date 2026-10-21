import { describe, it, expect, vi, beforeEach, afterEach, type Mock } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ImageStudio } from './ImageStudio';
import type { ImageItem } from '../types';

/*
 * TC-FE-01 / TC-FE-02 / TC-FE-03 / TC-FE-04 / TC-FE-05 (test-plan §5),
 * composition scope.
 *
 * ImageStudio is rendered WITHOUT any Router provider: the production tree
 * (main.tsx -> App -> AuthenticatedShell -> ProjectMediaSection -> "Image
 * Studio" tab -> ImageStudio) contains no Router. The hooks module is
 * mocked to isolate ImageStudio's own behavior (local pending entries
 * seeded from the 202 response, server-truth merge, error surfacing); the
 * query-client wrapper is kept because the production App root provides
 * React Query context.
 */

vi.mock('../hooks', () => ({
  useImages: vi.fn(),
  useGenerateImage: vi.fn(),
}));

import { useImages, useGenerateImage } from '../hooks';

const makeImage = (overrides: Partial<ImageItem> = {}): ImageItem => ({
  id: 101,
  project_id: 10,
  prompt: 'A serene mountain lake at sunrise',
  parameters: { style_preset: 'cinematic', width: 1080, height: 1920 },
  mime_type: 'image/png',
  size_bytes: 40960,
  width: 1080,
  height: 1920,
  status: 'generated',
  created_at: '2026-10-01T00:00:00.000000Z',
  updated_at: '2026-10-01T00:00:00.000000Z',
  ...overrides,
});

const mutateAsync = vi.fn();

// Stateful mutation double mirroring React Query's mutation result: the
// error state persists after mutateAsync rejects, and the component
// re-renders with it (React Query triggers that re-render in production).
const mutationState = {
  mutateAsync,
  isPending: false,
  error: null as Error | null,
};

const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
};

describe('ImageStudio', () => {
  const projectId = 7;

  const renderStudio = () =>
    render(<ImageStudio projectId={projectId} />, { wrapper: createWrapper() });

  const mockImages = (
    images: ImageItem[],
    extras: { isLoading?: boolean; error?: Error | null; refetch?: Mock } = {}
  ) => {
    (useImages as Mock).mockReturnValue({
      data: { data: images, links: {}, meta: {} },
      isLoading: extras.isLoading ?? false,
      error: extras.error ?? null,
      refetch: extras.refetch ?? vi.fn(),
    });
  };

  const mockMutation = (extras: { isPending?: boolean; error?: Error | null } = {}) => {
    mutationState.isPending = extras.isPending ?? false;
    mutationState.error = extras.error ?? null;
    (useGenerateImage as Mock).mockReturnValue(mutationState);
  };

  beforeEach(() => {
    vi.clearAllMocks();
    mockImages([]);
    mockMutation();
  });

  afterEach(() => {
    cleanup();
  });

  it('renders the Image Studio heading with a description, the generate form, and the gallery region', () => {
    renderStudio();

    expect(screen.getByRole('heading', { name: 'Image Studio' })).toBeInTheDocument();
    expect(screen.getByLabelText('Prompt')).toBeInTheDocument();
    expect(screen.getByText('0/1000')).toBeInTheDocument();
    expect(screen.getByLabelText('Style preset')).toBeInTheDocument();
    expect(screen.getByLabelText('Dimensions')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Generate' })).toBeInTheDocument();
    expect(screen.getByText('No images yet')).toBeInTheDocument();
  });

  it('loads images through the project-scoped query with pending-aware polling inputs', () => {
    renderStudio();

    expect(useImages).toHaveBeenCalledWith(projectId, 0);
  });

  it('generates through the mutation with the project id and current form values', async () => {
    mutateAsync.mockResolvedValueOnce({
      data: makeImage({ id: 101, status: 'pending', size_bytes: 0 }),
    });
    renderStudio();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A neon-lit city skyline at dusk');
    await userEvent.selectOptions(screen.getByLabelText('Style preset'), 'cinematic');
    await userEvent.selectOptions(screen.getByLabelText('Dimensions'), 'vertical');
    await userEvent.click(screen.getByRole('button', { name: 'Generate' }));

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1));
    expect(mutateAsync).toHaveBeenCalledWith({
      projectId,
      prompt: 'A neon-lit city skyline at dusk',
      stylePreset: 'cinematic',
      width: 1080,
      height: 1920,
    });
  });

  it('shows the pending 202 resource immediately as a local pending gallery entry', async () => {
    mutateAsync.mockResolvedValueOnce({
      data: makeImage({ id: 101, status: 'pending', size_bytes: 0 }),
    });
    renderStudio();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor at dawn');
    await userEvent.click(screen.getByRole('button', { name: 'Generate' }));

    await waitFor(() => {
      expect(screen.getByText('Pending')).toHaveClass('bg-gray-100');
    });
    expect(screen.getByText('A quiet harbor at dawn')).toBeInTheDocument();

    // The local pending entry is the polling input for the images query.
    expect(useImages).toHaveBeenLastCalledWith(projectId, 1);
  });

  it('flips the badge to Generated once the images query returns the terminal state', async () => {
    mutateAsync.mockResolvedValueOnce({
      data: makeImage({ id: 101, status: 'pending', size_bytes: 0 }),
    });
    const view = renderStudio();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor at dawn');
    await userEvent.click(screen.getByRole('button', { name: 'Generate' }));
    await waitFor(() => expect(screen.getByText('Pending')).toBeInTheDocument());

    // The next refetch returns server truth: the image is generated now.
    mockImages([makeImage({ id: 101, status: 'generated', size_bytes: 40960 })]);
    view.rerender(<ImageStudio projectId={projectId} />);

    await waitFor(() => {
      expect(screen.getByText('Generated')).toHaveClass('bg-green-100');
    });
    expect(screen.getByText('1080 × 1920')).toBeInTheDocument();
    expect(screen.getByText('40.0 KB')).toBeInTheDocument();

    // Server truth replaces the local pending entry: exactly one entry.
    expect(screen.getAllByRole('listitem')).toHaveLength(1);
  });

  it('does not call the mutation for an empty prompt and announces the validation error', () => {
    renderStudio();

    const form = screen.getByLabelText('Prompt').closest('form');
    fireEvent.submit(form as HTMLFormElement);

    expect(mutateAsync).not.toHaveBeenCalled();
    expect(screen.getByText(/enter a prompt before generating/i)).toBeInTheDocument();
  });

  it('announces a rejected generate mutation as a readable message in the live region', async () => {
    mutateAsync.mockRejectedValueOnce(new Error('The prompt field is required.'));
    const view = renderStudio();

    await userEvent.type(screen.getByLabelText('Prompt'), 'A quiet harbor at dawn');
    await userEvent.click(screen.getByRole('button', { name: 'Generate' }));

    await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1));

    // React Query records the rejection in the mutation error state and
    // the component re-renders with it.
    mutationState.error = new Error('The prompt field is required.');
    view.rerender(<ImageStudio projectId={projectId} />);

    expect(screen.getByText('The prompt field is required.')).toBeInTheDocument();

    const liveRegion = document.querySelector('[aria-live="polite"]');
    expect(liveRegion?.textContent).toContain('The prompt field is required.');
  });

  it('renders the gallery loading state while images load', () => {
    mockImages([], { isLoading: true });
    renderStudio();

    expect(screen.getByRole('status', { name: 'Loading images' })).toBeInTheDocument();
  });

  it('renders the gallery error state with retry wiring to the query refetch', async () => {
    const refetch = vi.fn();
    mockImages([], { error: new Error('Request failed'), refetch });
    renderStudio();

    expect(screen.getByText('Request failed')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Retry loading images' }));
    expect(refetch).toHaveBeenCalledTimes(1);
  });

  it('exposes polite live regions for form and gallery announcements', () => {
    renderStudio();

    expect(document.querySelectorAll('[aria-live="polite"]').length).toBeGreaterThanOrEqual(2);
  });

  it('keeps the composed controls programmatically labeled with touch-target sizing', () => {
    renderStudio();

    // The field class index.css styles with min-height: 44px and a
    // :focus border + ring; the button class with min-height: 44px and
    // a :focus-visible outline.
    const fields = [
      screen.getByLabelText('Prompt'),
      screen.getByLabelText('Style preset'),
      screen.getByLabelText('Dimensions'),
    ];
    for (const field of fields) {
      expect(field).toHaveClass('image-studio-field-control');
    }

    expect(screen.getByRole('button', { name: 'Generate' })).toHaveClass(
      'image-studio-submit-button',
    );
  });
});
