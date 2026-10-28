import type {
  PublishClipRequest,
  PublishResponse,
  PublishResponseWrapper,
  PublishListResponse,
  PublishStatusResponse,
  PublishStatusResponseWrapper,
  SocialPublishError,
} from '../types';

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
    throw { type: 'network', message: 'Network request failed' } satisfies SocialPublishError;
  }

  if (response.ok) return;

  const status = response.status;
  if (status === 419) {
    throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies SocialPublishError;
  }
  if (status === 401) {
    throw { type: 'unauthorized', message: 'Unauthorized' } satisfies SocialPublishError;
  }
  if (status === 429) {
    throw { type: 'throttle', message: 'Too many requests' } satisfies SocialPublishError;
  }
  throw { type: 'server', message: `Server error: ${status}` } satisfies SocialPublishError;
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
    throw { type: 'network', message: 'Network request failed' } satisfies SocialPublishError;
  }

  if (isStateChanging && response.status === 419) {
    if (retryCount >= 1) {
      throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies SocialPublishError;
    }
    await initCsrf();
    return request<T>(url, options, retryCount + 1);
  }

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    const status = response.status;

    if (status === 422) {
      throw { type: 'validation', errors: error.errors || {} } satisfies SocialPublishError;
    }

    if (status === 401) {
      throw { type: 'unauthorized', message: 'Unauthorized' } satisfies SocialPublishError;
    }

    if (status === 429) {
      throw { type: 'throttle', message: 'Too many requests' } satisfies SocialPublishError;
    }

    if (status === 403) {
      throw { type: 'unauthorized', message: 'Forbidden' } satisfies SocialPublishError;
    }

    if (status === 409) {
      throw { type: 'conflict', message: error.message || 'Conflict' } satisfies SocialPublishError;
    }

    if (status === 404) {
      throw { type: 'unauthorized', message: 'Not found' } satisfies SocialPublishError;
    }

    throw { type: 'server', message: `Server error: ${status}` } satisfies SocialPublishError;
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}

export async function publishClip(
  body: PublishClipRequest,
): Promise<PublishResponse> {
  const response = await request<PublishResponseWrapper>('/api/v1/social/publish', {
    method: 'POST',
    body: JSON.stringify(body),
  });
  return response.data;
}

export async function getPublishes(): Promise<PublishListResponse> {
  return request<PublishListResponse>('/api/v1/social/publishes');
}

export async function getPublishStatus(id: number): Promise<PublishStatusResponse> {
  const response = await request<PublishStatusResponseWrapper>(
    `/api/v1/social/publishes/${id}`,
  );
  return response.data;
}
