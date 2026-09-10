import { useState, useEffect } from 'react'

interface HealthStatus {
  status: string
  database: string
  timestamp?: string
}

export default function HealthCheck() {
  const [health, setHealth] = useState<HealthStatus | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const API_BASE = import.meta.env.VITE_API_BASE_URL || ''
    fetch(`${API_BASE}/api/v1/health`)
      .then((res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`)
        return res.json()
      })
      .then((data: HealthStatus) => {
        setHealth(data)
        setLoading(false)
      })
      .catch((err) => {
        setError(err.message || 'Failed to fetch health status')
        setLoading(false)
      })
  }, [])

  if (loading) {
    return <div role="status">Loading health status...</div>
  }

  if (error) {
    return (
      <div role="alert">
        <h2>Error</h2>
        <p>{error}</p>
      </div>
    )
  }

  return (
    <div>
      <h1>Health Status</h1>
      <p>Status: {health?.status}</p>
      <p>Database: {health?.database}</p>
      {health?.timestamp && <p>Timestamp: {health.timestamp}</p>}
    </div>
  )
}
