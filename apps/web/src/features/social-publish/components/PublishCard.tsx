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

function PlatformIcon({ platform }: { platform: string }) {
  if (platform === 'instagram') {
    return (
      <svg
        className="w-4 h-4"
        fill="currentColor"
        viewBox="0 0 24 24"
        aria-hidden="true"
      >
        <path d="M12 0C8.74 0 5.76 1.77 3.62 5.2c-1.06 1.71-1.73 4.03-1.42 6.68.23 1.93 1.25 3.68 2.91 4.85 1.19.8 2.65.9 4.1 1.24 1.75.4 3.55.58 5.36.58s3.61-.18 5.36-.58c1.45-.34 2.91-.44 4.1-1.24 1.66-1.17 2.68-2.92 2.91-4.85-.31-2.65-.36-4.97-1.42-6.68C18.24 1.77 15.26 0 12 0zm0 2.16c3.21 0 5.83 1.3 7.92 3.38 1.52 1.5 2.38 3.52 2.4 5.74.02 1.49-.15 2.88-.95 4.38-1.14 2.08-2.88 3.67-5.12 4.43-1.95.66-4.05.85-6.15.64-2.11-.21-4.21-.02-6.15-.64-2.24-.76-3.98-2.35-5.12-4.43-.8-1.5-.97-2.89-.95-4.38 0-2.22.88-4.24 2.4-5.74 2.09-2.08 4.71-3.38 7.92-3.38z">
          <path d="M12 1.85c-3.82 0-6.92 2.15-8.07 5.04-.62 1.59-.48 3.39.32 4.79.5.87 1.38 1.47 2.38 1.73.42.11.9.18 1.42.21 1.25.08 2.55.13 3.86.13 1.31 0 2.61-.05 3.86-.13.52-.03 1-.1 1.42-.21 1-.26 1.88-.86 2.38-1.73.8-1.4.94-3.2.32-4.79C18.92 4 15.82 1.85 12 1.85zm0 2.15c-2.42 0-4.4 1.34-5.86 3.48-1 .57-1.48 1.44-1.36 2.42.06.47.36.86.85 1.13 1.04.57 2.33.94 3.65.94 1.32 0 2.61-.37 3.65-.94.49-.27.79-.66.85-1.13.12-.98-.36-1.85-1.36-2.42-1.46-2.14-3.44-3.48-5.86-3.48z">
            <path d="M12 6.63c1.62 0 2.93 1.06 3.34 2.46h-.01l-.01.01-.01.01-.07.07c-.02.02-.05.04-.07.07-.02.02-.04.05-.07.07h-.01c-1.75 2.85-4.55 4.52-7.6 4.06-.03 0-.05-.01-.08-.01-.03 0-.05.01-.08.01-3.05.46-5.85-1.21-7.6-4.06h-.01l-.01-.01-.01-.01-.07-.07c-.02-.02-.05-.04-.07-.07-.02-.02-.04-.05-.07-.07l-.01-.01C3.07 7.69 4.38 6.63 6 6.63c1.62 0 2.93 1.06 3.34 2.46h.01l.01.01.01.01.07.07c.02.02.05.04.07.07.02.02.04.05.07.07h.01c1.75 2.85 4.55 4.52 7.6 4.06.03 0 .05-.01.08-.01.03 0 .05.01.08.01 3.05-.46 5.85-1.21 7.6-4.06h.01l.01-.01.01-.01.07-.07c.02-.02.05-.04.07-.07.02-.02.04-.05.07-.07l.01-.01C14.93 7.69 13.62 6.63 12 6.63z">
            </path>
          </path>
        </path>
      </svg>
    );
  }

  // YouTube icon (default)
  return (
    <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path d="M10 15h5.2l-4-5v5zM10 5L4.2 10v4l5.2 5V10z" />
    </svg>
  );
}

export function PublishCard({ publish }: PublishCardProps) {
  const isPublished = publish.status === 'published';
  const isFailed = publish.status === 'failed';

  return (
    <article className="publish-card" data-testid="publish-card">
      <div className="card-header">
        <div className="card-info">
          <div className="card-title-row">
            <h3 className="card-title">{publish.title}</h3>
            <PlatformIcon platform={publish.platform} />
          </div>
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
          aria-label={`View on ${publish.platform_label}`}
        >
          <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M10 15h5.2l-4-5v5zM10 5L4.2 10v4l5.2 5V10z" />
          </svg>
          View on {publish.platform_label}
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
