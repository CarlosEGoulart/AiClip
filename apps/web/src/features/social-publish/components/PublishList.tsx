import { useEffect } from 'react';
import type { SocialPublish } from '../types';
import { PublishCard } from './PublishCard';
import { usePublishes } from '../hooks';
import '../design-tokens.css';

interface PublishListProps {
  publishes?: SocialPublish[];
  loading?: boolean;
}

function SkeletonCard() {
  return (
    <article className="publish-card publish-skeleton" aria-hidden="true">
      <div className="card-header">
        <div className="skeleton-line" />
        <div className="skeleton-line" style={{ width: '60%' }} />
      </div>
      <div className="skeleton-line" style={{ height: '100px', width: '80%' }} />
    </article>
  );
}

function EmptyState() {
  return (
    <div className="publish-empty" role="status" aria-live="polite">
      <p>No publishes yet</p>
      <p>Publish your first approved clip to YouTube.</p>
    </div>
  );
}

export function PublishList({
  publishes: publishesProp,
  loading = false,
}: PublishListProps) {
  const { publishes: hookPublishes, loading: hookLoading, fetchPublishes } =
    usePublishes();

  // When used as a standalone route (no publishes prop), fetch publishes.
  // When publishes is provided (e.g. from tests or a parent), use it directly.
  useEffect(() => {
    if (publishesProp === undefined) {
      fetchPublishes();
    }
  }, [publishesProp, fetchPublishes]);

  const publishes = publishesProp ?? hookPublishes;
  const isLoading = loading || (publishesProp === undefined ? hookLoading : false);

  if (isLoading) {
    return (
      <div className="publish-list" aria-label="Loading publishes">
        {[1, 2, 3].map((i) => (
          <SkeletonCard key={i} />
        ))}
      </div>
    );
  }

  if (publishes.length === 0) {
    return <EmptyState />;
  }

  return (
    <div className="publish-list" aria-label="Publishes list">
      {publishes.map((publish) => (
        <PublishCard key={publish.id} publish={publish} />
      ))}
    </div>
  );
}
