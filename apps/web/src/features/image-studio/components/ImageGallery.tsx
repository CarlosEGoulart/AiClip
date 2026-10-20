import React from 'react';
import type { ImageItem, ImageStatus } from '../types';

interface ImageGalleryProps {
  images: ImageItem[];
  loading: boolean;
  error: string | null;
  onRetry: () => void;
}

const statusBadgeClasses: Record<ImageStatus, string> = {
  pending: 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300',
  generated: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
  failed: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
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
      <div role="status" aria-label="Loading images" className="space-y-3">
        {[...Array(3)].map((_, index) => (
          <div
            key={index}
            className="bg-white dark:bg-gray-800 rounded-xl p-5 space-y-3 animate-pulse"
          >
            <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-2/3" />
            <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3" />
          </div>
        ))}
      </div>
    );
  } else if (error) {
    content = (
      <div className="py-12 text-center bg-white dark:bg-gray-800 rounded-xl">
        <p className="text-red-600 dark:text-red-400">{error}</p>
        <button
          type="button"
          onClick={onRetry}
          className={
            'mt-4 inline-flex items-center justify-center min-h-11 px-5 rounded-lg ' +
            'bg-blue-600 font-medium text-white transition-colors hover:bg-blue-700 ' +
            'focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ' +
            'focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:focus-visible:ring-offset-gray-900'
          }
        >
          Retry loading images
        </button>
      </div>
    );
  } else if (images.length === 0) {
    content = (
      <div className="py-12 text-center bg-white dark:bg-gray-800 rounded-xl" role="status">
        <p className="text-lg text-gray-600 dark:text-gray-400">No images yet</p>
        <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
          Generate your first image with the form.
        </p>
      </div>
    );
  } else {
    content = (
      <div role="list" aria-label="Generated images" className="space-y-3">
        {images.map((image) => (
          <article
            key={image.id}
            role="listitem"
            className="bg-white dark:bg-gray-800 rounded-xl p-5"
          >
            <div className="flex items-start justify-between gap-3">
              <p className="min-w-0 text-sm font-medium text-gray-900 dark:text-white break-words">
                {excerpt(image.prompt)}
              </p>
              <span
                aria-label={`Status: ${statusLabels[image.status]}`}
                className={`shrink-0 rounded-full px-2.5 py-1 text-sm font-medium ${statusBadgeClasses[image.status]}`}
              >
                {statusLabels[image.status]}
              </span>
            </div>

            <div className="mt-3 flex flex-wrap gap-4 text-sm text-gray-500 dark:text-gray-400">
              <span className="tabular-nums">
                {image.width} × {image.height}
              </span>
              <span className="tabular-nums">{formatBytes(image.size_bytes)}</span>
            </div>

            {image.status === 'pending' && (
              <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Generating…</p>
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
      <div aria-live="polite" className="sr-only">
        {liveAnnouncement}
      </div>
      {content}
    </div>
  );
};
