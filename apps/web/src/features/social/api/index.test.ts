import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getConnections, connectPlatform, disconnectConnection, getConnectionStatus } from '../api';
import type { SocialConnectionsResponse, ConnectResponse, ConnectionStatusResponse } from '../types';

const mockConnection = {
  id: 1,
  platform: 'youtube' as const,
  platform_label: 'YouTube',
  display_name: 'Test Channel',
  avatar_url: 'https://example.com/avatar.jpg',
  channel_url: 'https://youtube.com/@test',
  status: 'connected' as const,
  last_error: null,
  created_at: '2026-10-22T10:00:00.000000Z',
  updated_at: '2026-10-22T10:00:00.000000Z',
};

const connectionsResponse: SocialConnectionsResponse = {
  data: [mockConnection],
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, from: 1, last_page: 1, per_page: 15, to: 1, total: 1 },
};

const connectResponse: ConnectResponse = {
  authorization_url: 'https://accounts.google.com/o/oauth2/v2/auth?state=abc123',
  state: 'abc123',
  connection_id: 5,
};

const statusResponse: ConnectionStatusResponse = {
  id: 1,
  status: 'connected',
  last_error: null,
  access_token_expires_at: '2026-10-23T10:00:00.000000Z',
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

describe('social API boundary', () => {
  it('sends credentialed GET for list connections', async () => {
    fetchMock.mockResolvedValueOnce(response(200, connectionsResponse));
    const result = await getConnections();
    expect(result).toEqual(connectionsResponse);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/social/connections', expect.objectContaining({
      credentials: 'include',
      headers: expect.objectContaining({ Accept: 'application/json' }),
    }));
  });

  it('maps 401 to unauthorized', async () => {
    fetchMock.mockResolvedValueOnce(response(401));
    await expect(getConnections()).rejects.toMatchObject({ type: 'unauthorized' });
  });

  it('maps 422 to validation', async () => {
    const errors = { platform: ['The platform field is required.'] };
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(422, { errors }));
    await expect(connectPlatform('youtube')).rejects.toMatchObject({ type: 'validation', errors });
  });

  it('maps network rejection to network error', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await expect(getConnections()).rejects.toMatchObject({ type: 'network' });
  });

  it('maps 429 to throttle', async () => {
    fetchMock.mockResolvedValueOnce(response(429));
    await expect(getConnections()).rejects.toMatchObject({ type: 'throttle' });
  });

  it('maps 409 to conflict', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(409, { message: 'Connection already exists' }));
    await expect(connectPlatform('youtube')).rejects.toMatchObject({ type: 'conflict' });
  });

  it('connectPlatform returns authorization data', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(200, { data: connectResponse }));

    const result = await connectPlatform('youtube');
    expect(result).toEqual(connectResponse);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/sanctum/csrf-cookie', expect.objectContaining({
      credentials: 'include',
    }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/social/youtube/connect', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      body: JSON.stringify({}),
      headers: expect.objectContaining({
        Accept: 'application/json',
        'Content-Type': 'application/json',
      }),
    }));
  });

  it('connectPlatform includes CSRF token', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(200, { data: connectResponse }));

    await connectPlatform('youtube');

    const secondCall = fetchMock.mock.calls[1];
    expect(secondCall[1]).toMatchObject({
      headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'test=' }),
    });
  });

  it('disconnectConnection returns void on success', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(204));

    await disconnectConnection(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/social/connections/1', expect.objectContaining({
      method: 'DELETE',
      credentials: 'include',
    }));
  });

  it('disconnectConnection handles not found', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(404));
    await expect(disconnectConnection(999)).rejects.toMatchObject({ type: 'unauthorized' });
  });

  it('disconnectConnection includes CSRF token', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(204));

    await disconnectConnection(1);

    const secondCall = fetchMock.mock.calls[1];
    expect(secondCall[1]).toMatchObject({
      headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'test=' }),
    });
  });

  it('getConnectionStatus returns status data', async () => {
    fetchMock.mockResolvedValueOnce(response(200, { data: statusResponse }));

    const result = await getConnectionStatus(1);
    expect(result).toEqual(statusResponse);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/social/connections/1/status', expect.objectContaining({
      credentials: 'include',
      headers: expect.objectContaining({ Accept: 'application/json' }),
    }));
  });
});