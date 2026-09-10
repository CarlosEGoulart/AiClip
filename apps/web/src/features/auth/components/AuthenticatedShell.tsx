import { useState, useEffect } from 'react';
import { useAuth } from '../hooks';

interface HealthStatus {
  status: string;
  database: string;
  timestamp?: string;
}

export function AuthenticatedShell() {
  const { user, logout, state, errorMessage } = useAuth();
  const [health, setHealth] = useState<HealthStatus | null>(null);

  const isLoggingOut = state === 'logging-out';
  const logoutFailed = state === 'authenticated' && errorMessage;

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
      <div className="app-main">
        <div className="user-info">
          <p className="welcome-message">
            Signed in as <strong>{user?.name}</strong>
          </p>
          <p className="user-email">{user?.email}</p>
        </div>

        <section className="health-section" role="region" aria-label="System health">
          <h2>Health Status</h2>
          <p data-testid="health-status">Status: {health?.status ?? 'Loading...'}</p>
          <p data-testid="health-database">Database: {health?.database ?? 'Loading...'}</p>
        </section>

        {logoutFailed && (
          <div className="error-message" role="alert">
            {errorMessage || 'Logout failed. The server session may still be active.'}
          </div>
        )}

        <button onClick={logout} disabled={isLoggingOut} className="logout-button">
          {isLoggingOut ? 'Logging out...' : 'Log out'}
        </button>
      </div>
    </div>
  );
}
