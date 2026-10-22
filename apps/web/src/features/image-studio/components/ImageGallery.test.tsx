import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ImageGallery } from './ImageGallery';
import type { ImageItem } from '../types';

/*
 * TC-FE-04 (test-plan §5), gallery scope.
 *
 * ImageGallery is a pure component: the metadata entries (prompt excerpt,
 * dimensions, file size), status badges (Pending gray, Generated green,
 * Failed red), and the loading / empty / error states with retry are
 * verified with plain props.
 *
 * Issue #115 adds TC-FE-01 (preview + download link for generated images
 * with a signed download_url) and TC-FE-02 (no preview when the artifact
 * is not deliverable: pending, failed, or null download_url).
 */

const SIGNED_DOWNLOAD_URL =
  'http://localhost/api/v1/images/1/download?expires=1792500000&signature=abc123def456';

const makeImage = (overrides: Partial<ImageItem> = {}): ImageItem => ({
  id: 1,
  project_id: 10,
  prompt: 'A serene mountain lake at sunrise',
  parameters: { style_preset: 'cinematic', width: 1080, height: 1920 },
  mime_type: 'image/png',
  size_bytes: 20480,
  width: 1080,
  height: 1920,
  status: 'generated',
  download_url: null,
  created_at: '2026-10-01T00:00:00.000000Z',
  updated_at: '2026-10-01T00:00:00.000000Z',
  ...overrides,
});

const onRetry = vi.fn();

const renderGallery = (props: {
  images?: ImageItem[];
  loading?: boolean;
  error?: string | null;
} = {}) =>
  render(
    <ImageGallery
      images={props.images ?? []}
      loading={props.loading ?? false}
      error={props.error ?? null}
      onRetry={onRetry}
    />
  );

describe('ImageGallery', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    cleanup();
  });

  it('renders a stable loading state while images are being fetched', () => {
    renderGallery({ loading: true });

    expect(screen.getByRole('status', { name: 'Loading images' })).toBeInTheDocument();
  });

  it('renders the empty state when no images exist', () => {
    renderGallery();

    expect(screen.getByText('No images yet')).toBeInTheDocument();
  });

  it('renders the error state with a retry control that calls onRetry', async () => {
    renderGallery({ error: 'Request failed' });

    expect(screen.getByText('Request failed')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Retry loading images' }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('renders a generated image with a green Generated badge, dimensions, and file size', () => {
    renderGallery({ images: [makeImage()] });

    const badge = screen.getByText('Generated');
    expect(badge).toHaveClass('bg-green-100');
    expect(badge).toHaveAttribute('aria-label', 'Status: Generated');
    expect(screen.getByText('A serene mountain lake at sunrise')).toBeInTheDocument();
    expect(screen.getByText('1080 × 1920')).toBeInTheDocument();
    expect(screen.getByText('20.0 KB')).toBeInTheDocument();
  });

  it('renders a pending image with a gray Pending badge and an in-progress hint', () => {
    renderGallery({
      images: [makeImage({ status: 'pending', size_bytes: 0 })],
    });

    const badge = screen.getByText('Pending');
    expect(badge).toHaveClass('bg-gray-100');
    expect(badge).toHaveAttribute('aria-label', 'Status: Pending');
    expect(screen.getByText('Generating…')).toBeInTheDocument();
  });

  it('renders a failed image with a red Failed badge', () => {
    renderGallery({ images: [makeImage({ status: 'failed' })] });

    const badge = screen.getByText('Failed');
    expect(badge).toHaveClass('bg-red-100');
    expect(badge).toHaveAttribute('aria-label', 'Status: Failed');
  });

  it('renders images in a labeled list with listitem entries', () => {
    renderGallery({
      images: [makeImage({ id: 1 }), makeImage({ id: 2, status: 'pending' })],
    });

    expect(screen.getByRole('list', { name: 'Generated images' })).toBeInTheDocument();
    expect(screen.getAllByRole('listitem')).toHaveLength(2);
  });

  it('excerpts long prompts in the gallery entry', () => {
    renderGallery({ images: [makeImage({ prompt: 'x'.repeat(120) })] });

    expect(screen.getByText(`${'x'.repeat(60)}…`)).toBeInTheDocument();
  });

  it('announces pending image status through a polite live region', () => {
    renderGallery({
      images: [makeImage({ id: 1, status: 'pending' }), makeImage({ id: 2, status: 'pending' })],
    });

    const liveRegion = document.querySelector('[aria-live="polite"]');
    expect(liveRegion?.textContent).toMatch(/2 images are generating/);
  });

  it('keeps the retry control touch-target sized with a visible focus indicator', () => {
    renderGallery({ error: 'Request failed' });

    // index.css styles this class with min-height: 44px and a
    // :focus-visible outline.
    const retry = screen.getByRole('button', { name: 'Retry loading images' });
    expect(retry).toHaveClass('image-gallery-retry-button');
  });

  /*
   * TC-FE-01 (test-plan §4): preview + download link for generated images
   * with a signed download_url (Issue #115).
   */

  it('renders a preview img with the exact alt text, intrinsic dimensions, and lazy loading', () => {
    renderGallery({ images: [makeImage({ download_url: SIGNED_DOWNLOAD_URL })] });

    const img = screen.getByRole('img', {
      name: 'Generated image: A serene mountain lake at sunrise',
    });
    expect(img).toHaveAttribute('src', SIGNED_DOWNLOAD_URL);
    expect(img).toHaveAttribute('width', '1080');
    expect(img).toHaveAttribute('height', '1920');
    expect(img).toHaveAttribute('loading', 'lazy');
    expect(img).toHaveClass('image-gallery-card-preview');
  });

  it('renders a Download image link with href, new-tab target, and noopener noreferrer rel', () => {
    renderGallery({ images: [makeImage({ download_url: SIGNED_DOWNLOAD_URL })] });

    const link = screen.getByRole('link', { name: 'Download image' });
    expect(link).toHaveAttribute('href', SIGNED_DOWNLOAD_URL);
    expect(link).toHaveAttribute('target', '_blank');
    expect(link.getAttribute('rel')).toContain('noopener');
    expect(link.getAttribute('rel')).toContain('noreferrer');
    expect(link).toHaveClass('image-gallery-download');
  });

  it('replaces a failed preview load with a fallback while keeping prompt, badge, meta, and link', () => {
    renderGallery({ images: [makeImage({ download_url: SIGNED_DOWNLOAD_URL })] });

    const img = screen.getByRole('img', {
      name: 'Generated image: A serene mountain lake at sunrise',
    });
    fireEvent.error(img);

    expect(screen.getByText('Preview unavailable')).toBeInTheDocument();
    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.getByText('A serene mountain lake at sunrise')).toBeInTheDocument();
    expect(screen.getByText('Generated')).toBeInTheDocument();
    expect(screen.getByText('1080 × 1920')).toBeInTheDocument();
    expect(screen.getByText('20.0 KB')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Download image' })).toBeInTheDocument();
  });

  /*
   * TC-FE-02 (test-plan §4): no preview when the artifact is not
   * deliverable (Issue #115).
   */

  it('renders no preview and no download link for a pending image', () => {
    renderGallery({
      images: [makeImage({ status: 'pending', size_bytes: 0, download_url: null })],
    });

    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Download image' })).not.toBeInTheDocument();
    expect(screen.getByText('Pending')).toBeInTheDocument();
    expect(screen.getByText('Generating…')).toBeInTheDocument();
  });

  it('renders no preview and no download link for a failed image', () => {
    renderGallery({ images: [makeImage({ status: 'failed' })] });

    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Download image' })).not.toBeInTheDocument();
    expect(screen.getByText('Failed')).toBeInTheDocument();
  });

  it('renders no preview and no download link for a generated image without a download url', () => {
    renderGallery({ images: [makeImage({ download_url: null })] });

    expect(screen.queryByRole('img')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Download image' })).not.toBeInTheDocument();
    expect(screen.getByText('Generated')).toBeInTheDocument();
  });
});
