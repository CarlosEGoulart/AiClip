import { StrictMode } from 'react';
import { act, cleanup, render, renderHook, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthProvider, useAuth } from './index';
import * as api from '../api';
import type { AuthResponse } from '../types';

vi.mock('../api', () => ({ getMe: vi.fn(), login: vi.fn(), register: vi.fn(), logout: vi.fn() }));
const user = { id: 7, name: 'Mira Vale', email: 'mira@example.test', email_verified_at: null };
const credentials = { email: user.email, password: 'test-password' };
function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
}
beforeEach(() => { vi.resetAllMocks(); vi.mocked(api.getMe).mockRejectedValue({ type: 'unauthorized' }); });
afterEach(cleanup);
const mount = () => renderHook(useAuth, { wrapper: AuthProvider });

function AuthProbe() {
  const { user: currentUser, state, errorMessage } = useAuth();
  return (
    <div>
      <span data-testid="state">{state}</span>
      <span data-testid="user">{currentUser ? JSON.stringify(currentUser) : 'null'}</span>
      <span data-testid="error">{errorMessage ?? 'null'}</span>
    </div>
  );
}

describe('auth provider state (F2)', () => {
  it('checks the session before restoring the sanitized identity', async () => {
    const pending = deferred<AuthResponse>();
    vi.mocked(api.getMe).mockReturnValue(pending.promise);
    const { result } = mount();
    expect(result.current.state).toBe('checking-session');
    expect(result.current.user).toBeNull();
    await act(async () => pending.resolve({ user }));
    expect(result.current.state).toBe('authenticated');
    expect(result.current.user).toEqual(user);
  });

  it('treats first-visit 401 as guest without an expiration alarm', async () => {
    const { result } = mount();
    await waitFor(() => expect(result.current.state).toBe('guest'));
    expect(result.current.errorMessage).toBeNull();
  });

  it.each([['network', 'network-error'], ['server', 'server-error']] as const)(
    'keeps bootstrap %s distinguishable from confirmed guest', async (type, state) => {
      vi.mocked(api.getMe).mockRejectedValue({ type });
      const { result } = mount();
      await waitFor(() => expect(result.current.state).toBe(state));
      expect(result.current.errorMessage).toBeTruthy();
      expect(result.current.user).toBeNull();
    },
  );

  it.each(['login', 'register'] as const)('%s locks repeated submissions until settled then authenticates', async operation => {
    const pending = deferred<AuthResponse>();
    vi.mocked(api[operation]).mockReturnValue(pending.promise);
    const { result } = mount();
    await waitFor(() => expect(result.current.state).toBe('guest'));
    const data = { ...credentials, name: user.name, password_confirmation: credentials.password };
    let first!: Promise<void>;
    act(() => {
      first = result.current[operation](data);
      void result.current[operation](data);
    });
    expect(result.current.state).toBe(operation === 'login' ? 'logging-in' : 'registering');
    expect(api[operation]).toHaveBeenCalledTimes(1);
    await act(async () => { pending.resolve({ user }); await first; });
    expect(result.current.user).toEqual(user);
    expect(result.current.state).toBe('authenticated');
  });

  it.each(['network', 'csrf', 'throttle', 'server'] as const)('retains identity on %s logout failure and permits retry', async type => {
    vi.mocked(api.getMe).mockResolvedValue({ user });
    const pending = deferred<void>();
    vi.mocked(api.logout).mockReturnValueOnce(pending.promise).mockResolvedValueOnce(undefined);
    const { result } = mount();
    await waitFor(() => expect(result.current.state).toBe('authenticated'));
    let first!: Promise<void>;
    act(() => { first = result.current.logout(); void result.current.logout(); });
    expect(result.current.state).toBe('logging-out');
    expect(result.current.user).toEqual(user);
    expect(api.logout).toHaveBeenCalledTimes(1);
    await act(async () => { pending.reject({ type }); await first; });
    expect(result.current.user).toEqual(user);
    expect(result.current.errorMessage).toBeTruthy();
    await act(async () => result.current.logout());
    expect(result.current.user).toBeNull();
    expect(result.current.state).toBe('guest');
    expect(result.current.errorMessage).toBeNull();
  });

  it('clears an established identity on logout 401 and communicates expiration', async () => {
    vi.mocked(api.getMe).mockResolvedValue({ user });
    vi.mocked(api.logout).mockRejectedValue({ type: 'unauthorized' });
    const { result } = mount();
    await waitFor(() => expect(result.current.user).toEqual(user));
    await act(async () => result.current.logout());
    expect(result.current.user).toBeNull();
    expect(result.current.state).toBe('session-expired');
    expect(result.current.errorMessage).toMatch(/expired/i);
  });

  it('stale StrictMode bootstrap cannot overwrite a newer valid bootstrap', async () => {
    const stale = deferred<AuthResponse>();
    const secondUser = { ...user, id: 99, name: 'Second Load' };
    vi.mocked(api.getMe).mockReturnValueOnce(stale.promise).mockResolvedValueOnce({ user: secondUser });

    render(
      <StrictMode>
        <AuthProvider>
          <AuthProbe />
        </AuthProvider>
      </StrictMode>,
    );

    expect(api.getMe).toHaveBeenCalledTimes(2);

    await waitFor(() => expect(screen.getByTestId('user').textContent).toContain('Second Load'));
    expect(screen.getByTestId('state').textContent).toBe('authenticated');

    await act(async () => stale.resolve({ user: { ...user, name: 'Stale Winner' } }));
    expect(screen.getByTestId('user').textContent).toContain('Second Load');
    expect(screen.getByTestId('state').textContent).toBe('authenticated');
  });

  it('stale StrictMode bootstrap failure cannot turn an authenticated user into guest', async () => {
    const stale = deferred<AuthResponse>();
    vi.mocked(api.getMe).mockReturnValueOnce(stale.promise).mockResolvedValueOnce({ user });

    render(
      <StrictMode>
        <AuthProvider>
          <AuthProbe />
        </AuthProvider>
      </StrictMode>,
    );

    expect(api.getMe).toHaveBeenCalledTimes(2);

    await waitFor(() => expect(screen.getByTestId('state').textContent).toBe('authenticated'));
    expect(screen.getByTestId('user').textContent).toContain('Mira Vale');

    await act(async () => stale.reject({ type: 'unauthorized' }));
    expect(screen.getByTestId('state').textContent).toBe('authenticated');
    expect(screen.getByTestId('user').textContent).toContain('Mira Vale');
  });

  it('newer login cannot be overwritten by an old bootstrap', async () => {
    const stale = deferred<AuthResponse>();
    vi.mocked(api.getMe).mockReturnValue(stale.promise);
    vi.mocked(api.login).mockResolvedValue({ user });

    const { result } = mount();
    expect(result.current.state).toBe('checking-session');
    await act(async () => result.current.login(credentials));
    expect(result.current.user).toEqual(user);
    expect(result.current.state).toBe('authenticated');

    await act(async () => stale.resolve({ user: { ...user, name: 'Stale Overwrite' } }));
    expect(result.current.user).toEqual(user);
    expect(result.current.user!.name).toBe('Mira Vale');
  });

  it('unmounted StrictMode provider ignores pending bootstrap completions', async () => {
    const pending = deferred<AuthResponse>();
    vi.mocked(api.getMe).mockReturnValue(pending.promise);

    const { result, unmount } = mount();
    const saved = result.current;
    unmount();

    await act(async () => pending.resolve({ user }));
    await act(async () => saved.login(credentials));
    expect(api.login).not.toHaveBeenCalled();
  });

  it('does not let stale bootstrap overwrite a newer successful login', async () => {
    const stale = deferred<AuthResponse>();
    vi.mocked(api.getMe).mockReturnValue(stale.promise);
    vi.mocked(api.login).mockResolvedValue({ user });
    const { result } = mount();
    await act(async () => result.current.login(credentials));
    await act(async () => stale.reject({ type: 'unauthorized' }));
    expect(result.current.user).toEqual(user);
    expect(result.current.state).toBe('authenticated');
  });

  it('ignores pending completion and saved actions after provider unmount', async () => {
    const pending = deferred<AuthResponse>();
    vi.mocked(api.getMe).mockReturnValue(pending.promise);
    const { result, unmount } = mount();
    const saved = result.current;
    unmount();
    await act(async () => pending.resolve({ user }));
    await act(async () => saved.login(credentials));
    expect(api.login).not.toHaveBeenCalled();
    expect(result.current.state).toBe('checking-session');
  });
});
