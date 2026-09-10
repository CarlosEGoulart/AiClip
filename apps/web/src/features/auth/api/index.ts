import type { AuthResponse } from '../types';

async function requestCsrf() {
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
  });
}

async function request<T>(
  url: string,
  options: RequestInit = {}
): Promise<T> {
  await requestCsrf();

  const response = await fetch(url, {
    ...options,
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...options.headers,
    },
  });

  if (response.status === 419) {
    await requestCsrf();
    return request(url, options);
  }

  if (!response.ok) {
    const error = await response.json().catch(() => ({}));
    const status = response.status;

    if (status === 422) {
      throw { type: 'validation', errors: error.errors || {} };
    }

    if (status === 401) {
      throw { type: 'unauthorized' };
    }

    if (status === 429) {
      throw { type: 'throttle' };
    }

    throw { type: 'network' };
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
  });
}

export async function getMe(): Promise<AuthResponse> {
  return request<AuthResponse>('/api/v1/auth/me');
}
