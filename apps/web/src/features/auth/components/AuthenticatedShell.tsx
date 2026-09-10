import { useAuth } from '../hooks';

export function AuthenticatedShell() {
  const { user, logout } = useAuth();

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
        <button onClick={logout} className="logout-button">
          Log out
        </button>
      </main>
    </div>
  );
}
