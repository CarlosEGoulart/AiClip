import type { SocialConnectionsResponse, ConnectResponse, ConnectionStatusResponse, SocialError } from '../types';

function getCsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  if (!match) return null;
  return decodeURIComponent(match[1]);
}

async function initCsrf(): Promise<void> {
  let response: Response;
  try {
    response = await fetch('/sanctum/csrf-cookie', {
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
    });
  } catch {
    throw { type: 'network', message: 'Network request failed' } satisfies SocialError;
  }

  if (response.ok) return;

  const status = response.status;
  if (status === 419) {
    throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies SocialError;
  }
  if (status === 401) {
    throw { type: 'unauthorized', message: 'Unauthorized' } satisfies SocialError;
  }
  if (status === 429) {
    throw { type: 'throttle', message: 'Too many requests' } satisfies SocialError;
  }
  throw { type: 'server', message: `Server error: ${status}` } satisfies SocialError;
}

async function request<T>(
  url: string,
  options: RequestInit = {},
  retryCount = 0,
): Promise<T> {
  const isStateChanging = options.method === 'POST' || options.method === 'PUT' || options.method === 'PATCH' || options.method === 'DELETE';

  if (isStateChanging && retryCount === 0) {
    await initCsrf();
  }

  const headers: Record<string, string> = {
    'Accept': 'application/json',
    ...(options.headers as Record<string, string> || {}),
  };

  if (isStateChanging) {
    headers['Content-Type'] = 'application/json';
    const token = getCsrfToken();
    if (token) {
      headers['X-XSRF-TOKEN'] = token;
    }
  }

  let response: Response;
  try {
    response = await fetch(url, {
      ...options,
      credentials: 'include',
      headers,
    });
  } catch {
    throw { type: 'network', message: 'Network request failed' } satisfies SocialError;
  }

  if (isStateChanging && response.status === 419) {
    if (retryCount >= 1) {
      throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies SocialError;
    }
    await initCsrf();
    return request<T>(url, options, retryCount + 1);
  }

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    const status = response.status;

    if (status === 422) {
      throw { type: 'validation', errors: error.errors || {} } satisfies SocialError;
    }

    if (status === 401) {
      throw { type: 'unauthorized', message: 'Unauthorized' } satisfies SocialError;
    }

    if (status === 429) {
      throw { type: 'throttle', message: 'Too many requests' } satisfies SocialError;
    }

    if (status === 403) {
      throw { type: 'unauthorized', message: 'Forbidden' } satisfies SocialError;
    }

    if (status === 409) {
      throw { type: 'conflict', message: error.message || 'Conflict' } satisfies SocialError;
    }

    if (status === 404) {
      throw { type: 'unauthorized', message: 'Not found' } satisfies SocialError;
    }

    throw { type: 'server', message: `Server error: ${status}` } satisfies SocialError;
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}

export async function getConnections(): Promise<SocialConnectionsResponse> {
  return request<SocialConnectionsResponse>('/api/v1/social/connections');
}

export async function connectPlatform(platform: string): Promise<ConnectResponse> {
  const response = await request<{ data: ConnectResponse }>(`/api/v1/social/${platform}/connect`, {
    method: 'POST',
    body: JSON.stringify({}),
  });
  return response.data;
}

export async function disconnectConnection(id: number): Promise<void> {
  await request<void>(`/api/v1/social/connections/${id}`, {
    method: 'DELETE',
  });
}

export async function getConnectionStatus(id: number): Promise<ConnectionStatusResponse> {
  const response = await request<{ data: ConnectionStatusResponse }>(`/api/v1/social/connections/${id}/status`);
  return response.data;
}