import { act, renderHook, waitFor } from '@testing-library/react';
import { useSocialConnections, useConnectPlatform, useDisconnectConnection } from './hooks';
import type { SocialConnectionsResponse, ConnectResponse, SocialConnection, SocialError } from './types';

const mockConnection: SocialConnection = {
  id: 1,
  platform: 'youtube',
  platform_label: 'YouTube',
  display_name: 'Test Channel',
  avatar_url: 'https://example.com/avatar.jpg',
  channel_url: 'https://youtube.com/@test',
  status: 'connected',
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

// Mock the api module with factory functions
vi.mock('./api', () => ({
  getConnections: vi.fn(),
  connectPlatform: vi.fn(),
  disconnectConnection: vi.fn(),
}));

// Get the mocked functions
import * as socialApi from './api';
const mockGetConnections = socialApi.getConnections as unknown as ReturnType<typeof vi.fn>;
const mockConnectPlatform = socialApi.connectPlatform as unknown as ReturnType<typeof vi.fn>;
const mockDisconnectConnection = socialApi.disconnectConnection as unknown as ReturnType<typeof vi.fn>;

describe('useSocialConnections', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('returns loading initially as false', () => {
    const { result } = renderHook(() => useSocialConnections());
    expect(result.current.loading).toBe(false);
    expect(result.current.connections).toEqual([]);
    expect(result.current.error).toBeNull();
  });

  it('returns connections on success', async () => {
    mockGetConnections.mockResolvedValue(connectionsResponse);

    const { result } = renderHook(() => useSocialConnections());

    await act(async () => {
      await result.current.fetchConnections();
    });

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    await waitFor(() => {
      expect(result.current.connections).toEqual([mockConnection]);
    });

    expect(result.current.error).toBeNull();
  });

  it('returns error on failure', async () => {
    const error: SocialError = { type: 'network', message: 'Network request failed' };
    mockGetConnections.mockRejectedValue(error);

    const { result } = renderHook(() => useSocialConnections());

    await act(async () => {
      await result.current.fetchConnections();
    });

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    await waitFor(() => {
      expect(result.current.error).toBe('Network error. Please check your connection.');
    });

    expect(result.current.connections).toEqual([]);
  });

  it('clearErrors resets error state', async () => {
    const error: SocialError = { type: 'network', message: 'Network request failed' };
    mockGetConnections.mockRejectedValue(error);

    const { result } = renderHook(() => useSocialConnections());

    await act(async () => {
      await result.current.fetchConnections();
    });

    await waitFor(() => {
      expect(result.current.error).not.toBeNull();
    });

    act(() => {
      result.current.clearErrors();
    });

    expect(result.current.error).toBeNull();
  });
});

describe('useConnectPlatform', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('returns pending during mutation', async () => {
    let resolvePromise: (value: ConnectResponse) => void;
    const promise = new Promise<ConnectResponse>((resolve) => {
      resolvePromise = resolve;
    });
    mockConnectPlatform.mockReturnValue(promise);

    const { result } = renderHook(() => useConnectPlatform());

    // Start the connection - synchronous act only runs the sync part
    let connectPromise: Promise<ConnectResponse | null>;
    act(() => {
      connectPromise = result.current.connect('youtube');
    });

    // After the synchronous part of connect runs, isPending should be true
    expect(result.current.isPending).toBe(true);
    expect(result.current.error).toBeNull();

    // Now resolve the promise and wait for completion
    await act(async () => {
      resolvePromise!(connectResponse);
      await connectPromise!;
    });

    expect(result.current.isPending).toBe(false);
  });

  it('returns data on success', async () => {
    mockConnectPlatform.mockResolvedValue(connectResponse);

    const { result } = renderHook(() => useConnectPlatform());

    const connectResult = await act(async () => {
      return await result.current.connect('youtube');
    });

    await waitFor(() => {
      expect(result.current.isPending).toBe(false);
    });

    expect(connectResult).toEqual(connectResponse);
    expect(result.current.error).toBeNull();
  });

  it('returns error on failure', async () => {
    const error: SocialError = { type: 'validation', message: 'Validation failed', errors: { platform: ['Invalid platform'] } };
    mockConnectPlatform.mockRejectedValue(error);

    const { result } = renderHook(() => useConnectPlatform());

    const connectResult = await act(async () => {
      return await result.current.connect('youtube');
    });

    await waitFor(() => {
      expect(result.current.isPending).toBe(false);
    });

    expect(connectResult).toBeNull();
    expect(result.current.error).toBe('Validation failed');
  });
});

describe('useDisconnectConnection', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('returns pending during mutation', async () => {
    let resolvePromise: () => void;
    const promise = new Promise<void>((resolve) => {
      resolvePromise = resolve;
    });
    mockDisconnectConnection.mockReturnValue(promise);

    const { result } = renderHook(() => useDisconnectConnection());

    let disconnectPromise: Promise<boolean>;
    act(() => {
      disconnectPromise = result.current.disconnect(1);
    });

    expect(result.current.isPending).toBe(true);
    expect(result.current.error).toBeNull();

    await act(async () => {
      resolvePromise!();
      await disconnectPromise!;
    });

    expect(result.current.isPending).toBe(false);
  });

  it('returns true on success', async () => {
    mockDisconnectConnection.mockResolvedValue(undefined);

    const { result } = renderHook(() => useDisconnectConnection());

    const success = await act(async () => {
      return await result.current.disconnect(1);
    });

    await waitFor(() => {
      expect(result.current.isPending).toBe(false);
    });

    expect(success).toBe(true);
    expect(result.current.error).toBeNull();
  });

  it('returns false on failure', async () => {
    const error: SocialError = { type: 'unauthorized', message: 'Not found' };
    mockDisconnectConnection.mockRejectedValue(error);

    const { result } = renderHook(() => useDisconnectConnection());

    const success = await act(async () => {
      return await result.current.disconnect(999);
    });

    await waitFor(() => {
      expect(result.current.isPending).toBe(false);
    });

    expect(success).toBe(false);
    expect(result.current.error).toBe('Your session has expired');
  });
});