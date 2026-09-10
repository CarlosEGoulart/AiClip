import { render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import HealthCheck from '../components/HealthCheck'

const HEALTH_PATH = '/api/v1/health'

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

function jsonResponse(body: unknown, init?: { ok: boolean; status: number }) {
  return {
    ok: init?.ok ?? true,
    status: init?.status ?? 200,
    json: () => Promise.resolve(body),
  }
}

describe('HealthCheck', () => {
  const fetchMock = vi.fn()

  beforeEach(() => {
    fetchMock.mockReset()
    vi.stubGlobal('fetch', fetchMock)
    vi.stubEnv('VITE_API_BASE_URL', '')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it('remains pending while the health fetch is unresolved', () => {
    const gate = deferred<unknown>()
    fetchMock.mockReturnValue(gate.promise)
    render(<HealthCheck />)

    expect(screen.getByRole('status')).toHaveTextContent('Loading health status...')
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(screen.queryByText('Status: ok')).not.toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('renders status, database, and timestamp after a successful response', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({
        status: 'ok',
        database: 'connected',
        timestamp: '2026-09-10T00:00:00.000000Z',
      }),
    )
    render(<HealthCheck />)

    await waitFor(() => {
      expect(screen.getByText('Status: ok')).toBeInTheDocument()
    })
    expect(screen.getByRole('heading', { name: 'Health Status' })).toBeInTheDocument()
    expect(screen.getByText('Database: connected')).toBeInTheDocument()
    expect(screen.getByText('Timestamp: 2026-09-10T00:00:00.000000Z')).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('renders an error alert when the fetch rejects', async () => {
    fetchMock.mockRejectedValue(new Error('Network error'))
    render(<HealthCheck />)

    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument()
    })
    expect(screen.getByText('Network error')).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    expect(screen.queryByText('Status: ok')).not.toBeInTheDocument()
  })

  it('renders an error alert for a non-2xx response instead of success content', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ status: 'error', database: 'disconnected' }, { ok: false, status: 503 }),
    )
    render(<HealthCheck />)

    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument()
    })
    expect(screen.getByText('HTTP 503')).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
    expect(screen.queryByText('Status: error')).not.toBeInTheDocument()
  })

  it('requests the relative health URL when no public origin is configured', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse({ status: 'ok', database: 'connected', timestamp: '2026-09-10T00:00:00Z' }),
    )
    render(<HealthCheck />)

    await waitFor(() => {
      expect(screen.getByText('Status: ok')).toBeInTheDocument()
    })
    expect(fetchMock).toHaveBeenCalledWith(HEALTH_PATH)
  })

  it('prefixes the health path with the configured public origin', async () => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://api.example.com')
    fetchMock.mockResolvedValue(
      jsonResponse({ status: 'ok', database: 'connected', timestamp: '2026-09-10T00:00:00Z' }),
    )
    render(<HealthCheck />)

    await waitFor(() => {
      expect(screen.getByText('Status: ok')).toBeInTheDocument()
    })
    expect(fetchMock).toHaveBeenCalledWith(`http://api.example.com${HEALTH_PATH}`)
  })
})
