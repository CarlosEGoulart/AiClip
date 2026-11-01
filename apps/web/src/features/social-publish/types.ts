export type SocialPublishStatus = 'pending' | 'uploading' | 'published' | 'failed';

export interface SocialPublish {
  id: number;
  user_id: number;
  social_connection_id: number;
  clip_review_id: number;
  campaign_id: number | null;
  platform: 'youtube' | 'instagram' | 'tiktok';
  platform_label: string;
  status: SocialPublishStatus;
  external_video_id: string | null;
  video_url: string | null;
  title: string;
  description: string | null;
  error_message: string | null;
  created_at: string;
  updated_at: string;
}

export type SocialPublishCampaignStatus =
  | 'pending'
  | 'processing'
  | 'published'
  | 'partially_published'
  | 'failed'
  | 'cancelled';

export interface SocialPublishCampaign {
  id: number;
  user_id: number;
  clip_review_id: number;
  status: SocialPublishCampaignStatus;
  status_label: string;
  publishes: SocialPublish[];
  created_at: string;
  updated_at: string;
}

export interface PublishCampaignTarget {
  platform: 'youtube' | 'instagram' | 'tiktok';
  title: string;
  description?: string;
}

export interface PublishCampaignRequest {
  clip_review_id: number;
  targets: PublishCampaignTarget[];
}

export interface PublishCampaignResponse {
  campaign_id: number;
  status: SocialPublishCampaignStatus;
  target_count: number;
}

export interface PublishCampaignTargetResponse {
  id: number;
  platform: 'youtube' | 'instagram' | 'tiktok';
  status: SocialPublishStatus;
  external_video_id: string | null;
  video_url: string | null;
  title: string;
  description: string | null;
  error_message: string | null;
}

export interface CampaignListResponse {
  data: SocialPublishCampaign[];
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
    per_page: number;
    to: number | null;
    total: number;
  };
}

export interface PublishClipRequest {
  clip_review_id: number;
  platform: 'youtube' | 'instagram' | 'tiktok';
  title: string;
  description?: string;
}

export interface PublishResponse {
  publish_id: number;
  status: SocialPublishStatus;
  platform: 'youtube' | 'instagram' | 'tiktok';
}

export interface PublishResponseWrapper {
  data: PublishResponse;
}

export interface PublishListResponse {
  data: SocialPublish[];
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
    per_page: number;
    to: number | null;
    total: number;
  };
}

export interface PublishStatusResponse {
  id: number;
  platform: 'youtube' | 'instagram' | 'tiktok';
  platform_label: string;
  status: SocialPublishStatus;
  external_video_id: string | null;
  video_url: string | null;
  title: string;
  description: string | null;
  error_message: string | null;
}

export interface PublishStatusResponseWrapper {
  data: PublishStatusResponse;
}

export type SocialPublishErrorType =
  | 'validation'
  | 'unauthorized'
  | 'throttle'
  | 'csrf'
  | 'network'
  | 'server'
  | 'conflict';

export interface SocialPublishError {
  type: SocialPublishErrorType;
  errors?: Record<string, string[]>;
  message?: string;
}
