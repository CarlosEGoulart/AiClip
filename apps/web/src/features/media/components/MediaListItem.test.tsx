import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MediaListItem } from './MediaListItem';
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

describe('MediaListItem', () => {
  it('renders the filename', () => {
    render(
      <MediaListItem media={mockMediaAsset} onDelete={vi.fn()} />,
    );

    expect(screen.getByText('test-video.mp4')).toBeInTheDocument();
  });

  it('formats bytes to human-readable size', () => {
    render(
      <MediaListItem media={mockMediaAsset} onDelete={vi.fn()} />,
    );

    // 5242880 bytes = 5 MB
    expect(screen.getByText('5 MB')).toBeInTheDocument();
  });

  it('formats KB for small files', () => {
    const smallFile = { ...mockMediaAsset, size_bytes: 1024 };
    render(
      <MediaListItem media={smallFile} onDelete={vi.fn()} />,
    );

    expect(screen.getByText('1 KB')).toBeInTheDocument();
  });

  it('formats GB for large files', () => {
    const largeFile = { ...mockMediaAsset, size_bytes: 1073741824 };
    render(
      <MediaListItem media={largeFile} onDelete={vi.fn()} />,
    );

    expect(screen.getByText('1 GB')).toBeInTheDocument();
  });

  it('shows status badge', () => {
    render(
      <MediaListItem media={mockMediaAsset} onDelete={vi.fn()} />,
    );

    expect(screen.getByText('stored')).toBeInTheDocument();
  });

  it('shows formatted date', () => {
    render(
      <MediaListItem media={mockMediaAsset} onDelete={vi.fn()} />,
    );

    expect(screen.getByText(/9\/14\/2026/)).toBeInTheDocument();
  });

  it('shows delete button', () => {
    render(
      <MediaListItem media={mockMediaAsset} onDelete={vi.fn()} />,
    );

    expect(screen.getByRole('button', { name: 'Delete test-video.mp4' })).toBeInTheDocument();
  });

  it('shows confirmation state on first click', () => {
    render(
      <MediaListItem media={mockMediaAsset} onDelete={vi.fn()} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete test-video.mp4' }));

    expect(screen.getByText('Delete this media?')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Confirm delete test-video.mp4' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel delete test-video.mp4' })).toBeInTheDocument();
  });

  it('calls onDelete when confirmed', async () => {
    const onDelete = vi.fn().mockResolvedValue(true);
    render(
      <MediaListItem media={mockMediaAsset} onDelete={onDelete} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete test-video.mp4' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete test-video.mp4' }));

    await waitFor(() => {
      expect(onDelete).toHaveBeenCalledWith(1);
    });
  });

  it('cancels delete when cancel is clicked', () => {
    render(
      <MediaListItem media={mockMediaAsset} onDelete={vi.fn()} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete test-video.mp4' }));
    expect(screen.getByText('Delete this media?')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel delete test-video.mp4' }));
    expect(screen.queryByText('Delete this media?')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete test-video.mp4' })).toBeInTheDocument();
  });

  it('disables buttons during deletion', async () => {
    const onDelete = vi.fn().mockImplementation(() => new Promise(() => {})); // Never resolves
    render(
      <MediaListItem media={mockMediaAsset} onDelete={onDelete} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete test-video.mp4' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete test-video.mp4' }));

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Confirm delete test-video.mp4' })).toBeDisabled();
      expect(screen.getByRole('button', { name: 'Cancel delete test-video.mp4' })).toBeDisabled();
    });
  });
});
