import type { SocialConnection } from '../types';
import { SocialConnectionCard } from './SocialConnectionCard';

interface SocialConnectionListProps {
  connections: SocialConnection[];
  loading: boolean;
  onDisconnect: (id: number) => void;
  onReconnect: (platform: string) => void;
  onRetry: (id: number) => void;
  disconnectPending?: Record<number, boolean>;
  reconnectPending?: Record<string, boolean>;
  retryPending?: Record<number, boolean>;
}

function SkeletonCard() {
  return (
    <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 animate-pulse">
      <div className="flex items-start gap-4">
        <div className="flex-shrink-0 w-12 h-12 rounded-lg bg-gray-200 dark:bg-gray-700" />
        <div className="flex-1">
          <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-3/4 mb-2" />
          <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2 mb-4" />
          <div className="h-8 bg-gray-200 dark:bg-gray-700 rounded w-1/3" />
          <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/4 mt-2" aria-label="Loading" />
        </div>
      </div>
      <div className="sr-only" aria-live="polite">Loading</div>
    </div>
  );
}

function EmptyState({ onConnect }: { onConnect: () => void }) {
  return (
    <div className="text-center py-12 px-4">
      <div className="mx-auto mb-4 w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
        <svg className="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
      </div>
      <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-2">No connections yet</h3>
      <p className="text-gray-500 dark:text-gray-400 mb-6 max-w-xs mx-auto">
        Connect your social accounts to publish content directly from AiClip.
      </p>
      <button
        type="button"
        onClick={onConnect}
        className="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
      >
        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
        </svg>
        Connect your first account
      </button>
    </div>
  );
}

export function SocialConnectionList({
  connections,
  loading,
  onDisconnect,
  onReconnect,
  onRetry,
  disconnectPending = {},
  reconnectPending = {},
  retryPending = {},
}: SocialConnectionListProps) {
  if (loading) {
    return (
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <SkeletonCard />
        <SkeletonCard />
        <SkeletonCard />
      </div>
    );
  }

  if (connections.length === 0) {
    return <EmptyState onConnect={() => {}} />;
  }

  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {connections.map((connection) => (
        <SocialConnectionCard
          key={connection.id}
          connection={connection}
          onDisconnect={onDisconnect}
          onReconnect={onReconnect}
          onRetry={onRetry}
          disconnectPending={disconnectPending[connection.id]}
          reconnectPending={reconnectPending[connection.platform]}
          retryPending={retryPending[connection.id]}
        />
      ))}
    </div>
  );
}