import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from '../App';

const user = { id: 7, name: 'Mira Vale', email: 'mira@example.test', email_verified_at: null };
const json = (status: number, body?: unknown) => new Response(body === undefined ? null : JSON.stringify(body), { status });
const fetchMock = vi.fn<typeof fetch>();
let me: () => Promise<Response>;
let mutation: () => Promise<Response>;
beforeEach(() => {
  me = async () => json(401);
  mutation = async () => json(200, { user });
  fetchMock.mockReset().mockImplementation(async (url) => {
    if (url === '/api/v1/health') return json(200, { status: 'ok', database: 'connected' });
    if (url === '/sanctum/csrf-cookie') {
      document.cookie = 'XSRF-TOKEN=test%3D; path=/';
      return json(204);
    }
    if (url === '/api/v1/auth/me') return me();
    return mutation();
  });
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => {
  cleanup();
  document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/';
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});
async function guest(register = false) {
  render(<App />);
  await screen.findByRole('button', { name: 'Sign In' });
  if (register) fireEvent.click(screen.getByRole('button', { name: 'Create one' }));
}
function fill(register = false) {
  if (register) fireEvent.change(screen.getByLabelText('Name'), { target: { value: user.name } });
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: user.email } });
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'test-password' } });
  if (register) fireEvent.change(screen.getByLabelText('Confirm Password'), { target: { value: 'test-password' } });
}
function submit(register = false) {
  const button = screen.getByRole('button', { name: register ? 'Create Account' : 'Sign In' });
  // Exercise the form submission path shared by Enter and the native submit button.
  fireEvent.submit(button.closest('form')!);
}
function mutations() {
  return fetchMock.mock.calls.filter(([, options]) => options?.method === 'POST');
}

describe('root provider, forms and HTTP integration (F1–F3)', () => {
  it('announces checking-session, restores identity at the application root and keeps one health region', async () => {
    let resolve!: (value: Response) => void;
    me = () => new Promise(yes => { resolve = yes; });
    render(<App />);
    expect(screen.getByText(/checking.*session/i)).toHaveAttribute('role', 'status');
    expect(screen.queryByRole('button', { name: 'Sign In' })).not.toBeInTheDocument();
    await act(async () => resolve(json(200, { user })));
    expect(await screen.findByText(user.email)).toBeVisible();
    expect(screen.getAllByRole('main')).toHaveLength(1);
    const health = screen.getByRole('region', { name: 'System health' });
    expect(await within(health).findByText('Database: connected')).toBeVisible();
    expect(fetchMock.mock.calls.filter(([url]) => url === '/api/v1/health')).toHaveLength(1);
  });

  it.each(['network', 'server'] as const)('offers session retry after startup %s and restores user', async type => {
    me = async () => { if (type === 'network') throw new TypeError('offline'); return json(500); };
    render(<App />);
    expect(await screen.findByRole('alert')).toHaveTextContent(type === 'network' ? /connection/i : /server/i);
    me = async () => json(200, { user });
    fireEvent.click(screen.getByRole('button', { name: /retry.*session/i }));
    expect(await screen.findByText(user.email)).toBeVisible();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it.each([false, true])('submits exact %s form data, locks pending controls and authenticates', async register => {
    let resolve!: (value: Response) => void;
    mutation = () => new Promise(yes => { resolve = yes; });
    await guest(register);
    fill(register);
    const form = screen.getByLabelText('Email').closest('form')!;
    submit(register);
    await waitFor(() => expect(mutations()).toHaveLength(1));
    expect(form).toHaveAttribute('aria-busy', 'true');
    for (const input of form.querySelectorAll('input')) expect(input).toBeDisabled();
    expect(screen.getByRole('button', { name: register ? /creating account/i : /signing in/i })).toBeDisabled();
    expect(screen.getByRole('status', { name: /authentication progress/i })).toHaveTextContent(register ? /creating account/i : /signing in/i);
    expect(screen.getByRole('button', { name: register ? 'Sign in' : 'Create one' })).toBeDisabled();
    fireEvent.submit(form);
    fireEvent.submit(form);
    expect(mutations()).toHaveLength(1);
    expect(mutations()[0][0]).toBe(`/api/v1/auth/${register ? 'register' : 'login'}`);
    expect(JSON.parse(mutations()[0][1]!.body as string)).toEqual({
      email: user.email, password: 'test-password',
      ...(register ? { name: user.name, password_confirmation: 'test-password' } : {}),
    });
    await act(async () => resolve(json(register ? 201 : 200, { user })));
    expect(await screen.findByText(user.email)).toBeVisible();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it.each([false, true])('provides required, named, labeled autocomplete semantics for register=%s', async register => {
    await guest(register);
    for (const [label, name, type, autoComplete] of [
      ...(register ? [['Name', 'name', 'text', 'name']] : []),
      ['Email', 'email', 'email', 'email'],
      ['Password', 'password', 'password', register ? 'new-password' : 'current-password'],
      ...(register ? [['Confirm Password', 'password_confirmation', 'password', 'new-password']] : []),
    ]) {
      const field = screen.getByLabelText(label);
      expect(field).toBeRequired();
      expect(field).toHaveAttribute('name', name);
      expect(field).toHaveAttribute('type', type);
      expect(field).toHaveAttribute('autocomplete', autoComplete);
    }
    expect(screen.getByLabelText('Email')).toHaveAttribute('spellcheck', 'false');
  });

  it.each([false, true])('associates all 422 field errors and focuses first invalid field for register=%s', async register => {
    const fields = register ? ['name', 'email', 'password', 'password_confirmation'] : ['email', 'password'];
    mutation = async () => json(422, { message: 'Validation failed.', errors: Object.fromEntries(fields.map(field => [field, [`Please correct ${field}.`]])) });
    await guest(register);
    fill(register);
    submit(register);
    await screen.findByText(`Please correct ${fields[0]}.`);
    const labels = register ? ['Name', 'Email', 'Password', 'Confirm Password'] : ['Email', 'Password'];
    labels.forEach((label, index) => {
      const field = screen.getByLabelText(label);
      expect(field).toHaveAttribute('aria-invalid', 'true');
      expect(field).toHaveAccessibleDescription(`Please correct ${fields[index]}.`);
    });
    expect(screen.getByLabelText(labels[0])).toHaveFocus();
    fireEvent.change(screen.getByLabelText(register ? 'Confirm Password' : 'Password'), { target: { value: 'corrected-password' } });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('shows Laravel generic credential 422 through the real API, then clears and resubmits', async () => {
    mutation = async () => json(422, { message: 'The provided credentials are incorrect.', errors: { email: ['The provided credentials are incorrect.'] } });
    await guest();
    fill();
    submit();
    expect(await screen.findByRole('alert')).toHaveTextContent('The provided credentials are incorrect.');
    expect(screen.getByLabelText('Email')).toHaveAccessibleDescription('The provided credentials are incorrect.');
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'corrected-password' } });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    mutation = async () => json(200, { user });
    submit();
    expect(await screen.findByText(user.email)).toBeVisible();
  });

  it.each([false, true])('switches views without carrying validation or transport errors for register=%s', async register => {
    mutation = async () => json(422, { errors: { email: ['Email rejected.'] } });
    await guest(register);
    fill(register);
    submit(register);
    await screen.findByRole('alert');
    fireEvent.click(screen.getByRole('button', { name: register ? 'Sign in' : 'Create one' }));
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Email')).toHaveValue('');
    expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'false');
  });

  describe.each([false, true])('form failure feedback, register=%s', register => {
    it.each([
      ['network', /connection/i], [401, /session.*expired/i], [419, /session.*expired/i],
      [429, /too many/i], [500, /server/i],
    ] as const)('shows %s, ends pending and clears obsolete feedback on edit', async (failure, message) => {
      mutation = async () => { if (failure === 'network') throw new TypeError('offline'); return json(failure); };
      await guest(register);
      fill(register);
      submit(register);
      expect(await screen.findByRole('alert')).toHaveTextContent(message);
      expect(screen.getByRole('button', { name: register ? 'Create Account' : 'Sign In' })).toBeEnabled();
      expect(mutations()).toHaveLength(failure === 419 ? 2 : 1);
      fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'changed@example.test' } });
      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
  });

  it.each(['network', 419, 429, 500] as const)('keeps shell and identity during pending logout and after %s failure', async failure => {
    me = async () => json(200, { user });
    let reject!: (reason: unknown) => void;
    let resolve!: (value: Response) => void;
    mutation = () => new Promise((yes, no) => { resolve = yes; reject = no; });
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Log out' }));
    await waitFor(() => expect(mutations()).toHaveLength(1));
    expect(screen.getByText(user.email)).toBeVisible();
    expect(screen.getByRole('button', { name: /logging out/i })).toBeDisabled();
    await act(async () => {
      mutation = async () => json(419);
      if (failure === 'network') reject(new TypeError('offline'));
      else resolve(json(failure));
    });
    expect(await screen.findByRole('alert')).toHaveTextContent(failure === 'network' ? /connection/i : failure === 419 ? /session.*expired/i : failure === 429 ? /too many/i : /server/i);
    expect(screen.getByText(user.email)).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Sign In' })).not.toBeInTheDocument();
    mutation = async () => json(204);
    fireEvent.click(screen.getByRole('button', { name: 'Log out' }));
    expect(await screen.findByRole('button', { name: 'Sign In' })).toBeVisible();
    expect(screen.queryByText(user.email)).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('returns to sign in with visible expiration on established-session logout 401', async () => {
    me = async () => json(200, { user });
    mutation = async () => json(401);
    render(<App />);
    fireEvent.click(await screen.findByRole('button', { name: 'Log out' }));
    expect(await screen.findByRole('button', { name: 'Sign In' })).toBeVisible();
    expect(screen.getByRole('alert')).toHaveTextContent(/session.*expired/i);
    expect(screen.queryByText(user.email)).not.toBeInTheDocument();
  });
});
