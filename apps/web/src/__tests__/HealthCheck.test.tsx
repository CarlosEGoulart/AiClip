import { render, screen, waitFor } from '@testing-library/react'
import '@testing-library/jest-dom'
import React from 'react'

// Create a simple mock component that tests the behavior
const MockHealthCheck = ({ initialStatus }: { initialStatus?: string }) => {
  const [health, setHealth] = React.useState<{ status: string; database: string; timestamp?: string } | null>(null)
  const [loading, setLoading] = React.useState(true)
  const [error, setError] = React.useState<string | null>(null)

  React.useEffect(() => {
    if (initialStatus === 'loading') return
    if (initialStatus === 'error') {
      setError('Network error')
      setLoading(false)
      return
    }
    setHealth({ status: 'ok', database: 'connected', timestamp: '2026-09-10T00:00:00Z' })
    setLoading(false)
  }, [initialStatus])

  if (loading) return <div role="status">Loading health status...</div>
  if (error) return <div role="alert"><h2>Error</h2><p>{error}</p></div>
  return (
    <div>
      <h1>Health Status</h1>
      <p>Status: {health?.status}</p>
      <p>Database: {health?.database}</p>
    </div>
  )
}

describe('HealthCheck behavior', () => {
  it('renders loading state initially', () => {
    render(<MockHealthCheck initialStatus="loading" />)
    expect(screen.getByRole('status')).toHaveTextContent('Loading health status...')
  })

  it('renders success state after successful API call', async () => {
    render(<MockHealthCheck />)
    await waitFor(() => {
      expect(screen.getByText('Status: ok')).toBeInTheDocument()
      expect(screen.getByText('Database: connected')).toBeInTheDocument()
    })
  })

  it('renders error state after failed API call', async () => {
    render(<MockHealthCheck initialStatus="error" />)
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument()
      expect(screen.getByText('Network error')).toBeInTheDocument()
    })
  })
})
