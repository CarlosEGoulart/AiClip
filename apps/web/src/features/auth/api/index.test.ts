import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getMe, login, logout, register } from './index';

const user = { id: 7, name: 'Mira Vale', email: 'mira@example.test', email_verified_at: null };
const credentials = { email: user.email, password: 'test-password' };
const registration = { ...credentials, name: user.name, password_confirmation: credentials.password };
const response = (status: number, body?: unknown) => new Response(
  body === undefined ? null : JSON.stringify(body), { status },
);
const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  document.cookie = 'OTHER-XSRF-TOKEN=decoy; path=/';
  document.cookie = 'XSRF-TOKEN=initial%2Btoken%3D; path=/';
});
afterEach(() => {
  for (const cookie of document.cookie.split(';')) {
    document.cookie = `${cookie.split('=')[0].trim()}=; Max-Age=0; path=/`;
  }
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('auth HTTP boundary (F1)', () => {
  it.each([
    ['register', () => register(registration), registration, 201],
    ['login', () => login(credentials), credentials, 200],
    ['logout', logout, undefined, 204],
  ] as const)('sends credentialed %s with CSRF token header', async (operation, send, data, status) => {
    const resultResponse = response(status, status === 204 ? undefined : { user });
    fetchMock.mockResolvedValueOnce(resultResponse);
    const pending = send();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenNthCalledWith(1, `/api/v1/auth/${operation}`, expect.objectContaining({
      method: 'POST', credentials: 'include', body: data ? JSON.stringify(data) : undefined,
      headers: expect.objectContaining({
        Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'initial+token=',
      }),
    }));
    if (status === 204) {
      const result = await pending;
      expect(result).toBeUndefined();
    } else {
      expect(await pending).toEqual({ user });
    }
  });

  it('restores me directly with cookies and JSON Accept', async () => {
    fetchMock.mockResolvedValue(response(200, { user }));
    expect(await getMe()).toEqual({ user });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/auth/me', expect.objectContaining({
      credentials: 'include', headers: expect.objectContaining({ Accept: 'application/json' }),
    }));
    expect(fetchMock.mock.calls[0][1]?.method ?? 'GET').toBe('GET');
  });

  it.each([
    [401, 'unauthorized'], [422, 'validation'], [429, 'throttle'], [500, 'server'],
  ] as const)('maps auth %s to %s without retry', async (status, type) => {
    const errors = { email: ['The provided credentials are incorrect.'] };
    fetchMock.mockResolvedValueOnce(response(status, { errors }));
    await expect(login(credentials)).rejects.toMatchObject({ type, ...(status === 422 ? { errors } : {}) });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('maps a non-JSON server response without a parse crash', async () => {
    fetchMock.mockResolvedValueOnce(new Response('<html>unavailable</html>', { status: 500 }));
    await expect(login(credentials)).rejects.toMatchObject({ type: 'server' });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('maps an auth transport rejection to network', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await expect(login(credentials)).rejects.toMatchObject({ type: 'network' });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('stops on CSRF transport failure with typed network error', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await expect(login(credentials)).rejects.toMatchObject({ type: 'network' });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it.each([
    [401, 'unauthorized'], [419, 'csrf'], [429, 'throttle'], [500, 'server'],
  ] as const)('stops on operation %s with typed %s', async (status, type) => {
    if (status === 419) {
      fetchMock.mockResolvedValueOnce(response(419)).mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(419));
    } else {
      fetchMock.mockResolvedValueOnce(response(status));
    }
    await expect(register(registration)).rejects.toMatchObject({ type });
    expect(fetchMock).toHaveBeenCalledTimes(status === 419 ? 3 : 1);
  });

  it('reacquires CSRF and retries operation exactly once retaining the operation', async () => {
    fetchMock
      .mockImplementationOnce(async () => response(419))
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=fresh%2Ftoken%3D; path=/';
        return response(204);
      })
      .mockImplementationOnce(async () => response(201, { user }));
    await register(registration);
    expect(fetchMock).toHaveBeenCalledTimes(3);
    const first = fetchMock.mock.calls[0];
    const retry = fetchMock.mock.calls[2];
    expect(retry[0]).toBe(first[0]);
    const retryHeaders = new Headers(retry[1]?.headers);
    expect(retryHeaders.get('X-XSRF-TOKEN')).toBe('fresh/token=');
    const csrfCalls = fetchMock.mock.calls.filter(([url]) => url === '/sanctum/csrf-cookie');
    expect(csrfCalls).toHaveLength(1);
  });

  it('bounds recovery to ONE retry when every mutation returns 419', async () => {
    fetchMock.mockImplementation(async url => {
      if (url === '/sanctum/csrf-cookie') return response(204);
      return response(419);
    });
    await expect(login(credentials)).rejects.toMatchObject({ type: 'csrf' });
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it('never reads or writes web storage or sends Authorization during the lifecycle', async () => {
    const storage = ['getItem', 'setItem', 'removeItem', 'clear', 'key'].map(method =>
      vi.spyOn(Storage.prototype, method as 'getItem'));
    fetchMock.mockImplementation(async url => response(
      url === '/sanctum/csrf-cookie' || url === '/api/v1/auth/logout' ? 204 : 200,
      url === '/sanctum/csrf-cookie' || url === '/api/v1/auth/logout' ? undefined : { user },
    ));
    await register(registration);
    await login(credentials);
    await getMe();
    await logout();
    for (const [, options] of fetchMock.mock.calls) {
      expect(options?.credentials).toBe('include');
      expect(new Headers(options?.headers).has('Authorization')).toBe(false);
    }
    for (const spy of storage) expect(spy).not.toHaveBeenCalled();
  });
});
