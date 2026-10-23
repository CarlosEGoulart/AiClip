import { useState } from 'react';
import type { SocialConnection, SocialConnectionStatus } from '../types';

interface SocialConnectionCardProps {
  connection: SocialConnection;
  onDisconnect: (id: number) => void;
  onReconnect: (platform: string) => void;
  onRetry: (id: number) => void;
  disconnectPending?: boolean;
  reconnectPending?: boolean;
  retryPending?: boolean;
}

function StatusBadge({ status }: { status: SocialConnectionStatus }) {
  const statusConfig: Record<SocialConnectionStatus, { label: string; color: string; bgColor: string }> = {
    connected: { label: 'Connected', color: 'text-green-700', bgColor: 'bg-green-100' },
    expired: { label: 'Expired', color: 'text-yellow-700', bgColor: 'bg-yellow-100' },
    revoked: { label: 'Revoked', color: 'text-gray-700', bgColor: 'bg-gray-100' },
    error: { label: 'Error', color: 'text-red-700', bgColor: 'bg-red-100' },
  };

  const config = statusConfig[status] || statusConfig.error;

  return (
    <span
      className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${config.bgColor} ${config.color}`}
      role="status"
      aria-live="polite"
    >
      {config.label}
    </span>
  );
}

function PlatformIcon({ platform }: { platform: 'youtube' | 'instagram' | 'tiktok' }) {
  const icons: Record<string, React.ReactElement> = {
    youtube: (
      <svg data-testid="platform-icon" className="w-6 h-6 text-red-500" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M23.498 6.186a3.167 3.167 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.17 3.17 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.166 3.166 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.167 3.167 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
      </svg>
    ),
    instagram: (
      <svg data-testid="platform-icon" className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="2" y="2" width="20" height="20" rx="5" ry="5" stroke="currentColor" strokeWidth="2" />
        <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" stroke="currentColor" strokeWidth="2" />
        <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" stroke="currentColor" strokeWidth="2" />
      </svg>
    ),
    tiktok: (
      <svg data-testid="platform-icon" className="w-6 h-6 text-black" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M12.521 21.64c-1.793 0-3.519-.586-4.736-1.57a20.174 20.174 0 0 1-5.273-1.722 1.58 1.58 0 0 1-.38-2.423c.469-.468 3.21-2.232 5.582-2.807v-10.94c-.464-.464-.464-1.216 0-1.68a1.58 1.58 0 0 1 2.43-.46c.147.148 2.345 2.305 2.54 2.507.123.125 2.946 1.534 3.213 1.79a1.58 1.58 0 0 1 .46 2.43c-.464.465-1.216.464-1.68 0V12c-.572.103-1.558.54-2.72 1.035-1.166.496-2.546.812-4.112.812zm2.426-9.552c.34.158 1.143.844 1.547 1.372.403.527.852 1.22 1.036 1.62.185.4.44.903.44 1.032v.767h2.907V10.43c0-.629-.263-1.418-.766-2.112-.504-.693-1.307-1.13-2.16-1.13-.692 0-1.33.256-1.727.618-.397.362-.73.855-.73 1.32v.748h1.4zm-6.403 8.586c-.013.493-.166 1.32-.42 2.082-.254.762-.723 1.403-1.262 1.802a1.75 1.75 0 0 1-2.34.12v-2.707c.37-.27 2.151-2.257 2.455-2.724a1.47 1.47 0 0 1 1.566.744z" />
      </svg>
    ),
  };

  return icons[platform] || icons.youtube;
}

export function SocialConnectionCard({
  connection,
  onDisconnect,
  onReconnect,
  onRetry,
  disconnectPending = false,
  reconnectPending = false,
  retryPending = false,
}: SocialConnectionCardProps) {
  const [showDisconnectConfirm, setShowDisconnectConfirm] = useState(false);

  const handleDisconnectClick = () => {
    setShowDisconnectConfirm(true);
  };

  const handleConfirmDisconnect = () => {
    onDisconnect(connection.id);
    setShowDisconnectConfirm(false);
  };

  const handleCancelDisconnect = () => {
    setShowDisconnectConfirm(false);
  };

  const handleReconnectClick = () => {
    onReconnect(connection.platform);
  };

  const handleRetryClick = () => {
    onRetry(connection.id);
  };

  return (
    <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 shadow-sm hover:shadow-md transition-shadow">
      <div className="flex items-start gap-4">
        <div className="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center bg-gray-100 dark:bg-gray-700 overflow-hidden">
          {connection.avatar_url ? (
            <img
              src={connection.avatar_url}
              alt=""
              className="w-full h-full object-cover"
              aria-hidden="true"
            />
          ) : (
            <PlatformIcon platform={connection.platform} />
          )}
        </div>

        <div className="flex-1 min-w-0">
          <div className="flex items-center justify-between gap-3">
            <div className="min-w-0">
              <h3 className="text-lg font-semibold text-gray-900 dark:text-white truncate">
                {connection.display_name}
              </h3>
              <p className="text-sm text-gray-500 dark:text-gray-400 capitalize">
                {connection.platform_label}
              </p>
            </div>
            <StatusBadge status={connection.status} />
          </div>

          {connection.channel_url && (
            <a
              href={connection.channel_url}
              target="_blank"
              rel="noopener noreferrer"
              className="mt-2 inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline"
            >
              <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
              </svg>
              View Channel
            </a>
          )}

          {connection.status === 'error' && connection.last_error && (
            <div className="mt-3 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
              <p className="text-sm text-red-700 dark:text-red-300" role="alert">
                {connection.last_error}
              </p>
            </div>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-2">
            {connection.status === 'connected' && (
              <>
                <button
                  type="button"
                  onClick={handleDisconnectClick}
                  disabled={disconnectPending}
                  className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg hover:bg-red-100 dark:hover:bg-red-900/30 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                  aria-label={`Disconnect ${connection.platform_label}`}
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                  </svg>
                  Disconnect {connection.platform_label}
                </button>
              </>
            )}

            {(connection.status === 'expired' || connection.status === 'revoked') && (
              <>
                <button
                  type="button"
                  onClick={handleReconnectClick}
                  disabled={reconnectPending}
                  className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/30 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                  aria-label={`Reconnect ${connection.platform_label}`}
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  {connection.status === 'expired' ? `Reconnect ${connection.platform_label}` : `Connect Again ${connection.platform_label}`}
                </button>
              </>
            )}

            {connection.status === 'error' && (
              <>
                <button
                  type="button"
                  onClick={handleRetryClick}
                  disabled={retryPending}
                  className="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-orange-600 dark:text-orange-400 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg hover:bg-orange-100 dark:hover:bg-orange-900/30 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                  aria-label={`Retry connection for ${connection.platform_label}`}
                >
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                  Retry connection for {connection.platform_label}
                </button>
              </>
            )}
          </div>
        </div>
      </div>

      {showDisconnectConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="disconnect-dialog-title">
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-md shadow-xl">
            <h2 id="disconnect-dialog-title" className="text-lg font-semibold text-gray-900 dark:text-white mb-2">
              Disconnect {connection.platform_label}?
            </h2>
            <p className="text-gray-600 dark:text-gray-300 mb-6">
              You'll need to reconnect to publish to {connection.platform_label}.
            </p>
            <div className="flex justify-end gap-3">
              <button
                type="button"
                onClick={handleCancelDisconnect}
                className="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
              >
                Cancel
              </button>
              <button
                type="button"
                onClick={handleConfirmDisconnect}
                disabled={disconnectPending}
                className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {disconnectPending ? 'Disconnecting...' : 'Disconnect'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}