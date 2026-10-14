import React from 'react';
import type { ClipReview } from '../types';

interface ReviewListProps {
  clipReviews: ClipReview[];
  loading: boolean;
  onReview: (clipReview: ClipReview) => void;
}

const statusColors: Record<string, { bg: string; text: string }> = {
  pending: { bg: 'bg-gray-100 dark:bg-gray-800', text: 'text-gray-700 dark:text-gray-300' },
  approved: { bg: 'bg-green-100 dark:bg-green-900/30', text: 'text-green-700 dark:text-green-400' },
  rejected: { bg: 'bg-red-100 dark:bg-red-900/30', text: 'text-red-700 dark:text-red-400' },
  needs_changes: { bg: 'bg-orange-100 dark:bg-orange-900/30', text: 'text-orange-700 dark:text-orange-400' },
};

const statusLabels: Record<string, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  needs_changes: 'Needs Changes',
};

const formatDuration = (ms: number): string => {
  const seconds = Math.floor(ms / 1000);
  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;
  return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
};

export const ReviewList: React.FC<ReviewListProps> = ({
  clipReviews,
  loading,
  onReview,
}) => {
  if (loading) {
    return (
      <div className="space-y-4" role="list" aria-label="Clip reviews loading">
        {[...Array(3)].map((_, i) => (
          <div key={i} role="listitem" className="bg-white dark:bg-gray-800 rounded-xl overflow-hidden animate-pulse">
            <div className="aspect-[16/9] bg-gray-200 dark:bg-gray-700" />
            <div className="p-5 space-y-3">
              <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-1/3" />
              <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-full" />
              <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-2/3" />
            </div>
          </div>
        ))}
      </div>
    );
  }

  if (clipReviews.length === 0) {
    return (
      <div className="py-16 bg-white dark:bg-gray-800 rounded-xl" role="status">
        <p className="text-center text-gray-600 dark:text-gray-400 text-lg leading-relaxed">
          No clips to review yet. Upload media to generate clips for review.
        </p>
        <a
          href="/upload"
          className="mt-6 inline-flex items-center justify-center px-5 py-2.5 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 transition-colors"
        >
          <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
          </svg>
          Upload Media
        </a>
      </div>
    );
  }

  return (
    <div className="space-y-4" role="list" aria-label="Clip reviews">
      {clipReviews.map((clipReview, index) => {
        const statusColor = statusColors[clipReview.status] || statusColors.pending;
        const isEven = index % 2 === 0;

        return (
          <article
            key={clipReview.id}
            className={`
              rounded-xl overflow-hidden
              transition-colors duration-200
              ${isEven
                ? 'bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700/50'
                : 'bg-gray-50 dark:bg-gray-800/50 hover:bg-gray-100 dark:hover:bg-gray-700'}
            `}
            role="listitem"
          >
            <div className={isEven ? 'flex' : 'flex flex-row-reverse'}>
              <div className="relative w-full md:w-2/3 min-w-0">
                <div className="aspect-[16/9] bg-black relative">
                  <div className="absolute inset-0 flex items-center justify-center">
                    <svg className="w-12 h-12 text-gray-600 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                  </div>
                  <span className="absolute bottom-3 right-3 bg-black/70 text-white text-sm px-2.5 py-1 rounded font-medium tabular-nums">
                    {formatDuration(clipReview.duration_ms)}
                  </span>
                  <span
                    className={`absolute top-3 left-3 px-2.5 py-1 rounded-full text-sm font-medium ${statusColor.bg} ${statusColor.text}`}
                    role="status"
                    aria-label={`Status: ${statusLabels[clipReview.status]}`}
                  >
                    {statusLabels[clipReview.status]}
                  </span>
                </div>
              </div>
              <div className="w-full md:w-1/3 p-5 flex flex-col justify-between min-w-0">
                <div>
                  <p className="text-lg font-semibold text-gray-900 dark:text-white truncate">
                    Clip {clipReview.candidate_index + 1}
                  </p>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400 truncate">
                    {clipReview.render_profile_version}
                  </p>
                </div>
                <button
                  onClick={() => onReview(clipReview)}
                  className="mt-4 w-full py-2.5 px-4 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 transition-colors touch-action-manipulation"
                  aria-label={`Review clip ${clipReview.candidate_index + 1}`}
                >
                  Review
                </button>
              </div>
            </div>
          </article>
        );
      })}
    </div>
  );
};