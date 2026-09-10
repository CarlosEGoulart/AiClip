import React from 'react';
import { AuthProvider, useAuth, LoginForm, RegisterForm, AuthenticatedShell } from './features/auth';

function AuthView() {
  const { state } = useAuth();
  const [view, setView] = React.useState<'login' | 'register'>('login');

  if (state === 'checking-session') {
    return <div className="loading">Loading...</div>;
  }

  if (state === 'authenticated') {
    return <AuthenticatedShell />;
  }

  return (
    <div className="auth-container">
      {view === 'login' ? (
        <>
          <LoginForm />
          <p className="auth-switch">
            Don't have an account?{' '}
            <button onClick={() => setView('register')} className="link-button">
              Create one
            </button>
          </p>
        </>
      ) : (
        <>
          <RegisterForm />
          <p className="auth-switch">
            Already have an account?{' '}
            <button onClick={() => setView('login')} className="link-button">
              Sign in
            </button>
          </p>
        </>
      )}
    </div>
  );
}

function App() {
  return (
    <AuthProvider>
      <main>
        <AuthView />
      </main>
    </AuthProvider>
  );
}

export default App;
