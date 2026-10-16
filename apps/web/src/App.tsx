import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AuthProvider, useAuth, LoginForm, RegisterForm, AuthenticatedShell } from './features/auth';
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
    <div className="auth-container">
      {view === 'login' ? (
        <>
          <LoginForm />
          <p className="auth-switch">
            Don't have an account?{' '}
            <button
              onClick={() => switchView('register')}
              className="link-button"
              disabled={isPending}
            >
              Create one
            </button>
          </p>
        </>
      ) : (
        <>
          <RegisterForm />
          <p className="auth-switch">
            Already have an account?{' '}
            <button
              onClick={() => switchView('login')}
              className="link-button"
              disabled={isPending}
            >
              Sign in
            </button>
          </p>
        </>
      )}
    </div>
  );
}

function BootstrapErrorView() {
  const { errorMessage, retrySession } = useAuth();

  return (
    <div className="auth-container">
      <div className="error-message" role="alert">
        {errorMessage}
      </div>
      <button onClick={retrySession} className="link-button">
        Retry session
      </button>
    </div>
  );
}

function AuthenticatedView() {
  const { state, user } = useAuth();

  if (state === 'checking-session') {
    return <div role="status">Checking session...</div>;
  }

  if (state === 'authenticated' || state === 'logging-out') {
    return <AuthenticatedShell />;
  }

  if (user && (state === 'network-error' || state === 'server-error')) {
    return <AuthenticatedShell />;
  }

  if (state === 'network-error' || state === 'server-error') {
    return <BootstrapErrorView />;
  }

  return <AuthEntry />;
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
