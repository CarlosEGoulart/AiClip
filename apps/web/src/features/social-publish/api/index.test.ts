import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  publishClip,
  getPublishes,
  getPublishStatus,
  createPublishCampaign,
  getPublishCampaigns,
  getPublishCampaign,
  cancelPublishCampaign,
  retryPublishCampaign,
} from '../api';
import type {
  PublishResponse,
  PublishListResponse,
  PublishStatusResponse,
  PublishCampaignRequest,
  PublishCampaignResponse,
  CampaignListResponse,
  SocialPublishCampaign,
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

const campaignRequest: PublishCampaignRequest = {
  clip_review_id: 1,
  targets: [
    { platform: 'youtube', title: 'YouTube Title', description: 'Desc' },
    { platform: 'instagram', title: 'Instagram Title' },
  ],
};

const campaignResponse: PublishCampaignResponse = {
  campaign_id: 42,
  status: 'pending',
  target_count: 2,
};

const campaignListResponse: CampaignListResponse = {
  data: [
    {
      id: 42,
      user_id: 1,
      clip_review_id: 1,
      status: 'published',
      status_label: 'Published',
      publishes: [
        {
          id: 7,
          platform: 'youtube',
          campaign_id: 42,
          platform_label: 'YouTube',
          status: 'published',
          external_video_id: 'abc',
          video_url: 'https://youtu.be/abc',
          title: 'YouTube Title',
          description: null,
          error_message: null,
          created_at: '2026-10-26T10:00:00.000000Z',
          updated_at: '2026-10-26T10:00:00.000000Z',
        },
      ],
      created_at: '2026-10-26T10:00:00.000000Z',
      updated_at: '2026-10-26T10:00:00.000000Z',
    },
  ],
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
};

const campaign: SocialPublishCampaign = {
  id: 42,
  user_id: 1,
  clip_review_id: 1,
  status: 'published',
  status_label: 'Published',
  publishes: [],
  created_at: '2026-10-26T10:00:00.000000Z',
  updated_at: '2026-10-26T10:00:00.000000Z',
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

  it('createPublishCampaign sends credentialed POST with CSRF token', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(202, { data: campaignResponse }));

    const result = await createPublishCampaign(campaignRequest);

    expect(result).toEqual(campaignResponse);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/sanctum/csrf-cookie', expect.objectContaining({
      credentials: 'include',
    }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/social/publish/campaign', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      body: JSON.stringify(campaignRequest),
      headers: expect.objectContaining({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': 'test=',
      }),
    }));
  });

  it('createPublishCampaign maps 422 to validation', async () => {
    const errors = { targets: ['All targets must be valid'] };
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(422, { errors }));
    await expect(createPublishCampaign(campaignRequest)).rejects.toMatchObject({ type: 'validation', errors });
  });

  it('getPublishCampaigns sends credentialed GET', async () => {
    fetchMock.mockResolvedValueOnce(response(200, campaignListResponse));
    const result = await getPublishCampaigns();
    expect(result).toEqual(campaignListResponse);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/social/publish/campaigns', expect.objectContaining({
      credentials: 'include',
    }));
  });

  it('getPublishCampaign returns campaign data', async () => {
    fetchMock.mockResolvedValueOnce(response(200, { data: campaign }));
    const result = await getPublishCampaign(42);
    expect(result).toEqual(campaign);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/social/publish/campaigns/42', expect.objectContaining({
      credentials: 'include',
    }));
  });

  it('cancelPublishCampaign sends POST with CSRF token', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(200, { data: { campaign_id: 42, status: 'cancelled' } }));

    const result = await cancelPublishCampaign(42);

    expect(result).toEqual({ campaign_id: 42, status: 'cancelled' });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/social/publish/campaigns/42/cancel', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      headers: expect.objectContaining({
        'X-XSRF-TOKEN': 'test=',
      }),
    }));
  });

  it('retryPublishCampaign sends POST with CSRF token', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(202, { data: { campaign_id: 42, status: 'processing', retried_count: 1 } }));

    const result = await retryPublishCampaign(42);

    expect(result).toEqual({ campaign_id: 42, status: 'processing', retried_count: 1 });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/social/publish/campaigns/42/retry', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      headers: expect.objectContaining({
        'X-XSRF-TOKEN': 'test=',
      }),
    }));
  });
});
