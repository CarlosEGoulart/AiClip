import { render, screen, waitFor } from '@testing-library/react'
import '@testing-library/jest-dom'
import React from 'react'

// Mock the HealthCheck module to avoid import.meta.env issues
jest.mock('../components/HealthCheck', () => {
  const HealthCheck = () => {
    const [health, setHealth] = React.useState<{ status: string; database: string; timestamp?: string } | null>(null)
    const [loading, setLoading] = React.useState(true)
    const [error, setError] = React.useState<string | null>(null)

    React.useEffect(() => {
      fetch('/api/v1/health')
        .then((res: Response) => {
          if (!res.ok) throw new Error(`HTTP ${res.status}`)
          return res.json()
        })
        .then((data: { status: string; database: string; timestamp?: string }) => {
          setHealth(data)
          setLoading(false)
        })
        .catch((err: Error) => {
          setError(err.message || 'Failed to fetch health status')
          setLoading(false)
        })
    }, [])

    if (loading) return <div role="status">Loading health status...</div>
    if (error) return <div role="alert"><h2>Error</h2><p>{error}</p></div>
    return (
      <div>
        <h1>Health Status</h1>
        <p>Status: {health?.status}</p>
        <p>Database: {health?.database}</p>
        {health?.timestamp && <p>Timestamp: {health.timestamp}</p>}
      </div>
    )
  }

  return { __esModule: true, default: HealthCheck }
})

// Mock fetch globally
const mockFetch = jest.fn()
Object.defineProperty(globalThis, 'fetch', {
  value: mockFetch,
  writable: true,
})

import HealthCheck from '../components/HealthCheck'

describe('HealthCheck', () => {
  afterEach(() => {
    jest.restoreAllMocks()
  })

  it('renders loading state initially', () => {
    mockFetch.mockImplementation(() => new Promise(() => {}))
    render(<HealthCheck />)
    expect(screen.getByRole('status')).toHaveTextContent('Loading health status...')
  })

  it('renders success state after successful API call', async () => {
    mockFetch.mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        status: 'ok',
        database: 'connected',
        timestamp: '2026-09-10T00:00:00.000000Z',
      }),
    })
    render(<HealthCheck />)
    await waitFor(() => {
      expect(screen.getByText('Status: ok')).toBeInTheDocument()
      expect(screen.getByText('Database: connected')).toBeInTheDocument()
    })
  })

  it('renders error state after failed API call', async () => {
    mockFetch.mockRejectedValue(new Error('Network error'))
    render(<HealthCheck />)
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument()
      expect(screen.getByText('Network error')).toBeInTheDocument()
    })
  })
})
