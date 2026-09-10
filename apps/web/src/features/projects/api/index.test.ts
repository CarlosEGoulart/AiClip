import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { getProjects, createProject, deleteProject } from '../api';

const user = { id: 7, name: 'Mira Vale', email: 'mira@example.test', email_verified_at: null };
const project = { id: 1, name: 'Test Project', user_id: 7, created_at: '2026-09-10T12:00:00.000000Z', updated_at: '2026-09-10T12:00:00.000000Z' };
const response = (status: number, body?: unknown) => new Response(
  body === undefined ? null : JSON.stringify(body), { status },
);
const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  document.cookie = 'XSRF-TOKEN=test%3D; path=/';
});
afterEach(() => {
  for (const cookie of document.cookie.split(';')) {
    document.cookie = `${cookie.split('=')[0].trim()}=; Max-Age=0; path=/`;
  }
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('project API boundary', () => {
  it('sends credentialed GET for list projects', async () => {
    fetchMock.mockResolvedValueOnce(response(200, { data: [project] }));
    const result = await getProjects();
    expect(result).toEqual({ data: [project] });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith('/api/v1/projects', expect.objectContaining({
      credentials: 'include',
      headers: expect.objectContaining({ Accept: 'application/json' }),
    }));
  });

  it('sends credentialed POST for create project after CSRF setup', async () => {
    fetchMock.mockResolvedValueOnce(response(204)); // CSRF
    fetchMock.mockResolvedValueOnce(response(201, { data: project }));

    const result = await createProject({ name: 'Test Project' });
    expect(result).toEqual({ data: project });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(1, '/sanctum/csrf-cookie', expect.objectContaining({
      credentials: 'include',
    }));
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/projects', expect.objectContaining({
      method: 'POST',
      credentials: 'include',
      body: JSON.stringify({ name: 'Test Project' }),
      headers: expect.objectContaining({
        Accept: 'application/json',
        'Content-Type': 'application/json',
      }),
    }));
  });

  it('sends credentialed DELETE for delete project after CSRF setup', async () => {
    fetchMock.mockResolvedValueOnce(response(204)); // CSRF
    fetchMock.mockResolvedValueOnce(response(204)); // DELETE

    await deleteProject(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock).toHaveBeenNthCalledWith(2, '/api/v1/projects/1', expect.objectContaining({
      method: 'DELETE',
      credentials: 'include',
    }));
  });

  it('maps 401 to unauthorized', async () => {
    fetchMock.mockResolvedValueOnce(response(401));
    await expect(getProjects()).rejects.toMatchObject({ type: 'unauthorized' });
  });

  it('maps 422 to validation', async () => {
    const errors = { name: ['The name field is required.'] };
    fetchMock.mockResolvedValueOnce(response(204)).mockResolvedValueOnce(response(422, { errors }));
    await expect(createProject({ name: '' })).rejects.toMatchObject({ type: 'validation', errors });
  });

  it('maps network rejection to network error', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('offline'));
    await expect(getProjects()).rejects.toMatchObject({ type: 'network' });
  });
});
