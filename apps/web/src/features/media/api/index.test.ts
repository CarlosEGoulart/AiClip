import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getMediaAssets, uploadMediaAsset, deleteMediaAsset } from '../api';

const mediaAsset = {
  id: 1,
  project_id: 1,
  original_name: 'test-video.mp4',
  mime_type: 'video/mp4',
  size_bytes: 5242880,
  status: 'stored' as const,
  created_at: '2026-09-14T12:00:00.000000Z',
  updated_at: '2026-09-14T12:00:00.000000Z',
};

const response = (status: number, body?: unknown) => new Response(
  body === undefined ? null : JSON.stringify(body), { status },
);
const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  document.cookie = 'XSRF-TOKEN=test%3D; path=/';
});
afterEach(() => {
  for (const cookie of document.cookie.split(';')) {
    document.cookie = `${cookie.split('=')[0].trim()}=; Max-Age=0; path=/`;
  }
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('media API boundary', () => {
  describe('getMediaAssets', () => {
    it('sends credentialed GET for list media assets', async () => {
      fetchMock.mockResolvedValueOnce(response(200, { data: [mediaAsset] }));
      const result = await getMediaAssets(1);
      expect(result).toEqual({ data: [mediaAsset] });
      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(fetchMock).toHaveBeenCalledWith('/api/v1/projects/1/media', expect.objectContaining({
        credentials: 'include',
        headers: expect.objectContaining({ Accept: 'application/json' }),
      }));
    });

    it('maps 401 to unauthorized', async () => {
      fetchMock.mockResolvedValueOnce(response(401));
      await expect(getMediaAssets(1)).rejects.toMatchObject({ type: 'unauthorized' });
    });

    it('maps 429 to throttle', async () => {
      fetchMock.mockResolvedValueOnce(response(429));
      await expect(getMediaAssets(1)).rejects.toMatchObject({ type: 'throttle' });
    });

    it('maps network rejection to network error', async () => {
      fetchMock.mockRejectedValueOnce(new TypeError('offline'));
      await expect(getMediaAssets(1)).rejects.toMatchObject({ type: 'network' });
    });
  });

  describe('uploadMediaAsset', () => {
    it('sends FormData POST after CSRF setup', async () => {
      fetchMock.mockResolvedValueOnce(response(204)); // CSRF
      fetchMock.mockResolvedValueOnce(response(201, { data: mediaAsset }));

      const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
      const result = await uploadMediaAsset(1, file);
      expect(result).toEqual({ data: mediaAsset });
      expect(fetchMock).toHaveBeenCalledTimes(2);
      expect(fetchMock).toHaveBeenNthCalledWith(1, '/sanctum/csrf-cookie', expect.objectContaining({
        credentials: 'include',
      }));
      expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/projects/1/media/upload', expect.objectContaining({
        method: 'POST',
        credentials: 'include',
        body: expect.any(FormData),
      }));
    });

    it('maps 422 to validation', async () => {
      const errors = { file: ['The file field is required.'] };
      fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(422, { errors }));
      const file = new File(['content'], 'test.txt', { type: 'text/plain' });
      await expect(uploadMediaAsset(1, file)).rejects.toMatchObject({ type: 'validation', errors });
    });

    it('maps 413 to file-too-large', async () => {
      fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(413));
      const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
      await expect(uploadMediaAsset(1, file)).rejects.toMatchObject({ type: 'file-too-large' });
    });

    it('maps 500 to server error', async () => {
      fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(500));
      const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
      await expect(uploadMediaAsset(1, file)).rejects.toMatchObject({ type: 'server' });
    });
  });

  describe('deleteMediaAsset', () => {
    it('sends credentialed DELETE after CSRF setup', async () => {
      fetchMock.mockResolvedValueOnce(response(204)); // CSRF
      fetchMock.mockResolvedValueOnce(response(204)); // DELETE

      await deleteMediaAsset(1);
      expect(fetchMock).toHaveBeenCalledTimes(2);
      expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/media/1', expect.objectContaining({
        method: 'DELETE',
        credentials: 'include',
      }));
    });

    it('maps 401 to unauthorized', async () => {
      fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(401));
      await expect(deleteMediaAsset(1)).rejects.toMatchObject({ type: 'unauthorized' });
    });
  });
});
