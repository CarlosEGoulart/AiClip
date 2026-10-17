import React, { useState, useEffect, useCallback } from 'react';
import type { ClipReviewStatus } from '../types';
import { useClipReviews, useUpdateClipReviewStatus, useSubmitClipReviewFeedback } from '../hooks';
import { VideoPlayer } from './VideoPlayer';
import { ReviewActions } from './ReviewActions';
import { FeedbackForm } from './FeedbackForm';

/*
 * Deep-linking uses the native History API because the production tree has no
 * client-side router: react-router hooks throw outside a Router context and
 * crash the embedded review section on mount (plan Phase 7.5).
 */

function readClipReviewIdParam(): number | null {
  const param = new URLSearchParams(window.location.search).get('clipReviewId');
  if (!param) {
    return null;
  }
  const id = Number.parseInt(param, 10);
  return Number.isInteger(id) && id > 0 ? id : null;
}

function writeClipReviewIdParam(clipReviewId: number): void {
  // replace (not push): the browser Back button keeps leaving the project
  // instead of stepping through clip selections.
  const url = new URL(window.location.href);
  url.searchParams.set('clipReviewId', String(clipReviewId));
  window.history.replaceState(window.history.state, '', url);
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

interface ReviewPageProps {
  projectId: number;
  projectName: string;
  onBack: () => void;
}

export const ReviewPage: React.FC<ReviewPageProps> = ({
  projectId,
  projectName,
  onBack,
}) => {
  const [selectedClipReviewId, setSelectedClipReviewId] = useState<number | null>(null);
  const [hasUnsavedFeedback, setHasUnsavedFeedback] = useState(false);
  const [cachedVideoUrl, setCachedVideoUrl] = useState<string | null>(null);

  const { data: clipReviewsResponse, isLoading, refetch } = useClipReviews(projectId);
  const clipReviews = clipReviewsResponse?.data || [];
  const updateStatusMutation = useUpdateClipReviewStatus();
  const submitFeedbackMutation = useSubmitClipReviewFeedback();

  useEffect(() => {
    const clipReviewIdParam = readClipReviewIdParam();
    if (clipReviewIdParam !== null) {
      if (clipReviews.some((clipReview) => clipReview.id === clipReviewIdParam)) {
        setSelectedClipReviewId(clipReviewIdParam);
      }
    } else if (clipReviews.length > 0) {
      const firstPending = clipReviews.find((clipReview) => clipReview.status === 'pending');
      const firstClip = firstPending || clipReviews[0];
      setSelectedClipReviewId(firstClip.id);
      writeClipReviewIdParam(firstClip.id);
    }
  }, [clipReviews]);

  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (hasUnsavedFeedback) {
        e.preventDefault();
        e.returnValue = 'You have unsaved feedback. Are you sure you want to leave?';
        return e.returnValue;
      }
    };

    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [hasUnsavedFeedback]);

  // Memoize video_url per clipReviewId so refetches (which rotate the signed
  // URL) do not change <video src> and trigger a browser video reload / 404.
  useEffect(() => {
    setCachedVideoUrl(selectedClipReview?.video_url || null);
  }, [selectedClipReviewId]);

  const selectedClipReview = clipReviews.find((cr) => cr.id === selectedClipReviewId);
  const selectedIndex = clipReviews.findIndex((cr) => cr.id === selectedClipReviewId);

  const handleStatusChange = useCallback(
    async (status: Exclude<ClipReviewStatus, 'pending'>) => {
      if (!selectedClipReviewId) return;
      await updateStatusMutation.mutateAsync({ clipReviewId: selectedClipReviewId, status });
      refetch();
    },
    [selectedClipReviewId, updateStatusMutation, refetch]
  );

  const handleFeedbackSubmit = useCallback(
    async (feedback: string) => {
      if (!selectedClipReviewId) return;
      await submitFeedbackMutation.mutateAsync({ clipReviewId: selectedClipReviewId, feedback });
      refetch();
    },
    [selectedClipReviewId, submitFeedbackMutation, refetch]
  );

  const handleNext = useCallback(() => {
    if (selectedIndex < clipReviews.length - 1) {
      const nextClip = clipReviews[selectedIndex + 1];
      setSelectedClipReviewId(nextClip.id);
      writeClipReviewIdParam(nextClip.id);
    }
  }, [selectedIndex, clipReviews]);

  const handlePrevious = useCallback(() => {
    if (selectedIndex > 0) {
      const prevClip = clipReviews[selectedIndex - 1];
      setSelectedClipReviewId(prevClip.id);
      writeClipReviewIdParam(prevClip.id);
    }
  }, [selectedIndex, clipReviews]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      const activeElement = document.activeElement;
      const isInputFocused = activeElement?.tagName === 'INPUT' || activeElement?.tagName === 'TEXTAREA';
      if (isInputFocused) return;

      if (e.key === 'n' || e.key === 'N') {
        handleNext();
      } else if (e.key === 'p' || e.key === 'P') {
        handlePrevious();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [handleNext, handlePrevious]);

  if (isLoading) {
    return (
      <div className="review-loading">
        <div className="review-loading-spinner" aria-label="Loading reviews" role="status" />
      </div>
    );
  }

  return (
    <div className="review-page">
      <a href="#main-content" className="review-skip-link">
        Skip to main content
      </a>
      <header className="review-header">
        <div className="review-header-inner">
          <div className="review-header-bar">
            <div className="review-header-start">
              <button
                onClick={onBack}
                className="review-back-button"
                aria-label="Back to project"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                </svg>
              </button>
              <div>
                <h1 className="review-title">{projectName}</h1>
                <p className="review-counter">
                  {selectedClipReview ? `Clip ${selectedIndex + 1} of ${clipReviews.length}` : 'No clips'}
                </p>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main id="main-content" className="review-main">
        <div className="review-layout">
          <div className="review-layout-primary">
            {selectedClipReview ? (
              <>
                <VideoPlayer
                  videoUrl={cachedVideoUrl || selectedClipReview?.video_url}
                  poster={selectedClipReview.thumbnail_url || undefined}
                />

                <div className="bg-gray-100 dark:bg-gray-800/50 rounded-xl p-6 space-y-6">
                  <ReviewActions
                    currentStatus={selectedClipReview.status}
                    onStatusChange={handleStatusChange}
                    disabled={updateStatusMutation.isPending}
                  />

                  {selectedClipReview.status === 'rejected' || selectedClipReview.status === 'needs_changes' ? (
                    <FeedbackForm
                      clipReviewId={selectedClipReview.id}
                      status={selectedClipReview.status}
                      initialFeedback={selectedClipReview.feedback || ''}
                      onSubmit={handleFeedbackSubmit}
                      disabled={submitFeedbackMutation.isPending}
                      onUnsavedChange={setHasUnsavedFeedback}
                    />
                  ) : (
                    <div className="p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                      <p className="text-gray-600 dark:text-gray-300 leading-relaxed">
                        Feedback is optional for approved clips. You can add feedback after approving if needed.
                      </p>
                    </div>
                  )}

                  <div className="grid grid-cols-2 gap-4 pt-6">
                    <div>
                      <p className="text-sm font-medium text-gray-900 dark:text-white">
                        Duration: <span className="font-normal tabular-nums">{Math.floor(selectedClipReview.duration_ms / 60000)}:{String(Math.floor((selectedClipReview.duration_ms % 60000) / 1000)).padStart(2, '0')}</span>
                      </p>
                    </div>
                    <div>
                      <p className="text-sm font-medium text-gray-900 dark:text-white">
                        Resolution: <span className="font-normal tabular-nums">{selectedClipReview.width}×{selectedClipReview.height}</span>
                      </p>
                    </div>
                    <div>
                      <p className="text-sm font-medium text-gray-900 dark:text-white">
                        Candidate: <span className="font-normal">#{selectedClipReview.candidate_index + 1}</span>
                      </p>
                    </div>
                    <div>
                      <p className="text-sm font-medium text-gray-900 dark:text-white truncate">
                        Render Profile: <span className="font-normal">{selectedClipReview.render_profile_version}</span>
                      </p>
                    </div>
                  </div>
                </div>
              </>
            ) : (
              <div className="review-empty">
                <p className="review-empty-text">Select a clip to review</p>
              </div>
            )}
          </div>

          <div className="review-layout-secondary">
            <div className="review-sidebar">
              <h2 className="review-sidebar-title">Clips to Review</h2>
              <div className="space-y-3" role="list" aria-label="Clip review list">
                {clipReviews.map((clipReview, index) => {
                  const statusColor = statusColors[clipReview.status] || statusColors.pending;
                  const isSelected = clipReview.id === selectedClipReviewId;

                  return (
                    <button
                      key={clipReview.id}
                      onClick={() => {
                        setSelectedClipReviewId(clipReview.id);
                        writeClipReviewIdParam(clipReview.id);
                      }}
                      className={`
                        w-full text-left p-4 rounded-lg border-2 transition-colors
                        focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900
                        ${isSelected
                          ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                          : 'border-transparent hover:bg-gray-50 dark:hover:bg-gray-700/50'}
                      `}
                      aria-current={isSelected ? 'true' : 'false'}
                      role="listitem"
                      style={{ touchAction: 'manipulation' }}
                    >
                      <div className="flex items-center justify-between mb-2">
                        <span className="text-sm font-medium text-gray-900 dark:text-white">
                          Clip {index + 1}
                        </span>
                        <span
                          className={`px-2.5 py-1 rounded-full text-sm font-medium ${statusColor.bg} ${statusColor.text}`}
                        >
                          {statusLabels[clipReview.status]}
                        </span>
                      </div>
                      <div className="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                        <span className="tabular-nums">#{clipReview.candidate_index + 1}</span>
                        <span className="tabular-nums">{Math.floor(clipReview.duration_ms / 60000)}:{String(Math.floor((clipReview.duration_ms % 60000) / 1000)).padStart(2, '0')}</span>
                      </div>
                    </button>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>
  );
};
