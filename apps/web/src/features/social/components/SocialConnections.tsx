import { useEffect, useState, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';
import { SocialConnectionList } from './SocialConnectionList';
import { ConnectPlatformModal } from './ConnectPlatformModal';
import { useSocialConnections, useConnectPlatform, useDisconnectConnection } from '../hooks';
import { toast } from 'sonner';
import '../design-tokens.css';

export function SocialConnections() {
  const [searchParams, setSearchParams] = useSearchParams();
  const [showConnectModal, setShowConnectModal] = useState(false);
  const [disconnectPending, setDisconnectPending] = useState<Record<number, boolean>>({});
  const [reconnectPending, setReconnectPending] = useState<Record<string, boolean>>({});
  const [retryPending, setRetryPending] = useState<Record<number, boolean>>({});

  const {
    connections,
    loading,
    error,
    fetchConnections,
    clearErrors: clearFetchErrors,
  } = useSocialConnections();

  const { connect, isPending: connectPending, error: connectError, clearErrors: clearConnectErrors } = useConnectPlatform();

  const { disconnect, isPending: _disconnectLoading, error: disconnectError, clearErrors: clearDisconnectErrors } = useDisconnectConnection();

  // Handle URL parameters for callback results
  useEffect(() => {
    const connected = searchParams.get('connected');
    const error = searchParams.get('error');

    if (connected) {
      const platformLabel = connected === 'youtube' ? 'YouTube'
        : connected.charAt(0).toUpperCase() + connected.slice(1);
      toast.success(`${platformLabel} connected successfully!`);
      setSearchParams({}, { replace: true });
      fetchConnections();
    }

    if (error) {
      const errorMessages: Record<string, string> = {
        invalid_platform: 'Invalid platform selected',
        missing_parameters: 'Missing required parameters',
        invalid_state: 'Invalid or expired authorization state. Please try again.',
        callback_failed: 'Failed to complete authorization. Please try again.',
      };
      toast.error(errorMessages[error] || 'An error occurred during connection');
      setSearchParams({}, { replace: true });
    }
  }, [searchParams, fetchConnections, setSearchParams]);

  // Fetch connections on mount
  useEffect(() => {
    fetchConnections();
  }, [fetchConnections]);

  const handleConnectClick = useCallback(() => {
    setShowConnectModal(true);
  }, []);

  const handleConnect = useCallback(async (platform: string) => {
    clearConnectErrors();
    const result = await connect(platform);
    if (result) {
      // Redirect to OAuth provider
      window.location.href = result.authorization_url;
    }
  }, [connect, clearConnectErrors]);

  const handleDisconnect = useCallback(async (id: number) => {
    setDisconnectPending(prev => ({ ...prev, [id]: true }));
    clearDisconnectErrors();

    const success = await disconnect(id);
    if (success) {
      toast.success('Disconnected successfully');
      fetchConnections();
    } else if (disconnectError) {
      toast.error(disconnectError);
    }
    setDisconnectPending(prev => ({ ...prev, [id]: false }));
  }, [disconnect, disconnectError, fetchConnections, clearDisconnectErrors]);

  const handleReconnect = useCallback(async (platform: string) => {
    setReconnectPending(prev => ({ ...prev, [platform]: true }));
    clearConnectErrors();

    const result = await connect(platform);
    if (result) {
      window.location.href = result.authorization_url;
    } else if (connectError) {
      toast.error(connectError);
    }
    setReconnectPending(prev => ({ ...prev, [platform]: false }));
  }, [connect, connectError, clearConnectErrors]);

  const handleRetry = useCallback(async (id: number) => {
    setRetryPending(prev => ({ ...prev, [id]: true }));
    clearFetchErrors();

    // For retry, we need to reconnect the same platform
    const connection = connections.find(c => c.id === id);
    if (connection) {
      const result = await connect(connection.platform);
      if (result) {
        window.location.href = result.authorization_url;
      } else if (connectError) {
        toast.error(connectError);
      }
    }
    setRetryPending(prev => ({ ...prev, [id]: false }));
  }, [connections, connect, connectError, clearFetchErrors]);

  // Archival photographs for atmosphere
  const socialImages = [
    {
      id: 1056,
      alt: 'Social media network visualization',
      credit: 'Wikimedia Commons, CC BY 2.0',
    },
    {
      id: 1067,
      alt: 'Global internet connectivity map',
      credit: 'Library of Congress, public domain',
    },
    {
      id: 1078,
      alt: 'Early computer network diagram',
      credit: 'Smithsonian Institution, public domain',
    },
    {
      id: 1089,
      alt: 'Digital communication satellite',
      credit: 'NASA, public domain',
    },
  ];

  return (
    <div className="social-page">
      {/* Image credits for screen readers */}
      <div className="social-image-credits" aria-hidden="true" style={{ position: 'absolute', width: 1, height: 1, padding: 0, margin: -1, overflow: 'hidden', clip: 'rect(0, 0, 0, 0)', whiteSpace: 'nowrap', border: 0 }}>
        {socialImages.map((img) => (
          <span key={img.id}>{img.alt} — {img.credit}</span>
        ))}
      </div>

      {/* Hero — fragment on shaped stage */}
      <header className="social-hero">
        <div className="social-hero-content">
          <p className="social-wordmark">AiClip</p>
          <h1 className="social-headline">Social Connections</h1>
          <p className="social-supporting">
            Manage your connected social media accounts for AI-powered video publishing
          </p>
        </div>

        <div className="social-stage" aria-label="Connection interface preview">
          <div className="connection-fragment" role="region" aria-label="Sample connected accounts">
            <div className="fragment-header">
              <span className="fragment-title">Your Connections</span>
              <span className="fragment-status">3 connected</span>
            </div>
            <div className="fragment-body">
              <div className="connection-preview">
                <div className="preview-avatar" style={{ background: '#ff0000' }}>
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M23.498 6.186a3.167 3.167 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.17 3.17 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.166 3.166 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.167 3.17 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
                  </svg>
                </div>
                <div className="preview-info">
                  <p className="preview-name">My YouTube Channel</p>
                  <p className="preview-platform youtube">YouTube</p>
                </div>
                <div className="preview-actions">
                  <button className="btn btn-ghost" disabled>Disconnect</button>
                </div>
              </div>
              <div className="connection-preview">
                <div className="preview-avatar" style={{ background: 'linear-gradient(135deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)' }}>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden="true">
                    <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
                    <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" />
                    <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" />
                  </svg>
                </div>
                <div className="preview-info">
                  <p className="preview-name">@creator_handle</p>
                  <p className="preview-platform instagram">Instagram</p>
                </div>
                <div className="preview-actions">
                  <button className="btn btn-ghost" disabled>Disconnect</button>
                </div>
              </div>
              <div className="connection-preview">
                <div className="preview-avatar" style={{ background: '#000' }}>
                  <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12.521 21.64c-1.793 0-3.519-.586-4.736-1.57a20.174 20.174 0 0 1-5.273-1.722 1.58 1.58 0 0 1-.38-2.423c.469-.468 3.21-2.232 5.582-2.807v-10.94c-.464-.464-.464-1.216 0-1.68a1.58 1.58 0 0 1 2.43-.46c.147.148 2.345 2.305 2.54 2.507.123.125 2.946 1.534 3.213 1.79a1.58 1.58 0 0 1 .46 2.43c-.464.465-1.216.464-1.68 0V12c-.572.103-1.558.54-2.72 1.035-1.166.496-2.546.812-4.112.812zm2.426-9.552c.34.158 1.143.844 1.547 1.372.403.527.852 1.22 1.036 1.62.185.4.44.903.44 1.032v.767h2.907V10.43c0-.629-.263-1.418-.766-2.112-.504-.693-1.307-1.13-2.16-1.13-.692 0-1.33.256-1.727.618-.397.362-.73.855-.73 1.32v.748h1.4zm-6.403 8.586c-.013.493-.166 1.32-.42 2.082-.254.762-.723 1.403-1.262 1.802a1.75 1.75 0 0 1-2.34.12v-2.707c.37-.27 2.151-2.257 2.455-2.724a1.47 1.47 0 0 1 1.566.744z" />
                  </svg>
                </div>
                <div className="preview-info">
                  <p className="preview-name">@tiktok_creator</p>
                  <p className="preview-platform tiktok">TikTok</p>
                </div>
                <div className="preview-actions">
                  <button className="btn btn-ghost" disabled>Disconnect</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </header>

      {/* Connections List */}
      <main className="social-connections">
        <div className="connections-header">
          <div>
            <h2 className="connections-title">Connected Accounts</h2>
            <p className="connections-count">
              {connections.length} account{connections.length !== 1 ? 's' : ''} connected
            </p>
          </div>
          <button
            type="button"
            onClick={handleConnectClick}
            disabled={connectPending}
            className="btn btn-primary connect-button"
          >
            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
            </svg>
            Connect Account
          </button>
        </div>

        {error && (
          <div className="connection-card card-error" role="alert" style={{ background: 'var(--error-bg)', borderColor: 'rgba(220, 38, 38, 0.15)' }}>
            {error}
          </div>
        )}

        <SocialConnectionList
          connections={connections}
          loading={loading}
          onDisconnect={handleDisconnect}
          onReconnect={handleReconnect}
          onConnect={handleConnectClick}
          onRetry={handleRetry}
          disconnectPending={disconnectPending}
          reconnectPending={reconnectPending}
          retryPending={retryPending}
        />

        <ConnectPlatformModal
          isOpen={showConnectModal}
          onClose={() => setShowConnectModal(false)}
          onConnect={handleConnect}
          loading={connectPending}
          error={connectError}
        />
      </main>
    </div>
  );
}