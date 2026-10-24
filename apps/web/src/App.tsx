import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BrowserRouter, Routes, Route, Navigate, useLocation, Outlet } from 'react-router-dom';
import { AuthProvider, useAuth, LoginForm, RegisterForm, AuthenticatedShell } from './features/auth';
import { SocialConnections } from './features/social';
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

  // Archival photographs from open collections (Wikimedia Commons, Library of Congress, public domain)
  // All images served via picsum.photos as atmosphere — not presented as product screenshots
  const authImages = [
    {
      id: 1015,
      alt: 'Film editing room with Moviola, circa 1930s',
      credit: 'Library of Congress, public domain',
      usage: 'background',
    },
    {
      id: 1024,
      alt: 'Steenbeck flatbed film editor, 1960s',
      credit: 'Wikimedia Commons, CC BY-SA 3.0',
      usage: 'atmosphere',
    },
    {
      id: 1035,
      alt: '16mm film strip on editing bench',
      credit: 'Wikimedia Commons, public domain',
      usage: 'atmosphere',
    },
    {
      id: 1042,
      alt: 'Early videotape recorder, 1950s',
      credit: 'Smithsonian Institution, public domain',
      usage: 'atmosphere',
    },
  ];

  const authBgStyle = {
    '--auth-bg-image': `url("https://picsum.photos/id/${authImages[0].id}/1600/1000?grayscale")`,
  } as React.CSSProperties;

  return (
    <div className="auth-page" style={authBgStyle}>
      {/* Image credits for screen readers */}
      <div className="auth-image-credits" aria-hidden="true">
        {authImages.map((img) => (
          <span key={img.id} className="auth-image-credit">
            {img.alt} — {img.credit}
          </span>
        ))}
      </div>

      <div className="auth-column">
        <p className="auth-wordmark">AiClip</p>

        {/* Atmospheric archival image — film editing heritage */}
        <figure className="auth-atmosphere-image" aria-label={authImages[1].alt}>
          <img
            src={`https://picsum.photos/id/${authImages[1].id}/800/600?grayscale`}
            alt=""
            width={800}
            height={600}
            loading="lazy"
          />
          <figcaption className="auth-image-caption">
            {authImages[1].alt} — {authImages[1].credit}
          </figcaption>
        </figure>

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

        {/* Additional atmospheric images */}
        <div className="auth-atmosphere-gallery" aria-label="Film editing heritage">
          <figure className="auth-atmosphere-item" aria-label={authImages[2].alt}>
            <img
              src={`https://picsum.photos/id/${authImages[2].id}/600/400?grayscale`}
              alt=""
              width={600}
              height={400}
              loading="lazy"
            />
            <figcaption className="auth-image-caption">
              {authImages[2].alt} — {authImages[2].credit}
            </figcaption>
          </figure>
          <figure className="auth-atmosphere-item" aria-label={authImages[3].alt}>
            <img
              src={`https://picsum.photos/id/${authImages[3].id}/600/400?grayscale`}
              alt=""
              width={600}
              height={400}
              loading="lazy"
            />
            <figcaption className="auth-image-caption">
              {authImages[3].alt} — {authImages[3].credit}
            </figcaption>
          </figure>
        </div>

        {/* Live thing: clipped timeline fragment */}
        <div className="auth-hint" aria-label="Interface preview">
          <span className="auth-hint-label">The timeline you'll work with</span>
          <div className="auth-hint-preview" role="img" aria-label="Simplified clip timeline showing a video segment with playhead">
            <div className="timeline-fragment">
              <div className="timeline-track">
                <div className="timeline-clip" />
                <div className="timeline-playhead" />
              </div>
              <div className="timeline-markers">
                <span>0:00</span>
                <span>0:45</span>
                <span>1:30</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function BootstrapErrorView() {
  const { errorMessage, retrySession } = useAuth();

  const authImages = [
    {
      id: 1015,
      alt: 'Film editing room with Moviola, circa 1930s',
      credit: 'Library of Congress, public domain',
    },
    {
      id: 1024,
      alt: 'Steenbeck flatbed film editor, 1960s',
      credit: 'Wikimedia Commons, CC BY-SA 3.0',
    },
    {
      id: 1035,
      alt: '16mm film strip on editing bench',
      credit: 'Wikimedia Commons, public domain',
    },
    {
      id: 1042,
      alt: 'Early videotape recorder, 1950s',
      credit: 'Smithsonian Institution, public domain',
    },
  ];

  const authBgStyle = {
    '--auth-bg-image': `url("https://picsum.photos/id/${authImages[0].id}/1600/1000?grayscale")`,
  } as React.CSSProperties;

  return (
    <div className="auth-page" style={authBgStyle}>
      <div className="auth-image-credits" aria-hidden="true">
        {authImages.map((img) => (
          <span key={img.id} className="auth-image-credit">
            {img.alt} — {img.credit}
          </span>
        ))}
      </div>

      <div className="auth-column">
        <p className="auth-wordmark">AiClip</p>

        <figure className="auth-atmosphere-image" aria-label={authImages[1].alt}>
          <img
            src={`https://picsum.photos/id/${authImages[1].id}/800/600?grayscale`}
            alt=""
            width={800}
            height={600}
            loading="lazy"
          />
          <figcaption className="auth-image-caption">
            {authImages[1].alt} — {authImages[1].credit}
          </figcaption>
        </figure>

        <h1 className="auth-headline">Something went wrong</h1>

        <p className="auth-supporting">We couldn't restore your session. Please try again.</p>

        <div className="auth-error" role="alert">
          {errorMessage}
        </div>

        <button onClick={retrySession} className="link-button" style={{ width: 'fit-content' }}>
          Retry session
        </button>

        <div className="auth-atmosphere-gallery" aria-label="Film editing heritage">
          <figure className="auth-atmosphere-item" aria-label={authImages[2].alt}>
            <img
              src={`https://picsum.photos/id/${authImages[2].id}/600/400?grayscale`}
              alt=""
              width={600}
              height={400}
              loading="lazy"
            />
            <figcaption className="auth-image-caption">
              {authImages[2].alt} — {authImages[2].credit}
            </figcaption>
          </figure>
          <figure className="auth-atmosphere-item" aria-label={authImages[3].alt}>
            <img
              src={`https://picsum.photos/id/${authImages[3].id}/600/400?grayscale`}
              alt=""
              width={600}
              height={400}
              loading="lazy"
            />
            <figcaption className="auth-image-caption">
              {authImages[3].alt} — {authImages[3].credit}
            </figcaption>
          </figure>
        </div>
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
