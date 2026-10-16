export type ClipReviewStatus = 'pending' | 'approved' | 'rejected' | 'needs_changes';

export interface ClipReview {
  id: number;
  derived_asset_id: number;
  status: ClipReviewStatus;
  feedback: string | null;
  video_url: string;
  thumbnail_url: string | null;
  duration_ms: number;
  width: number;
  height: number;
  candidate_index: number;
  render_profile_version: string;
  created_at: string;
  updated_at: string;
}

export interface ClipReviewListResponse {
  data: ClipReview[];
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    path: string;
    per_page: number;
    to: number | null;
    total: number;
  };
}

export interface UpdateClipReviewStatusRequest {
  status: Exclude<ClipReviewStatus, 'pending'>;
}

export interface SubmitClipReviewFeedbackRequest {
  feedback: string;
}