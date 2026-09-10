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
  ] as const)('sends credentialed %s only after CSRF setup with the exact decoded cookie', async (operation, send, data, status) => {
    let release!: (value: Response) => void;
    fetchMock.mockImplementationOnce(() => new Promise(resolve => { release = resolve; }));
    const resultResponse = response(status, status === 204 ? undefined : { user });
    const parse = vi.spyOn(resultResponse, 'json');
    fetchMock.mockResolvedValueOnce(resultResponse);
    const pending = send();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/sanctum/csrf-cookie', expect.objectContaining({
      credentials: 'include', headers: expect.objectContaining({ Accept: 'application/json' }),
    }));
    release(response(204));
    expect(await pending).toEqual(status === 204 ? undefined : { user });
    expect(fetchMock).toHaveBeenNthCalledWith(2, `/api/v1/auth/${operation}`, expect.objectContaining({
      method: 'POST', credentials: 'include', body: data ? JSON.stringify(data) : undefined,
      headers: expect.objectContaining({
        Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': 'initial+token=',
      }),
    }));
    if (status === 204) expect(parse).not.toHaveBeenCalled();
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
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(status, { errors }));
    await expect(login(credentials)).rejects.toMatchObject({ type, ...(status === 422 ? { errors } : {}) });
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('maps a non-JSON server response without a parse crash', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(new Response('<html>unavailable</html>', { status: 500 }));
    await expect(login(credentials)).rejects.toMatchObject({ type: 'server' });
  });

  it('maps an auth transport rejection to network', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockRejectedValueOnce(new TypeError('offline'));
    await expect(login(credentials)).rejects.toMatchObject({ type: 'network' });
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it.each(['initial', 'refresh'] as const)('stops on %s CSRF transport failure with a typed network error', async phase => {
    if (phase === 'refresh') fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(419));
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await expect(login(credentials)).rejects.toMatchObject({ type: 'network' });
    expect(fetchMock).toHaveBeenCalledTimes(phase === 'initial' ? 1 : 3);
  });

  it.each([
    [401, 'unauthorized'], [419, 'csrf'], [429, 'throttle'], [500, 'server'],
  ] as const)('stops on CSRF setup %s with typed %s', async (status, type) => {
    fetchMock.mockResolvedValueOnce(response(status));
    await expect(register(registration)).rejects.toMatchObject({ type });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('stops when recovery CSRF returns 500 without a second mutation', async () => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(419)).mockResolvedValueOnce(response(500));
    await expect(logout()).rejects.toMatchObject({ type: 'server' });
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it.each([
    ['register', () => register(registration)], ['login', () => login(credentials)], ['logout', logout],
  ] as const)('reacquires CSRF and retries %s exactly once retaining the operation', async (_operation, send) => {
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(419))
      .mockImplementationOnce(async () => {
        document.cookie = 'XSRF-TOKEN=fresh%2Ftoken%3D; path=/';
        return response(204);
      }).mockResolvedValueOnce(response(204));
    await send();
    expect(fetchMock).toHaveBeenCalledTimes(4);
    const first = fetchMock.mock.calls[1];
    const retry = fetchMock.mock.calls[3];
    expect(retry[0]).toBe(first[0]);
    expect(retry[1]).toEqual({ ...first[1], headers: {
      ...first[1]?.headers, 'X-XSRF-TOKEN': 'fresh/token=',
    } });
    expect(fetchMock.mock.calls.filter(([url]) => url === '/sanctum/csrf-cookie')).toHaveLength(2);
  });

  it('bounds recovery to ONE retry when every mutation returns 419', async () => {
    fetchMock.mockImplementation(async url => response(url === '/sanctum/csrf-cookie' ? 204 : 419));
    await expect(login(credentials)).rejects.toMatchObject({ type: 'csrf' });
    expect(fetchMock).toHaveBeenCalledTimes(4);
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
