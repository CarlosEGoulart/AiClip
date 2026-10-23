import type { SocialConnection } from '../types';
import { SocialConnectionCard } from './SocialConnectionCard';
import '../design-tokens.css';

interface SocialConnectionListProps {
  connections: SocialConnection[];
  loading: boolean;
  onDisconnect: (id: number) => void;
  onReconnect: (platform: string) => void;
  onConnect: () => void;
  onRetry: (id: number) => void;
  disconnectPending?: Record<number, boolean>;
  reconnectPending?: Record<string, boolean>;
  retryPending?: Record<number, boolean>;
}

function SkeletonCard() {
  return (
    <article className="connection-card animate-pulse" style={{ pointerEvents: 'none' }}>
      <div className="card-avatar" style={{ background: 'var(--surface)' }} />
      <div className="card-content">
        <div className="card-header">
          <div className="card-info">
            <div style={{ height: '24px', width: '60%', background: 'var(--surface)', borderRadius: 'var(--radius)' }} />
            <div style={{ height: '14px', width: '40%', background: 'var(--surface)', borderRadius: 'var(--radius)', marginTop: '4px' }} />
          </div>
          <div className="card-status" style={{ background: 'var(--surface)' }} />
        </div>
        <div style={{ height: '40px', background: 'var(--surface)', borderRadius: 'var(--radius)' }} />
        <div style={{ height: '36px', background: 'var(--surface)', borderRadius: 'var(--radius)', width: '50%' }} />
      </div>
    </article>
  );
}

function EmptyState({ onConnect }: { onConnect: () => void }) {
  return (
    <div className="connections-empty">
      <div className="connections-empty-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
          <path strokeLinecap="round" strokeLinejoin="round" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
        </svg>
      </div>
      <h3 className="connections-empty-title">No connections yet</h3>
      <p className="connections-empty-text">
        Connect your social accounts to publish content directly from AiClip.
      </p>
      <button
        type="button"
        onClick={onConnect}
        className="btn btn-primary"
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
  onConnect,
  onRetry,
  disconnectPending = {},
  reconnectPending = {},
  retryPending = {},
}: SocialConnectionListProps) {
  if (loading) {
    return (
      <div className="connections-list" role="status" aria-label="Loading connections">
        <SkeletonCard />
        <SkeletonCard />
        <SkeletonCard />
      </div>
    );
  }

  if (connections.length === 0) {
    return <EmptyState onConnect={onConnect} />;
  }

  return (
    <div className="connections-list">
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