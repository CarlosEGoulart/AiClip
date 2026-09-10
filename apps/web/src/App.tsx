import React from 'react';
import { AuthProvider, useAuth, LoginForm, RegisterForm, AuthenticatedShell } from './features/auth';
import HealthCheck from './components/HealthCheck';

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
    <AuthProvider>
      <AppContent />
    </AuthProvider>
  );
}

export default App;
