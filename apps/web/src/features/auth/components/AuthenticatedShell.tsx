import { useState, useEffect } from 'react';
import { useAuth } from '../hooks';

interface HealthStatus {
  status: string;
  database: string;
  timestamp?: string;
}

export function AuthenticatedShell() {
  const { user, logout } = useAuth();
  const [health, setHealth] = useState<HealthStatus | null>(null);

  useEffect(() => {
    fetch('/api/v1/health')
      .then((res) => res.json())
      .then(setHealth)
      .catch(() => setHealth({ status: 'error', database: 'disconnected' }));
  }, []);

  return (
    <div className="authenticated-shell">
      <header className="app-header">
        <h1>AiClip</h1>
      </header>
      <main className="app-main">
        <div className="user-info">
          <p className="welcome-message">
            Signed in as <strong>{user?.name}</strong>
          </p>
          <p className="user-email">{user?.email}</p>
        </div>

        <div className="health-section">
          <h2>Health Status</h2>
          <p data-testid="health-status">Status: {health?.status ?? 'Loading...'}</p>
          <p data-testid="health-database">Database: {health?.database ?? 'Loading...'}</p>
        </div>

        <button onClick={logout} className="logout-button">
          Log out
        </button>
      </main>
    </div>
  );
}
