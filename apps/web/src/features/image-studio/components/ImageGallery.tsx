import React from 'react';
import type { ImageItem, ImageStatus } from '../types';

interface ImageGalleryProps {
  images: ImageItem[];
  loading: boolean;
  error: string | null;
  onRetry: () => void;
}

/*
 * The status class names keep the exact tokens e2e/image-studio.spec.ts
 * asserts on (bg-gray-100 / bg-green-100 / bg-red-100); they are real
 * classes defined in index.css alongside the shared badge base class.
 */
const statusBadgeClasses: Record<ImageStatus, string> = {
  pending: 'image-gallery-badge bg-gray-100',
  generated: 'image-gallery-badge bg-green-100',
  failed: 'image-gallery-badge bg-red-100',
};

const statusLabels: Record<ImageStatus, string> = {
  pending: 'Pending',
  generated: 'Generated',
  failed: 'Failed',
};

const PROMPT_EXCERPT_LENGTH = 60;

const excerpt = (prompt: string): string =>
  prompt.length > PROMPT_EXCERPT_LENGTH
    ? `${prompt.slice(0, PROMPT_EXCERPT_LENGTH)}…`
    : prompt;

const formatBytes = (bytes: number): string => {
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`;
  }
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

/*
 * Metadata gallery for the project's generated images. Entries show a
 * prompt excerpt, dimensions, file size, and a status badge (Pending
 * gray with an in-progress hint, Generated green, Failed red). Loading,
 * empty, and load-error states with retry are all rendered. No image
 * bytes are fetched in this slice: the gallery is metadata + status only.
 */
export const ImageGallery: React.FC<ImageGalleryProps> = ({
  images,
  loading,
  error,
  onRetry,
}) => {
  const pendingCount = images.filter((image) => image.status === 'pending').length;
  const liveAnnouncement =
    pendingCount === 1
      ? '1 image is generating'
      : pendingCount > 1
        ? `${pendingCount} images are generating`
        : '';

  let content: React.ReactNode;

  if (loading) {
    content = (
      <div role="status" aria-label="Loading images" className="image-gallery-loading">
        {[...Array(3)].map((_, index) => (
          <div key={index} className="image-gallery-skeleton">
            <div className="image-gallery-skeleton-line image-gallery-skeleton-line--title" />
            <div className="image-gallery-skeleton-line image-gallery-skeleton-line--meta" />
          </div>
        ))}
      </div>
    );
  } else if (error) {
    content = (
      <div className="image-gallery-state">
        <p className="error-message">{error}</p>
        <button
          type="button"
          onClick={onRetry}
          className="image-gallery-retry-button"
        >
          Retry loading images
        </button>
      </div>
    );
  } else if (images.length === 0) {
    content = (
      <div className="image-gallery-state" role="status">
        <p className="image-gallery-state-title">No images yet</p>
        <p className="image-gallery-state-subtitle">
          Generate your first image with the form.
        </p>
      </div>
    );
  } else {
    content = (
      <div role="list" aria-label="Generated images" className="image-gallery-list">
        {images.map((image) => (
          <article key={image.id} role="listitem" className="image-gallery-card">
            <div className="image-gallery-card-head">
              <p className="image-gallery-card-prompt">{excerpt(image.prompt)}</p>
              <span
                aria-label={`Status: ${statusLabels[image.status]}`}
                className={statusBadgeClasses[image.status]}
              >
                {statusLabels[image.status]}
              </span>
            </div>

            <div className="image-gallery-card-meta">
              <span>
                {image.width} × {image.height}
              </span>
              <span>{formatBytes(image.size_bytes)}</span>
            </div>

            {image.status === 'pending' && (
              <p className="image-gallery-card-hint">Generating…</p>
            )}
          </article>
        ))}
      </div>
    );
  }

  // The live region is always mounted (before any content changes) so
  // screen readers reliably announce pending-status transitions.
  return (
    <div>
      <div aria-live="polite" className="image-gallery-live">
        {liveAnnouncement}
      </div>
      {content}
    </div>
  );
};
