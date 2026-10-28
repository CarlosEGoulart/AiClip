import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Routes, Route, Navigate, useLocation, Outlet } from 'react-router-dom';
import { AuthProvider, useAuth, LoginForm, RegisterForm, AuthenticatedShell } from './features/auth';
import { SocialConnections } from './features/social';
import { PublishList } from './features/social-publish';
import HealthCheck from './components/HealthCheck';

/*
 * Shared React Query client for the application's data hooks (the embedded
 * clip-review feature is the first consumer). One root provider keeps a
 * single cache alive across the authenticated session; without it, every
 * React Query hook throws on mount and unmounts the whole application
 * (no error boundary exists — see plan Phase 7.5 defect class).
 */
const queryClient = new QueryClient();

function AuthEntry() {
  const [view, setView] = React.useState<'login' | 'register'>('login');
  const { state, clearErrors } = useAuth();

  const isPending = state === 'logging-in' || state === 'registering';

  const switchView = (newView: 'login' | 'register') => {
    setView(newView);
    clearErrors();
  };

  return (
    <div className="auth-page">
      <div className="auth-column">
        <p className="auth-wordmark">AiClip</p>

        <h1 className="auth-headline">
          {view === 'login' ? 'Sign in to your workspace' : 'Create your workspace'}
        </h1>

        <p className="auth-supporting">
          {view === 'login'
            ? 'Enter your credentials to continue editing clips.'
            : 'Start turning long videos into shareable clips in minutes.'}
        </p>

        {view === 'login' ? (
          <LoginForm />
        ) : (
          <RegisterForm />
        )}

        <p className="auth-switch">
          {view === 'login' ? (
            <>
              Don't have an account?{' '}
              <button
                onClick={() => switchView('register')}
                className="link-button"
                disabled={isPending}
              >
                Create one
              </button>
            </>
          ) : (
            <>
              Already have an account?{' '}
              <button
                onClick={() => switchView('login')}
                className="link-button"
                disabled={isPending}
              >
                Sign in
              </button>
            </>
          )}
        </p>
      </div>
    </div>
  );
}

function BootstrapErrorView() {
  const { errorMessage, retrySession } = useAuth();

  return (
    <div className="auth-page">
      <div className="auth-column">
        <p className="auth-wordmark">AiClip</p>

        <h1 className="auth-headline">Something went wrong</h1>

        <p className="auth-supporting">We couldn't restore your session. Please try again.</p>

        <div className="auth-error" role="alert">
          {errorMessage}
        </div>

        <button onClick={retrySession} className="link-button" style={{ width: 'fit-content' }}>
          Retry session
        </button>
      </div>
    </div>
  );
}

function AuthenticatedView() {
  const { state, user } = useAuth();

  if (state === 'checking-session') {
    return <div role="status">Checking session...</div>;
  }

  if (state === 'authenticated' || state === 'logging-out') {
    return <AuthenticatedLayout />;
  }

  if (user && (state === 'network-error' || state === 'server-error')) {
    return <AuthenticatedLayout />;
  }

  if (state === 'network-error' || state === 'server-error') {
    return <BootstrapErrorView />;
  }

  return <AuthEntry />;
}

function AuthenticatedLayout() {
  return (
    <BrowserRouter>
      <Routes>
        <Route element={<AuthenticatedLayoutWrapper />}>
          <Route path="/connections" element={<SocialConnections />} />
          <Route path="/publishes" element={<PublishList />} />
          <Route path="/" element={<AuthenticatedShell />} />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}

function AuthenticatedLayoutWrapper() {
  const location = useLocation();
  const isConnections = location.pathname === '/connections';
  const { user, logout, state } = useAuth();
  const isLoggingOut = state === 'logging-out';

  return (
    <div className="authenticated-shell">
      <header className="app-header">
        {isConnections && (
          <button
            onClick={() => window.history.back()}
            className="back-button"
            aria-label="Back to dashboard"
          >
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
        )}
        <h1>AiClip</h1>
      </header>
      <div className="app-main">
        <div className="user-info">
          <p className="welcome-message">
            Signed in as <strong>{user?.name}</strong>
          </p>
          <p className="user-email">{user?.email}</p>
        </div>
        <Outlet />
        {!isConnections && (
          <button onClick={logout} disabled={isLoggingOut} className="logout-button">
            {isLoggingOut ? 'Logging out...' : 'Log out'}
          </button>
        )}
      </div>
    </div>
  );
}

function AppContent() {
  return (
    <main>
      <AuthenticatedView />
    </main>
  );
}

function App() {
  const isHealthPath = window.location.pathname === '/health';

  if (isHealthPath) {
    return (
      <main>
        <HealthCheck />
      </main>
    );
  }

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <AppContent />
      </AuthProvider>
    </QueryClientProvider>
  );
}

export default App;
