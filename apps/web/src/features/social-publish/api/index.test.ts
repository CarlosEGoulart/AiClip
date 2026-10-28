import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  publishClip,
  getPublishes,
  getPublishStatus,
} from '../api';
import type {
  PublishResponse,
  PublishListResponse,
  PublishStatusResponse,
} from '../types';

const publishResponse: PublishResponse = {
  publish_id: 7,
  status: 'pending',
  platform: 'youtube',
};

const publishListResponse: PublishListResponse = {
  data: [
    {
      id: 7,
      platform: 'youtube',
      platform_label: 'YouTube',
      status: 'published' as const,
      external_video_id: 'dQw4w9WgXcQ',
      video_url: 'https://youtu.be/dQw4w9WgXcQ',
      title: 'My Clip',
      description: null,
      error_message: null,
      created_at: '2026-10-26T10:00:00.000000Z',
      updated_at: '2026-10-26T10:05:00.000000Z',
    },
  ],
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
};

const publishStatusResponse: PublishStatusResponse = {
  id: 7,
  platform: 'youtube',
  platform_label: 'YouTube',
  status: 'published' as const,
  external_video_id: 'dQw4w9WgXcQ',
  video_url: 'https://youtu.be/dQw4w9WgXcQ',
  title: 'My Clip',
  description: null,
  error_message: null,
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

describe('social-publish API boundary', () => {
  it('publishClip sends credentialed POST with CSRF token', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(202, { data: publishResponse }));

    const result = await publishClip({
      clip_review_id: 1,
      platform: 'youtube',
      title: 'My Clip',
    });

    expect(result).toEqual(publishResponse);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/sanctum/csrf-cookie', expect.objectContaining({
      credentials: 'include',
    }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/social/publish', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      body: JSON.stringify({
        clip_review_id: 1,
        platform: 'youtube',
        title: 'My Clip',
      }),
      headers: expect.objectContaining({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': 'test=',
      }),
    }));
  });

  it('publishClip maps 422 to validation', async () => {
    const errors = { title: ['The title field is required.'] };
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(422, { errors }));
    await expect(publishClip({
      clip_review_id: 1,
      platform: 'youtube',
      title: '',
    })).rejects.toMatchObject({ type: 'validation', errors });
  });

  it('publishClip maps 409 to conflict', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(409, { message: 'A publish already exists for this clip review' }));
    await expect(publishClip({
      clip_review_id: 1,
      platform: 'youtube',
      title: 'My Clip',
    })).rejects.toMatchObject({ type: 'conflict' });
  });

  it('publishClip maps 429 to throttle', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(429));
    await expect(publishClip({
      clip_review_id: 1,
      platform: 'youtube',
      title: 'My Clip',
    })).rejects.toMatchObject({ type: 'throttle' });
  });

  it('publishClip maps 401 to unauthorized', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(401));
    await expect(publishClip({
      clip_review_id: 1,
      platform: 'youtube',
      title: 'My Clip',
    })).rejects.toMatchObject({ type: 'unauthorized' });
  });

  it('publishClip maps network rejection to network error', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await expect(publishClip({
      clip_review_id: 1,
      platform: 'youtube',
      title: 'My Clip',
    })).rejects.toMatchObject({ type: 'network' });
  });

  it('publishClip sends instagram platform field', async () => {
    const instagramResponse: PublishResponse = {
      publish_id: 8,
      status: 'pending',
      platform: 'instagram',
    };
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(202, { data: instagramResponse }));

    const result = await publishClip({
      clip_review_id: 1,
      platform: 'instagram',
      title: 'My Instagram Clip',
    });

    expect(result).toEqual(instagramResponse);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/social/publish', expect.objectContaining({
      method: 'POST',
      body: JSON.stringify({
        clip_review_id: 1,
        platform: 'instagram',
        title: 'My Instagram Clip',
      }),
    }));
  });

  it('getPublishes sends credentialed GET', async () => {
    fetchMock.mockResolvedValueOnce(response(200, publishListResponse));
    const result = await getPublishes();
    expect(result).toEqual(publishListResponse);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/social/publishes', expect.objectContaining({
      credentials: 'include',
    }));
  });

  it('getPublishes maps 404 to unauthorized', async () => {
    fetchMock.mockResolvedValueOnce(response(404));
    await expect(getPublishes()).rejects.toMatchObject({ type: 'unauthorized' });
  });

  it('getPublishStatus returns status data', async () => {
    fetchMock.mockResolvedValueOnce(response(200, { data: publishStatusResponse }));
    const result = await getPublishStatus(7);
    expect(result).toEqual(publishStatusResponse);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/social/publishes/7', expect.objectContaining({
      credentials: 'include',
    }));
  });
});
