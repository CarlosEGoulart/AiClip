import React from 'react';
import type { ClipReview } from '../types';

interface ReviewListProps {
  clipReviews: ClipReview[];
  loading: boolean;
  onReview: (clipReview: ClipReview) => void;
}

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
      <div className="review-list review-list--loading" role="list" aria-label="Clip reviews loading">
        {[...Array(3)].map((_, i) => (
          <div key={i} className="review-list-skeleton" role="listitem" />
        ))}
      </div>
    );
  }

  if (clipReviews.length === 0) {
    return (
      <div className="review-list-empty" role="status">
        <p className="review-list-empty-text">
          No clips to review yet. Upload media to generate clips for review.
        </p>
        <a href="/" className="btn btn-primary review-list-empty-action">
          Upload Media
        </a>
      </div>
    );
  }

  return (
    <div className="review-list" role="list" aria-label="Clip reviews">
      {clipReviews.map((clipReview) => (
        <article
          key={clipReview.id}
          className="review-list-item"
          role="listitem"
        >
          <div className="review-list-item-thumb">
            <svg className="review-list-item-thumb-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <span className="review-list-item-duration">{formatDuration(clipReview.duration_ms)}</span>
            <span
              className={`review-list-item-badge review-list-item-badge--${clipReview.status}`}
              role="status"
              aria-label={`Status: ${statusLabels[clipReview.status]}`}
            >
              {statusLabels[clipReview.status]}
            </span>
          </div>
          <div className="review-list-item-info">
            <p className="review-list-item-title">Clip {clipReview.candidate_index + 1}</p>
            <p className="review-list-item-profile">{clipReview.render_profile_version}</p>
          </div>
          <button
            onClick={() => onReview(clipReview)}
            className="btn btn-primary review-list-item-button"
            aria-label={`Review clip ${clipReview.candidate_index + 1}`}
          >
            Review
          </button>
        </article>
      ))}
    </div>
  );
};
