import { useAuth } from '../hooks';

export function AuthenticatedShell() {
  const { user, logout, state, errorMessage } = useAuth();

  const isLoggingOut = state === 'logging-out';
  const logoutFailed = state === 'authenticated' && errorMessage;

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
