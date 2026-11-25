import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MediaList } from './MediaList';
import type { MediaAsset } from '../types';

afterEach(cleanup);

const mockMediaAsset: MediaAsset = {
  id: 1,
  project_id: 1,
  original_name: 'test-video.mp4',
  mime_type: 'video/mp4',
  size_bytes: 5242880,
  status: 'stored',
  created_at: '2026-09-14T12:00:00.000000Z',
  updated_at: '2026-09-14T12:00:00.000000Z',
};

const mockMediaAssets: MediaAsset[] = [
  mockMediaAsset,
  { ...mockMediaAsset, id: 2, original_name: 'video2.mp4' },
];

describe('MediaList', () => {
  it('shows loading state', () => {
    render(
      <MediaList mediaAssets={[]} onDelete={vi.fn()} loading={true} />,
    );

    expect(screen.getByRole('status')).toHaveTextContent('Loading media assets…');
  });

  it('shows empty state heading when no media assets', () => {
    render(
      <MediaList mediaAssets={[]} onDelete={vi.fn()} loading={false} />,
    );

    expect(screen.getByText(/No media assets yet/)).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'No media assets yet' })).toBeInTheDocument();
  });

  it('shows empty state body text', () => {
    render(
      <MediaList mediaAssets={[]} onDelete={vi.fn()} loading={false} />,
    );

    expect(screen.getByText(/Upload a video to get started/)).toBeInTheDocument();
  });

  it('renders media list heading', () => {
    render(
      <MediaList mediaAssets={mockMediaAssets} onDelete={vi.fn()} loading={false} />,
    );

    expect(screen.getByRole('heading', { name: 'Media Assets' })).toBeInTheDocument();
  });

  it('renders media list items', () => {
    render(
      <MediaList mediaAssets={mockMediaAssets} onDelete={vi.fn()} loading={false} />,
    );

    expect(screen.getByText('test-video.mp4')).toBeInTheDocument();
    expect(screen.getByText('video2.mp4')).toBeInTheDocument();
    expect(screen.queryByText(/No media assets yet/)).not.toBeInTheDocument();
  });

  it('renders correct number of items', () => {
    render(
      <MediaList mediaAssets={mockMediaAssets} onDelete={vi.fn()} loading={false} />,
    );

    const items = screen.getAllByRole('listitem');
    expect(items).toHaveLength(2);
  });

  it('shows delete button for each item', () => {
    render(
      <MediaList mediaAssets={mockMediaAssets} onDelete={vi.fn()} loading={false} />,
    );

    const deleteButtons = screen.getAllByRole('button', { name: /Delete/ });
    expect(deleteButtons.length).toBeGreaterThanOrEqual(2);
  });

  // --- Visual / accessibility additions (issue #158) ---

  it('every touch target is at least 44px', () => {
    render(
      <MediaList mediaAssets={mockMediaAssets} onDelete={vi.fn()} loading={false} />,
    );

    const buttons = screen.getAllByRole('button');
    for (const button of buttons) {
      const box = button.getBoundingClientRect();
      expect(box.height).toBeGreaterThanOrEqual(44);
      expect(box.width).toBeGreaterThanOrEqual(44);
    }
  });

  it('every list item is keyboard focusable in reading order', () => {
    render(
      <MediaList mediaAssets={mockMediaAssets} onDelete={vi.fn()} loading={false} />,
    );

    const items = screen.getAllByRole('listitem');
    expect(items.length).toBeGreaterThan(0);
    for (const item of items) {
      expect(item).toHaveAttribute('tabindex', '0');
      item.focus();
      expect(document.activeElement).toBe(item);
    }
  });
});
