import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { ClipReviewStatus } from './types';
import {
  fetchClipReviews,
  fetchClipReview,
  updateClipReviewStatus,
  submitClipReviewFeedback,
} from './api';

export function useClipReviews(projectId: number) {
  return useQuery({
    queryKey: ['clipReviews', projectId],
    queryFn: () => fetchClipReviews(projectId),
    enabled: !!projectId,
    // No staleTime override: the review queue refetches on every review
    // section mount so the queue is always fresh when the user returns
    // (React Query defaults: staleTime 0 + refetchOnMount).
  });
}

export function useClipReview(clipReviewId: number) {
  return useQuery({
    queryKey: ['clipReview', clipReviewId],
    queryFn: () => fetchClipReview(clipReviewId),
    enabled: !!clipReviewId,
    staleTime: 30000,
  });
}

export function useUpdateClipReviewStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ clipReviewId, status }: { clipReviewId: number; status: Exclude<ClipReviewStatus, 'pending'> }) =>
      updateClipReviewStatus(clipReviewId, status),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['clipReview', variables.clipReviewId] });
      queryClient.invalidateQueries({ queryKey: ['clipReviews'] });
    },
  });
}

export function useSubmitClipReviewFeedback() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ clipReviewId, feedback }: { clipReviewId: number; feedback: string }) =>
      submitClipReviewFeedback(clipReviewId, feedback),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: ['clipReview', variables.clipReviewId] });
      queryClient.invalidateQueries({ queryKey: ['clipReviews'] });
    },
  });
}