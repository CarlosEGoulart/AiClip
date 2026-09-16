import { StrictMode, useEffect } from 'react';
import { act, cleanup, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useMediaAssets } from '../hooks';
import type { MediaAsset, MediaAssetResponse, MediaAssetsResponse } from '../types';

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

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}

const videoFile = () => new File(['content'], 'test-video.mp4', { type: 'video/mp4' });
const mountMedia = (projectId: number | null = 1) => renderHook(
  ({ projectId }) => useMediaAssets(projectId),
  { initialProps: { projectId } },
);

// Separate deferred responses let each operation settle in a deliberately chosen order.
function pendingMediaOperation(operation: 'fetch' | 'upload' | 'delete') {
  const fetch = deferred<MediaAssetsResponse>();
  const upload = deferred<MediaAssetResponse>();
  const deletion = deferred<void>();
  if (operation === 'fetch') vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(fetch.promise);
  if (operation === 'upload') vi.mocked(mediaApi.uploadMediaAsset).mockReturnValueOnce(upload.promise);
  if (operation === 'delete') vi.mocked(mediaApi.deleteMediaAsset).mockReturnValueOnce(deletion.promise);
  return {
    start: (hook: ReturnType<typeof useMediaAssets>) => {
      if (operation === 'fetch') return hook.fetchMedia();
      if (operation === 'upload') return hook.uploadFile(videoFile());
      return hook.deleteMedia(mockMediaAsset.id);
    },
    settle: (outcome: 'success' | 'failure') => {
      if (outcome === 'failure') {
        ({ fetch, upload, delete: deletion })[operation].reject({ type: 'network' });
      } else if (operation === 'fetch') {
        fetch.resolve({ data: mockMediaAssets });
      } else if (operation === 'upload') {
        upload.resolve({ data: mockMediaAsset });
      } else {
        deletion.resolve(undefined);
      }
    },
  };
}

describe('useMediaAssets request ordering', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('M1 retains the uploaded asset after the already-pending empty GET settles', async () => {
    const stale = deferred<MediaAssetsResponse>();
    const upload = deferred<MediaAssetResponse>();
    vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(stale.promise);
    vi.mocked(mediaApi.uploadMediaAsset).mockReturnValueOnce(upload.promise);
    const { result } = renderHook(() => useMediaAssets(1));
    let fetching!: Promise<void>;
    act(() => { fetching = result.current.fetchMedia(); });
    expect(mediaApi.getMediaAssets).toHaveBeenCalledExactlyOnceWith(1);
    expect(result.current.loading).toBe(true);

    const file = new File(['content'], 'test-video.mp4', { type: 'video/mp4' });
    let uploading!: Promise<boolean>;
    act(() => { uploading = result.current.uploadFile(file); });
    expect(mediaApi.uploadMediaAsset).toHaveBeenCalledExactlyOnceWith(1, file);
    await act(async () => {
      upload.resolve({ data: mockMediaAsset });
      expect(await uploading).toBe(true);
    });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);

    // Keep this assertion independent of immediate display/loading coverage.
    await act(async () => {
      stale.resolve({ data: [] });
      await fetching;
    });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
  });

  it('M1 makes the uploaded asset displayable while the superseded GET is still pending', async () => {
    const stale = deferred<MediaAssetsResponse>();
    vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(stale.promise);
    vi.mocked(mediaApi.uploadMediaAsset).mockResolvedValueOnce({ data: mockMediaAsset });
    const { result } = mountMedia();
    let fetching!: Promise<void>;
    act(() => { fetching = result.current.fetchMedia(); });
    expect(result.current.loading).toBe(true);
    await act(async () => { expect(await result.current.uploadFile(videoFile())).toBe(true); });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
    try {
      // MediaList renders only its status while loading is true.
      expect(result.current.loading).toBe(false);
      expect(result.current.uploading).toBe(false);
    } finally {
      await act(async () => { stale.resolve({ data: [] }); await fetching; });
    }
  });

  it('M2 ignores a stale non-empty snapshot instead of replacing or merging uploaded metadata', async () => {
    const stale = deferred<MediaAssetsResponse>();
    vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(stale.promise);
    vi.mocked(mediaApi.uploadMediaAsset).mockResolvedValueOnce({ data: mockMediaAsset });
    const { result } = mountMedia();
    let fetching!: Promise<void>;
    act(() => { fetching = result.current.fetchMedia(); });
    await act(async () => { expect(await result.current.uploadFile(videoFile())).toBe(true); });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
    await act(async () => { stale.resolve({ data: [mockMediaAssets[1]] }); await fetching; });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
  });

  it('M3 does not resurrect a deleted asset from an older GET and retains unrelated assets', async () => {
    const stale = deferred<MediaAssetsResponse>();
    vi.mocked(mediaApi.getMediaAssets)
      .mockResolvedValueOnce({ data: mockMediaAssets }).mockReturnValueOnce(stale.promise);
    vi.mocked(mediaApi.deleteMediaAsset).mockResolvedValueOnce(undefined);
    const { result } = mountMedia();
    await act(async () => { await result.current.fetchMedia(); });
    let fetching!: Promise<void>;
    act(() => { fetching = result.current.fetchMedia(); });
    await act(async () => { expect(await result.current.deleteMedia(1)).toBe(true); });
    expect(result.current.mediaAssets).toEqual([mockMediaAssets[1]]);
    await act(async () => { stale.resolve({ data: mockMediaAssets }); await fetching; });
    expect(result.current.mediaAssets).toEqual([mockMediaAssets[1]]);
    expect(result.current.loading).toBe(false);
  });

  it.each([2, null])('M4 clears previous-project data and error on transition to %s', async projectId => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: mockMediaAssets });
    vi.mocked(mediaApi.uploadMediaAsset).mockRejectedValueOnce({ type: 'network' });
    const { result, rerender } = mountMedia();
    await act(async () => { await result.current.fetchMedia(); });
    await act(async () => { expect(await result.current.uploadFile(videoFile())).toBe(false); });
    expect(result.current.mediaAssets).toEqual(mockMediaAssets);
    expect(result.current.error).toBe('Network error. Please check your connection.');
    rerender({ projectId });
    expect.soft(result.current.mediaAssets).toEqual([]);
    expect.soft(result.current.error).toBeNull();
    expect(result.current.loading).toBe(false);
    expect(result.current.uploading).toBe(false);
  });

  it.each([2, null])('M4 clears previous-project pending flags on transition to %s', async projectId => {
    const fetch = pendingMediaOperation('fetch');
    const upload = pendingMediaOperation('upload');
    const { result, rerender } = mountMedia();
    let fetching!: Promise<void | boolean>;
    let uploading!: Promise<void | boolean>;
    act(() => { fetching = fetch.start(result.current); uploading = upload.start(result.current); });
    expect(result.current.loading).toBe(true);
    expect(result.current.uploading).toBe(true);
    rerender({ projectId });
    try {
      expect.soft(result.current.loading).toBe(false);
      expect.soft(result.current.uploading).toBe(false);
    } finally {
      await act(async () => { fetch.settle('success'); upload.settle('success'); await Promise.all([fetching, uploading]); });
    }
  });

  describe.each([2, null])('M4 late project A work after transition to %s', projectId => {
    it.each([
      ['fetch', 'success'], ['fetch', 'failure'],
      ['upload', 'success'], ['upload', 'failure'],
      ['delete', 'success'], ['delete', 'failure'],
    ] as const)('ignores obsolete %s %s', async (operation, outcome) => {
      vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: mockMediaAssets });
      const { result, rerender } = mountMedia();
      await act(async () => { await result.current.fetchMedia(); });
      const stale = pendingMediaOperation(operation);
      let pending!: Promise<void | boolean>;
      act(() => { pending = stale.start(result.current); });
      rerender({ projectId });
      // Reuse the deleted ID to make a late delete's state update observable.
      const currentAsset = { ...mockMediaAsset, project_id: 2, original_name: 'project-b.mp4' };
      if (projectId !== null) {
        vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: [currentAsset] });
        await act(async () => { await result.current.fetchMedia(); });
        expect(mediaApi.getMediaAssets).toHaveBeenLastCalledWith(2);
      }
      await act(async () => { stale.settle(outcome); await pending; });
      expect.soft(result.current.mediaAssets).toEqual(projectId === null ? [] : [currentAsset]);
      expect.soft(result.current.error).toBeNull();
      expect(result.current.loading).toBe(false);
      expect(result.current.uploading).toBe(false);
    });
  });

  it.each([
    ['fetch', 'success'], ['fetch', 'failure'],
    ['upload', 'success'], ['upload', 'failure'],
  ] as const)('M4 obsolete %s %s cannot end project B pending work', async (operation, outcome) => {
    const stale = pendingMediaOperation(operation);
    const { result, rerender } = mountMedia();
    let obsolete!: Promise<void | boolean>;
    act(() => { obsolete = stale.start(result.current); });
    rerender({ projectId: 2 });
    const fetch = deferred<MediaAssetsResponse>();
    const upload = deferred<MediaAssetResponse>();
    vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(fetch.promise);
    vi.mocked(mediaApi.uploadMediaAsset).mockReturnValueOnce(upload.promise);
    let fetching!: Promise<void>;
    let uploading!: Promise<boolean>;
    const file = videoFile();
    act(() => { fetching = result.current.fetchMedia(); uploading = result.current.uploadFile(file); });
    expect(mediaApi.getMediaAssets).toHaveBeenLastCalledWith(2);
    expect(mediaApi.uploadMediaAsset).toHaveBeenLastCalledWith(2, file);
    await act(async () => { stale.settle(outcome); await obsolete; });
    expect.soft(result.current.loading).toBe(true);
    expect.soft(result.current.uploading).toBe(true);
    expect.soft(result.current.error).toBeNull();
    expect.soft(result.current.mediaAssets).toEqual([]);
    const currentAsset = { ...mockMediaAsset, project_id: 2 };
    await act(async () => { fetch.resolve({ data: [] }); await fetching; });
    await act(async () => { upload.resolve({ data: currentAsset }); expect(await uploading).toBe(true); });
    expect(result.current.mediaAssets).toEqual([currentAsset]);
    expect(result.current.loading).toBe(false);
    expect(result.current.uploading).toBe(false);
  });

  it('M5 accepts only the latest GET when the newer response arrives first', async () => {
    const old = deferred<MediaAssetsResponse>();
    const current = deferred<MediaAssetsResponse>();
    vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise);
    const { result } = mountMedia();
    let older!: Promise<void>;
    let newer!: Promise<void>;
    act(() => { older = result.current.fetchMedia(); newer = result.current.fetchMedia(); });
    await act(async () => { current.resolve({ data: [mockMediaAsset] }); await newer; });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
    await act(async () => { old.resolve({ data: [mockMediaAssets[1]] }); await older; });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
    expect(result.current.loading).toBe(false);
  });

  it.each(['upload', 'delete'] as const)('M5 a fresh authoritative empty GET can clear state after %s', async operation => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: mockMediaAssets });
    const { result } = mountMedia();
    await act(async () => { await result.current.fetchMedia(); });
    if (operation === 'upload') {
      vi.mocked(mediaApi.uploadMediaAsset).mockResolvedValueOnce({ data: { ...mockMediaAsset, id: 3 } });
      await act(async () => { expect(await result.current.uploadFile(videoFile())).toBe(true); });
      expect(result.current.mediaAssets).toHaveLength(3);
    } else {
      vi.mocked(mediaApi.deleteMediaAsset).mockResolvedValueOnce(undefined);
      await act(async () => { expect(await result.current.deleteMedia(1)).toBe(true); });
      expect(result.current.mediaAssets).toEqual([mockMediaAssets[1]]);
    }
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: [] });
    await act(async () => { await result.current.fetchMedia(); });
    expect(mediaApi.getMediaAssets).toHaveBeenCalledTimes(2);
    expect(result.current.mediaAssets).toEqual([]);
    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBeNull();
  });

  it.each(['upload', 'delete'] as const)('M5 a newer same-project GET does not discard a pending %s result', async operation => {
    const pending = pendingMediaOperation(operation);
    const { result } = mountMedia();
    let mutating!: Promise<void | boolean>;
    act(() => { mutating = pending.start(result.current); });
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: operation === 'upload' ? [mockMediaAssets[1]] : mockMediaAssets });
    await act(async () => { await result.current.fetchMedia(); });
    await act(async () => { pending.settle('success'); expect(await mutating).toBe(true); });
    expect(result.current.mediaAssets).toEqual(operation === 'upload' ? mockMediaAssets : [mockMediaAssets[1]]);
    expect(result.current.uploading).toBe(false);
  });

  it('M5 a successful same-project delete does not discard an earlier pending upload', async () => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: [mockMediaAssets[1]] });
    const { result } = mountMedia();
    await act(async () => { await result.current.fetchMedia(); });
    const upload = pendingMediaOperation('upload');
    let uploading!: Promise<void | boolean>;
    act(() => { uploading = upload.start(result.current); });
    vi.mocked(mediaApi.deleteMediaAsset).mockResolvedValueOnce(undefined);
    await act(async () => { expect(await result.current.deleteMedia(2)).toBe(true); });
    await act(async () => { upload.settle('success'); expect(await uploading).toBe(true); });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
    expect(result.current.uploading).toBe(false);
  });

  it('M5 applies returned upload metadata exactly once when a current GET already contains its ID', async () => {
    const upload = deferred<MediaAssetResponse>();
    vi.mocked(mediaApi.uploadMediaAsset).mockReturnValueOnce(upload.promise);
    const { result } = mountMedia();
    let uploading!: Promise<boolean>;
    act(() => { uploading = result.current.uploadFile(videoFile()); });
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: mockMediaAssets });
    await act(async () => { await result.current.fetchMedia(); });
    const returned = { ...mockMediaAsset, updated_at: '2026-09-16T12:00:00.000000Z' };
    await act(async () => { upload.resolve({ data: returned }); expect(await uploading).toBe(true); });
    expect(result.current.mediaAssets).toEqual([returned, mockMediaAssets[1]]);
    expect(result.current.uploading).toBe(false);
  });

  it.each(['upload', 'delete'] as const)('M6 ignores an invalidated GET error after successful %s', async operation => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: [mockMediaAssets[1]] });
    const { result } = mountMedia();
    await act(async () => { await result.current.fetchMedia(); });
    const stale = pendingMediaOperation('fetch');
    let fetching!: Promise<void | boolean>;
    act(() => { fetching = stale.start(result.current); });
    if (operation === 'upload') {
      vi.mocked(mediaApi.uploadMediaAsset).mockResolvedValueOnce({ data: mockMediaAsset });
      await act(async () => { expect(await result.current.uploadFile(videoFile())).toBe(true); });
    } else {
      vi.mocked(mediaApi.deleteMediaAsset).mockResolvedValueOnce(undefined);
      await act(async () => { expect(await result.current.deleteMedia(2)).toBe(true); });
    }
    await act(async () => { stale.settle('failure'); await fetching; });
    expect(result.current.error).toBeNull();
    expect(result.current.mediaAssets).toEqual(operation === 'upload' ? mockMediaAssets : []);
    expect(result.current.loading).toBe(false);
    expect(result.current.uploading).toBe(false);
  });

  it.each(['success', 'failure'] as const)('M6 obsolete GET %s cannot end a newer GET loading state', async outcome => {
    const stale = pendingMediaOperation('fetch');
    const { result } = mountMedia();
    let obsolete!: Promise<void | boolean>;
    act(() => { obsolete = stale.start(result.current); });
    const current = deferred<MediaAssetsResponse>();
    vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(current.promise);
    let fetching!: Promise<void>;
    act(() => { fetching = result.current.fetchMedia(); });
    await act(async () => { stale.settle(outcome); await obsolete; });
    expect.soft(result.current.loading).toBe(true);
    expect.soft(result.current.error).toBeNull();
    expect.soft(result.current.mediaAssets).toEqual([]);
    await act(async () => { current.resolve({ data: [mockMediaAsset] }); await fetching; });
    expect(result.current.loading).toBe(false);
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
    expect(result.current.error).toBeNull();
  });

  it.each(['upload', 'delete'] as const)('M7 failed %s preserves assets, returns false, and permits a subsequent attempt', async operation => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: [mockMediaAssets[1]] });
    const { result } = mountMedia();
    await act(async () => { await result.current.fetchMedia(); });
    const attempt = () => operation === 'upload' ? result.current.uploadFile(videoFile()) : result.current.deleteMedia(2);
    vi.mocked(mediaApi.uploadMediaAsset).mockRejectedValueOnce({ type: 'validation' }).mockResolvedValueOnce({ data: mockMediaAsset });
    vi.mocked(mediaApi.deleteMediaAsset).mockRejectedValueOnce({ type: 'network' }).mockResolvedValueOnce(undefined);
    await act(async () => { expect(await attempt()).toBe(false); });
    expect(result.current.mediaAssets).toEqual([mockMediaAssets[1]]);
    expect(result.current.uploading).toBe(false);
    expect(result.current.error).toBe(operation === 'upload'
      ? 'Validation failed. Please check the file type and size.' : 'Network error. Please check your connection.');
    act(() => { result.current.clearErrors(); });
    expect(result.current.error).toBeNull();
    await act(async () => { expect(await attempt()).toBe(true); });
    expect(result.current.mediaAssets).toEqual(operation === 'upload' ? mockMediaAssets : []);
    expect(result.current.error).toBeNull();
  });

  it('M7 current fetch error preserves assets and clearErrors removes the safe message', async () => {
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: mockMediaAssets }).mockRejectedValueOnce({ type: 'unauthorized' });
    const { result } = mountMedia();
    await act(async () => { await result.current.fetchMedia(); });
    await act(async () => { await result.current.fetchMedia(); });
    expect(result.current.mediaAssets).toEqual(mockMediaAssets);
    expect(result.current.error).toBe('Your session has expired');
    expect(result.current.loading).toBe(false);
    act(() => { result.current.clearErrors(); });
    expect(result.current.error).toBeNull();
  });

  it('M7 null project actions make no requests and mutations return false', async () => {
    const { result } = mountMedia(null);
    await act(async () => {
      await result.current.fetchMedia();
      expect(await result.current.uploadFile(videoFile())).toBe(false);
      expect.soft(await result.current.deleteMedia(1)).toBe(false);
    });
    expect(mediaApi.getMediaAssets).not.toHaveBeenCalled();
    expect(mediaApi.uploadMediaAsset).not.toHaveBeenCalled();
    expect(mediaApi.deleteMediaAsset).not.toHaveBeenCalled();
    expect(result.current.mediaAssets).toEqual([]);
    expect(result.current.error).toBeNull();
    expect(result.current.loading).toBe(false);
    expect(result.current.uploading).toBe(false);
  });

  it.each(['success', 'failure'] as const)('M8 StrictMode cleanup ignores obsolete GET %s and accepts the replay', async outcome => {
    const stale = pendingMediaOperation('fetch');
    const current = deferred<MediaAssetsResponse>();
    vi.mocked(mediaApi.getMediaAssets).mockReturnValueOnce(current.promise);
    const requests: Promise<void>[] = [];
    const { result } = renderHook(() => {
      const media = useMediaAssets(1);
      const { fetchMedia } = media;
      useEffect(() => { requests.push(fetchMedia()); }, [fetchMedia]);
      return media;
    }, { wrapper: StrictMode });
    expect(mediaApi.getMediaAssets).toHaveBeenCalledTimes(2);
    expect(result.current.loading).toBe(true);
    await act(async () => { current.resolve({ data: [mockMediaAssets[1]] }); await requests[1]; });
    expect(result.current.mediaAssets).toEqual([mockMediaAssets[1]]);
    await act(async () => { stale.settle(outcome); await requests[0]; });
    expect.soft(result.current.mediaAssets).toEqual([mockMediaAssets[1]]);
    expect.soft(result.current.error).toBeNull();
    expect(result.current.loading).toBe(false);
    expect(result.current.uploading).toBe(false);
  });

  it.each(['success', 'failure'] as const)('M8 StrictMode cleanup ignores obsolete upload %s without ending replay uploading', async outcome => {
    const stale = pendingMediaOperation('upload');
    const current = deferred<MediaAssetResponse>();
    vi.mocked(mediaApi.uploadMediaAsset).mockReturnValueOnce(current.promise);
    const requests: Promise<boolean>[] = [];
    const { result } = renderHook(() => {
      const media = useMediaAssets(1);
      const { uploadFile } = media;
      useEffect(() => { requests.push(uploadFile(videoFile())); }, [uploadFile]);
      return media;
    }, { wrapper: StrictMode });
    expect(mediaApi.uploadMediaAsset).toHaveBeenCalledTimes(2);
    await act(async () => { stale.settle(outcome); await requests[0]; });
    expect.soft(result.current.mediaAssets).toEqual([]);
    expect.soft(result.current.error).toBeNull();
    expect.soft(result.current.uploading).toBe(true);
    await act(async () => { current.resolve({ data: mockMediaAsset }); expect(await requests[1]).toBe(true); });
    expect(result.current.mediaAssets).toEqual([mockMediaAsset]);
    expect(result.current.uploading).toBe(false);
    expect(result.current.error).toBeNull();
  });

  it.each(['success', 'failure'] as const)('M8 unmounted operations settling with %s cannot alter a new view', async outcome => {
    const fetch = pendingMediaOperation('fetch');
    const upload = pendingMediaOperation('upload');
    const deletion = pendingMediaOperation('delete');
    const previous = mountMedia();
    let pending!: Promise<void | boolean>[];
    act(() => { pending = [fetch.start(previous.result.current), upload.start(previous.result.current), deletion.start(previous.result.current)]; });
    previous.unmount();
    const current = mountMedia(2);
    const asset = { ...mockMediaAsset, project_id: 2 };
    vi.mocked(mediaApi.getMediaAssets).mockResolvedValueOnce({ data: [asset] });
    await act(async () => { await current.result.current.fetchMedia(); });
    await act(async () => {
      fetch.settle(outcome); upload.settle(outcome); deletion.settle(outcome);
      await Promise.all(pending);
    });
    expect(current.result.current.mediaAssets).toEqual([asset]);
    expect(current.result.current.error).toBeNull();
    expect(current.result.current.loading).toBe(false);
    expect(current.result.current.uploading).toBe(false);
  });
});

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
