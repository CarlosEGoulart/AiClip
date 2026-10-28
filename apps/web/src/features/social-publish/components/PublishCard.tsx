import type { SocialPublish } from '../types';
import '../design-tokens.css';

interface PublishCardProps {
  publish: SocialPublish;
}

function StatusBadge({ status }: { status: string }) {
  const statusConfig: Record<string, { label: string; className: string }> = {
    pending: { label: 'Pending', className: 'pending' },
    uploading: { label: 'Uploading', className: 'uploading' },
    published: { label: 'Published', className: 'published' },
    failed: { label: 'Failed', className: 'failed' },
  };

  const config = statusConfig[status] || { label: status, className: '' };

  return (
    <span
      className={`publish-status ${config.className}`}
      role="status"
      aria-live="polite"
    >
      {config.label}
    </span>
  );
}

export function PublishCard({ publish }: PublishCardProps) {
  const isPublished = publish.status === 'published';
  const isFailed = publish.status === 'failed';

  return (
    <article className="publish-card" data-testid="publish-card">
      <div className="card-header">
        <div className="card-info">
          <h3 className="card-title">{publish.title}</h3>
          <p className="card-platform">{publish.platform_label}</p>
        </div>
        <StatusBadge status={publish.status} />
      </div>

      {isPublished && publish.video_url && (
        <a
          href={publish.video_url}
          target="_blank"
          rel="noopener noreferrer"
          className="card-video-link"
          aria-label="View on YouTube"
        >
          <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M10 15h5.2l-4-5v5zM10 5L4.2 10v4l5.2 5V10z" />
          </svg>
          View on YouTube
        </a>
      )}

      {isFailed && publish.error_message && (
        <div className="card-error" role="alert">
          {publish.error_message}
        </div>
      )}

      <div className="card-meta">
        <span className="card-created">
          {new Date(publish.created_at).toLocaleDateString()}
        </span>
      </div>
    </article>
  );
}
