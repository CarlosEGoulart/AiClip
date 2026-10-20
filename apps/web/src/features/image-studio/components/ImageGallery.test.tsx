import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, cleanup } from '@testing-library/react';
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
 */

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

    const retry = screen.getByRole('button', { name: 'Retry loading images' });
    expect(retry).toHaveClass('min-h-11');
    expect(retry.className).toMatch(/focus-visible:ring-2/);
  });
});
