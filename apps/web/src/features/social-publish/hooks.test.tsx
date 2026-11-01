import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import {
  usePublishClip,
  usePublishes,
  usePublishCampaignCreate,
  usePublishCampaigns,
  useCancelPublishCampaign,
  useRetryPublishCampaign,
  campaignStatusColor,
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
    campaign_id: null,
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
  createPublishCampaign: vi.fn(),
  getPublishCampaigns: vi.fn(),
  getPublishCampaign: vi.fn(),
  cancelPublishCampaign: vi.fn(),
  retryPublishCampaign: vi.fn(),
}));

beforeEach(() => {
  vi.mocked(socialPublishApi.publishClip).mockReset();
  vi.mocked(socialPublishApi.getPublishes).mockReset();
  vi.mocked(socialPublishApi.createPublishCampaign).mockReset();
  vi.mocked(socialPublishApi.getPublishCampaigns).mockReset();
  vi.mocked(socialPublishApi.getPublishCampaign).mockReset();
  vi.mocked(socialPublishApi.cancelPublishCampaign).mockReset();
  vi.mocked(socialPublishApi.retryPublishCampaign).mockReset();
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

describe('usePublishCampaign', () => {
  const mockCampaignResponse = {
    campaign_id: 42,
    status: 'pending',
    target_count: 2,
  };

  it('returns pending during mutation', async () => {
    vi.mocked(socialPublishApi.createPublishCampaign).mockResolvedValue(mockCampaignResponse);

    const { result } = renderHook(() => usePublishCampaignCreate());

    expect(result.current.isPending).toBe(false);

    await act(async () => {
      await result.current.createCampaign({
        clip_review_id: 1,
        targets: [{ platform: 'youtube', title: 'Title' }],
      });
    });

    expect(result.current.isPending).toBe(false);
  });

  it('returns data on success', async () => {
    vi.mocked(socialPublishApi.createPublishCampaign).mockResolvedValue(mockCampaignResponse);

    const { result } = renderHook(() => usePublishCampaignCreate());

    let data = null;
    await act(async () => {
      data = await result.current.createCampaign({
        clip_review_id: 1,
        targets: [{ platform: 'youtube', title: 'Title' }],
      });
    });

    expect(data).toEqual(mockCampaignResponse);
    expect(result.current.error).toBeNull();
  });

  it('returns error on failure', async () => {
    vi.mocked(socialPublishApi.createPublishCampaign).mockRejectedValue({ type: 'validation', errors: {} });

    const { result } = renderHook(() => usePublishCampaignCreate());

    let data = null;
    await act(async () => {
      data = await result.current.createCampaign({
        clip_review_id: 1,
        targets: [{ platform: 'youtube', title: 'Title' }],
      });
    });

    expect(data).toBeNull();
    expect(result.current.error).toBe('Validation failed. Please check your inputs.');
  });
});

describe('usePublishCampaigns', () => {
  const mockCampaigns = [
    {
      id: 1,
      user_id: 1,
      clip_review_id: 1,
      status: 'published',
      status_label: 'Published',
      publishes: [],
      created_at: '2026-10-26T10:00:00.000000Z',
      updated_at: '2026-10-26T10:00:00.000000Z',
    },
  ];

  it('returns loading initially', () => {
    const { result } = renderHook(() => usePublishCampaigns());

    expect(result.current.loading).toBe(true);
    expect(result.current.campaigns).toEqual([]);
  });

  it('returns campaigns on success', async () => {
    vi.mocked(socialPublishApi.getPublishCampaigns).mockResolvedValue({ data: mockCampaigns });

    const { result } = renderHook(() => usePublishCampaigns());

    await act(async () => {
      await result.current.fetchCampaigns();
    });

    expect(result.current.loading).toBe(false);
    expect(result.current.campaigns).toEqual(mockCampaigns);
  });
});

describe('useCancelPublishCampaign', () => {
  it('cancels campaign on success', async () => {
    vi.mocked(socialPublishApi.cancelPublishCampaign).mockResolvedValue({ campaign_id: 1, status: 'cancelled' });

    const { result } = renderHook(() => useCancelPublishCampaign());

    let data = null;
    await act(async () => {
      data = await result.current.cancel(1);
    });

    expect(data).toEqual({ campaign_id: 1, status: 'cancelled' });
    expect(result.current.error).toBeNull();
  });
});

describe('useRetryPublishCampaign', () => {
  it('retries campaign on success', async () => {
    vi.mocked(socialPublishApi.retryPublishCampaign).mockResolvedValue({ campaign_id: 1, status: 'processing', retried_count: 1 });

    const { result } = renderHook(() => useRetryPublishCampaign());

    let data = null;
    await act(async () => {
      data = await result.current.retry(1);
    });

    expect(data).toEqual({ campaign_id: 1, status: 'processing', retried_count: 1 });
  });
});

describe('campaignStatusColor', () => {
  it('returns correct color for each status', () => {
    expect(campaignStatusColor('pending')).toBe('gray');
    expect(campaignStatusColor('processing')).toBe('blue');
    expect(campaignStatusColor('published')).toBe('green');
    expect(campaignStatusColor('partially_published')).toBe('yellow');
    expect(campaignStatusColor('failed')).toBe('red');
    expect(campaignStatusColor('cancelled')).toBe('gray');
  });
});
