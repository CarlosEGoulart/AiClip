import { useEffect, useState } from 'react';
import type { SocialPublish } from '../types';
import { PublishCard } from './PublishCard';
import { PublishCampaignDialog } from './PublishCampaignDialog';
import { usePublishes } from '../hooks';
import { useSocialConnections } from '../../social/hooks';
import { useProjects } from '../../projects/hooks';
import { fetchClipReviews } from '../../clip-review/api';
import type { ClipReview } from '../../clip-review/types';
import '../design-tokens.css';

type Platform = 'youtube' | 'instagram' | 'tiktok';

interface PublishListProps {
  publishes?: SocialPublish[];
  loading?: boolean;
  availablePlatforms?: string[];
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

export function PublishList({
  publishes: publishesProp,
  loading = false,
  availablePlatforms,
}: PublishListProps) {
  const { publishes: hookPublishes, loading: hookLoading, fetchPublishes } =
    usePublishes();

  const { connections, fetchConnections } = useSocialConnections();
  const { projects, fetchProjects } = useProjects();

  const [clipReviewId, setClipReviewId] = useState<number | null>(null);
  const [campaignDialogOpen, setCampaignDialogOpen] = useState(false);

  // When used as a standalone route (no publishes prop), fetch publishes.
  useEffect(() => {
    if (publishesProp === undefined) {
      fetchPublishes();
    }
  }, [publishesProp, fetchPublishes]);

  // Fetch connected platforms when not provided as a prop.
  useEffect(() => {
    if (availablePlatforms === undefined) {
      fetchConnections();
    }
  }, [availablePlatforms, fetchConnections]);

  // Fetch projects, then resolve an approved clip review for campaign creation.
  useEffect(() => {
    if (projects.length === 0) {
      fetchProjects();
    }
  }, [projects, fetchProjects]);

  useEffect(() => {
    if (projects.length > 0 && clipReviewId === null) {
      fetchClipReviews(projects[0].id)
        .then((response) => {
          const reviews = (response.data || []) as ClipReview[];
          const ready = reviews.find((r) => r.status === 'approved');
          if (ready) setClipReviewId(ready.id);
        })
        .catch(() => {
          // Campaign dialog unavailable if clip reviews cannot be loaded.
        });
    }
  }, [projects, clipReviewId]);

  const platforms =
    availablePlatforms ??
    connections
      .filter((c) => c.status === 'connected')
      .map((c) => c.platform);

  const publishes = publishesProp ?? hookPublishes;
  const isLoading = loading || (publishesProp === undefined ? hookLoading : false);

  const canPublishCampaign = clipReviewId !== null && platforms.length > 0;

  if (isLoading) {
    return (
      <div className="publish-list" aria-label="Loading publishes">
        {[1, 2, 3].map((i) => (
          <SkeletonCard key={i} />
        ))}
      </div>
    );
  }

  const publishListContent = (
    <>
      {publishes.map((publish) => (
        <PublishCard key={publish.id} publish={publish} />
      ))}
    </>
  );

  if (publishes.length === 0) {
    return (
      <div className="publish-list" aria-label="Publishes list">
        <div className="publish-empty" role="status" aria-live="polite">
          <p>No publishes yet</p>
          <p>Publish your first approved clip to YouTube.</p>
        </div>
        {canPublishCampaign && (
          <button
            type="button"
            onClick={() => setCampaignDialogOpen(true)}
            className="btn btn-primary"
            aria-label="Publish to Multiple Platforms"
          >
            Publish to Multiple Platforms
          </button>
        )}
        {clipReviewId !== null && (
          <PublishCampaignDialog
            isOpen={campaignDialogOpen}
            onClose={() => setCampaignDialogOpen(false)}
            clipReviewId={clipReviewId}
            availablePlatforms={platforms as Platform[]}
            onCampaignCreated={() => setCampaignDialogOpen(false)}
          />
        )}
      </div>
    );
  }

  return (
    <div className="publish-list" aria-label="Publishes list">
      <div className="publish-list-toolbar">
        {canPublishCampaign && (
          <button
            type="button"
            onClick={() => setCampaignDialogOpen(true)}
            className="btn btn-primary"
            aria-label="Publish to Multiple Platforms"
          >
            Publish to Multiple Platforms
          </button>
        )}
      </div>
      {publishListContent}
      {clipReviewId !== null && (
        <PublishCampaignDialog
          isOpen={campaignDialogOpen}
          onClose={() => setCampaignDialogOpen(false)}
          clipReviewId={clipReviewId}
          availablePlatforms={platforms as Platform[]}
          onCampaignCreated={() => setCampaignDialogOpen(false)}
        />
      )}
    </div>
  );
}
