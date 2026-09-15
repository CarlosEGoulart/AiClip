import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useMediaAssets } from '../hooks';
import type { MediaAsset } from '../types';

// Mock the API module
vi.mock('../api', () => ({
  getMediaAssets: vi.fn(),
  uploadMediaAsset: vi.fn(),
  deleteMediaAsset: vi.fn(),
}));

import * as mediaApi from '../api';

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

describe('useMediaAssets hook', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('starts with empty mediaAssets, no loading, no error', () => {
    function TestComponent() {
      const { mediaAssets, loading, error } = useMediaAssets(1);
      return (
        <div>
          <span data-testid="count">{mediaAssets.length}</span>
          <span data-testid="loading">{String(loading)}</span>
          <span data-testid="error">{error ?? 'null'}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('count').textContent).toBe('0');
    expect(screen.getByTestId('loading').textContent).toBe('false');
    expect(screen.getByTestId('error').textContent).toBe('null');
  });

  it('does not fetch if projectId is null', () => {
    function TestComponent() {
      const { mediaAssets, loading } = useMediaAssets(null);
      return (
        <div>
          <span data-testid="count">{mediaAssets.length}</span>
          <span data-testid="loading">{String(loading)}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('count').textContent).toBe('0');
    expect(screen.getByTestId('loading').textContent).toBe('false');
    expect(mediaApi.getMediaAssets).not.toHaveBeenCalled();
  });

  it('fetches media assets successfully', async () => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValue({ data: mockMediaAssets });

    function TestComponent() {
      const { mediaAssets, loading, fetchMedia } = useMediaAssets(1);
      return (
        <div>
          <button onClick={fetchMedia}>Load</button>
          <span data-testid="count">{mediaAssets.length}</span>
          <span data-testid="loading">{String(loading)}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('count').textContent).toBe('0');

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('2');
    });
    expect(screen.getByTestId('loading').textContent).toBe('false');
  });

  it('handles fetch error', async () => {
    vi.mocked(mediaApi.getMediaAssets).mockRejectedValue({ type: 'network' });

    function TestComponent() {
      const { error, fetchMedia } = useMediaAssets(1);
      return (
        <div>
          <button onClick={fetchMedia}>Load</button>
          <span data-testid="error">{error ?? 'null'}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('error').textContent).toBe('null');

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('error').textContent).toContain('Network');
    });
  });

  it('uploads a file and adds it to the list', async () => {
    vi.mocked(mediaApi.uploadMediaAsset).mockResolvedValue({ data: mockMediaAsset });

    function TestComponent() {
      const { mediaAssets, uploadFile } = useMediaAssets(1);
      return (
        <div>
          <button onClick={() => {
            const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
            uploadFile(file);
          }}>Upload</button>
          <span data-testid="count">{mediaAssets.length}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('count').textContent).toBe('0');

    await act(async () => {
      fireEvent.click(screen.getByText('Upload'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('1');
    });
  });

  it('handles upload error', async () => {
    vi.mocked(mediaApi.uploadMediaAsset).mockRejectedValue({ type: 'file-too-large' });

    function TestComponent() {
      const { error, uploadFile } = useMediaAssets(1);
      return (
        <div>
          <button onClick={() => {
            const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
            uploadFile(file);
          }}>Upload</button>
          <span data-testid="error">{error ?? 'null'}</span>
        </div>
      );
    }

    render(<TestComponent />);

    await act(async () => {
      fireEvent.click(screen.getByText('Upload'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('error').textContent).toContain('100 MB');
    });
  });

  it('deletes media and removes it from the list', async () => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValue({ data: mockMediaAssets });
    vi.mocked(mediaApi.deleteMediaAsset).mockResolvedValue(undefined);

    function TestComponent() {
      const { mediaAssets, fetchMedia, deleteMedia } = useMediaAssets(1);
      return (
        <div>
          <button onClick={fetchMedia}>Load</button>
          <button onClick={() => deleteMedia(1)}>Delete 1</button>
          <span data-testid="count">{mediaAssets.length}</span>
        </div>
      );
    }

    render(<TestComponent />);

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });
    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('2');
    });

    await act(async () => {
      fireEvent.click(screen.getByText('Delete 1'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('1');
    });
    expect(mediaApi.deleteMediaAsset).toHaveBeenCalledWith(1);
  });

  it('clears errors', async () => {
    vi.mocked(mediaApi.getMediaAssets).mockRejectedValue({ type: 'server' });

    function TestComponent() {
      const { error, fetchMedia, clearErrors } = useMediaAssets(1);
      return (
        <div>
          <button onClick={fetchMedia}>Load</button>
          <button onClick={clearErrors}>Clear</button>
          <span data-testid="error">{error ?? 'null'}</span>
        </div>
      );
    }

    render(<TestComponent />);

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });
    await waitFor(() => {
      expect(screen.getByTestId('error').textContent).toContain('Server');
    });

    await act(async () => {
      fireEvent.click(screen.getByText('Clear'));
    });

    expect(screen.getByTestId('error').textContent).toBe('null');
  });
});
