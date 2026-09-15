import type { MediaAssetsResponse, MediaAssetResponse, MediaError } from '../types';

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
    throw { type: 'network', message: 'Network request failed' } satisfies MediaError;
  }

  if (response.ok) return;

  const status = response.status;
  if (status === 419) {
    throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies MediaError;
  }
  if (status === 401) {
    throw { type: 'unauthorized', message: 'Unauthorized' } satisfies MediaError;
  }
  if (status === 429) {
    throw { type: 'throttle', message: 'Too many requests' } satisfies MediaError;
  }
  throw { type: 'server', message: `Server error: ${status}` } satisfies MediaError;
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

  if (isStateChanging && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json';
    const token = getCsrfToken();
    if (token) {
      headers['X-XSRF-TOKEN'] = token;
    }
  } else if (isStateChanging && options.body instanceof FormData) {
    // For FormData, let the browser set Content-Type with boundary
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
    throw { type: 'network', message: 'Network request failed' } satisfies MediaError;
  }

  if (isStateChanging && response.status === 419) {
    if (retryCount >= 1) {
      throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies MediaError;
    }
    await initCsrf();
    return request<T>(url, options, retryCount + 1);
  }

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    const status = response.status;

    if (status === 422) {
      throw { type: 'validation', errors: error.errors || {} } satisfies MediaError;
    }

    if (status === 413) {
      throw { type: 'file-too-large', message: 'File exceeds maximum upload size' } satisfies MediaError;
    }

    if (status === 401) {
      throw { type: 'unauthorized', message: 'Unauthorized' } satisfies MediaError;
    }

    if (status === 429) {
      throw { type: 'throttle', message: 'Too many requests' } satisfies MediaError;
    }

    if (status === 403) {
      throw { type: 'unauthorized', message: 'Forbidden' } satisfies MediaError;
    }

    throw { type: 'server', message: `Server error: ${status}` } satisfies MediaError;
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}

export async function getMediaAssets(projectId: number): Promise<MediaAssetsResponse> {
  return request<MediaAssetsResponse>(`/api/v1/projects/${projectId}/media`);
}

export async function uploadMediaAsset(projectId: number, file: File): Promise<MediaAssetResponse> {
  const formData = new FormData();
  formData.append('file', file);

  return request<MediaAssetResponse>(`/api/v1/projects/${projectId}/media/upload`, {
    method: 'POST',
    body: formData,
  });
}

export async function deleteMediaAsset(mediaId: number): Promise<void> {
  return request<void>(`/api/v1/media/${mediaId}`, {
    method: 'DELETE',
  });
}
