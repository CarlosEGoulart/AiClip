import type { ProjectsResponse, ProjectResponse, ProjectError, ProjectErrorType } from '../types';
import type { AuthError } from '../../auth/api';

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
    throw { type: 'network', message: 'Network request failed' } satisfies ProjectError;
  }

  if (response.ok) return;

  const status = response.status;
  if (status === 419) {
    throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies ProjectError;
  }
  if (status === 401) {
    throw { type: 'unauthorized', message: 'Unauthorized' } satisfies ProjectError;
  }
  if (status === 429) {
    throw { type: 'throttle', message: 'Too many requests' } satisfies ProjectError;
  }
  throw { type: 'server', message: `Server error: ${status}` } satisfies ProjectError;
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
    throw { type: 'network', message: 'Network request failed' } satisfies ProjectError;
  }

  if (isStateChanging && response.status === 419) {
    if (retryCount >= 1) {
      throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies ProjectError;
    }
    await initCsrf();
    return request<T>(url, options, retryCount + 1);
  }

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    const status = response.status;

    if (status === 422) {
      throw { type: 'validation', errors: error.errors || {} } satisfies ProjectError;
    }

    if (status === 401) {
      throw { type: 'unauthorized', message: 'Unauthorized' } satisfies ProjectError;
    }

    if (status === 429) {
      throw { type: 'throttle', message: 'Too many requests' } satisfies ProjectError;
    }

    if (status === 403) {
      throw { type: 'unauthorized', message: 'Forbidden' } satisfies ProjectError;
    }

    throw { type: 'server', message: `Server error: ${status}` } satisfies ProjectError;
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}

export async function getProjects(): Promise<ProjectsResponse> {
  return request<ProjectsResponse>('/api/v1/projects');
}

export async function createProject(data: { name: string }): Promise<ProjectResponse> {
  return request<ProjectResponse>('/api/v1/projects', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function deleteProject(id: number): Promise<void> {
  return request<void>(`/api/v1/projects/${id}`, {
    method: 'DELETE',
  });
}
