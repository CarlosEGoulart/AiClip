export type SocialPublishStatus = 'pending' | 'uploading' | 'published' | 'failed';

export interface SocialPublish {
  id: number;
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
