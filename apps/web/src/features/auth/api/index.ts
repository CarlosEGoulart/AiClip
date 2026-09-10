import type { AuthResponse } from '../types';

export type AuthErrorType =
  | 'validation'
  | 'unauthorized'
  | 'throttle'
  | 'csrf'
  | 'network'
  | 'server';

export interface AuthError {
  type: AuthErrorType;
  errors?: Record<string, string[]>;
  message?: string;
}

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
    throw { type: 'network', message: 'Network request failed' } satisfies AuthError;
  }

  if (response.ok) return;

  const status = response.status;
  if (status === 419) {
    throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies AuthError;
  }
  if (status === 401) {
    throw { type: 'unauthorized', message: 'Unauthorized' } satisfies AuthError;
  }
  if (status === 429) {
    throw { type: 'throttle', message: 'Too many requests' } satisfies AuthError;
  }
  throw { type: 'server', message: `Server error: ${status}` } satisfies AuthError;
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
    throw { type: 'network', message: 'Network request failed' } satisfies AuthError;
  }

  if (isStateChanging && response.status === 419) {
    if (retryCount >= 1) {
      throw { type: 'csrf', message: 'CSRF token validation failed' } satisfies AuthError;
    }
    await initCsrf();
    return request<T>(url, options, retryCount + 1);
  }

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    const status = response.status;

    if (status === 422) {
      throw { type: 'validation', errors: error.errors || {} } satisfies AuthError;
    }

    if (status === 401) {
      throw { type: 'unauthorized', message: 'Unauthorized' } satisfies AuthError;
    }

    if (status === 429) {
      throw { type: 'throttle', message: 'Too many requests' } satisfies AuthError;
    }

    if (status === 403) {
      throw { type: 'unauthorized', message: 'Forbidden' } satisfies AuthError;
    }

    throw { type: 'server', message: `Server error: ${status}` } satisfies AuthError;
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return response.json();
}

export async function register(data: {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}): Promise<AuthResponse> {
  return request<AuthResponse>('/api/v1/auth/register', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function login(data: {
  email: string;
  password: string;
}): Promise<AuthResponse> {
  return request<AuthResponse>('/api/v1/auth/login', {
    method: 'POST',
    body: JSON.stringify(data),
  });
}

export async function logout(): Promise<void> {
  return request<void>('/api/v1/auth/logout', {
    method: 'POST',
    body: undefined,
  });
}

export async function getMe(): Promise<AuthResponse> {
  return request<AuthResponse>('/api/v1/auth/me');
}
