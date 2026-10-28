import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import {
  usePublishClip,
  usePublishes,
} from './hooks';
import * as socialPublishApi from './api';
import type { PublishResponse, SocialPublish } from './types';

const mockPublishResponse: PublishResponse = {
  publish_id: 7,
  status: 'pending',
  platform: 'youtube',
};

const mockInstagramPublishResponse: PublishResponse = {
  publish_id: 8,
  status: 'pending',
  platform: 'instagram',
};

const mockPublishes: SocialPublish[] = [
  {
    id: 7,
    platform: 'youtube',
    platform_label: 'YouTube',
    status: 'published',
    external_video_id: 'dQw4w9WgXcQ',
    video_url: 'https://youtu.be/dQw4w9WgXcQ',
    title: 'My Clip',
    description: null,
    error_message: null,
    created_at: '2026-10-26T10:00:00.000000Z',
    updated_at: '2026-10-26T10:05:00.000000Z',
  },
];

vi.mock('./api', () => ({
  publishClip: vi.fn(),
  getPublishes: vi.fn(),
}));

beforeEach(() => {
  vi.mocked(socialPublishApi.publishClip).mockReset();
  vi.mocked(socialPublishApi.getPublishes).mockReset();
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('usePublishClip', () => {
  it('returns pending during mutation', async () => {
    vi.mocked(socialPublishApi.publishClip).mockResolvedValue(mockPublishResponse);

    const { result } = renderHook(() => usePublishClip());

    expect(result.current.isPending).toBe(false);

    await act(async () => {
      await result.current.publish({
        clip_review_id: 1,
        platform: 'youtube',
        title: 'Test Clip',
      });
    });

    expect(result.current.isPending).toBe(false);
  });

  it('returns data on success', async () => {
    vi.mocked(socialPublishApi.publishClip).mockResolvedValue(mockPublishResponse);

    const { result } = renderHook(() => usePublishClip());

    let data: PublishResponse | null = null;
    await act(async () => {
      data = await result.current.publish({
        clip_review_id: 1,
        platform: 'youtube',
        title: 'Test Clip',
      });
    });

    expect(data).toEqual(mockPublishResponse);
    expect(result.current.isPending).toBe(false);
    expect(result.current.error).toBeNull();
  });

  it('returns error on failure', async () => {
    vi.mocked(socialPublishApi.publishClip).mockRejectedValue({ type: 'conflict' });

    const { result } = renderHook(() => usePublishClip());

    let data: PublishResponse | null = null;
    await act(async () => {
      data = await result.current.publish({
        clip_review_id: 1,
        platform: 'youtube',
        title: 'Test Clip',
      });
    });

    expect(data).toBeNull();
    expect(result.current.isPending).toBe(false);
    expect(result.current.error).toBe('A publish already exists for this clip.');
  });

  it('publishes to instagram platform', async () => {
    vi.mocked(socialPublishApi.publishClip).mockResolvedValue(mockInstagramPublishResponse);

    const { result } = renderHook(() => usePublishClip());

    let data: PublishResponse | null = null;
    await act(async () => {
      data = await result.current.publish({
        clip_review_id: 1,
        platform: 'instagram',
        title: 'Instagram Clip',
      });
    });

    expect(data).toEqual(mockInstagramPublishResponse);
    expect(data?.platform).toBe('instagram');
    expect(result.current.isPending).toBe(false);
    expect(result.current.error).toBeNull();
  });
});

describe('usePublishes', () => {
  it('returns loading initially', () => {
    const { result } = renderHook(() => usePublishes());

    expect(result.current.loading).toBe(true);
    expect(result.current.publishes).toEqual([]);
    expect(result.current.error).toBeNull();
  });

  it('returns publishes on success', async () => {
    vi.mocked(socialPublishApi.getPublishes).mockResolvedValue({ data: mockPublishes, links: {}, meta: {} });

    const { result } = renderHook(() => usePublishes());

    await act(async () => {
      await result.current.fetchPublishes();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.publishes).toEqual(mockPublishes);
    expect(result.current.error).toBeNull();
  });

  it('returns error on failure', async () => {
    vi.mocked(socialPublishApi.getPublishes).mockRejectedValue({ type: 'network', message: 'Network error' });

    const { result } = renderHook(() => usePublishes());

    await act(async () => {
      await result.current.fetchPublishes();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.publishes).toEqual([]);
    expect(result.current.error).toBe('Network error. Please check your connection.');
  });
});
