import { useEffect, useState, useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';
import { SocialConnectionList } from './SocialConnectionList';
import { ConnectPlatformModal } from './ConnectPlatformModal';
import { useSocialConnections, useConnectPlatform, useDisconnectConnection } from '../hooks';
import { toast } from 'sonner';

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

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white">Social Connections</h1>
          <p className="text-gray-500 dark:text-gray-400 mt-1">
            Manage your connected social media accounts
          </p>
        </div>
        <button
          type="button"
          onClick={handleConnectClick}
          disabled={connectPending}
          className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Connect Account
        </button>
      </div>

      {error && (
        <div className="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg" role="alert">
          <p className="text-sm text-red-700 dark:text-red-300">{error}</p>
        </div>
      )}

      <SocialConnectionList
        connections={connections}
        loading={loading}
        onDisconnect={handleDisconnect}
        onReconnect={handleReconnect}
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
    </div>
  );
}