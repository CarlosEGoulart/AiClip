export type SocialConnectionStatus = 'connected' | 'expired' | 'revoked' | 'error';

export interface SocialConnection {
  id: number;
  platform: 'youtube' | 'instagram' | 'tiktok';
  platform_label: string;
  display_name: string;
  avatar_url: string | null;
  channel_url: string | null;
  status: SocialConnectionStatus;
  last_error: string | null;
  created_at: string;
  updated_at: string;
}

export interface SocialConnectionsResponse {
  data: SocialConnection[];
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

export interface ConnectResponse {
  authorization_url: string;
  state: string;
  connection_id: number;
}

export interface ConnectResponseWrapper {
  data: ConnectResponse;
}

export interface ConnectionStatusResponse {
  id: number;
  status: SocialConnectionStatus;
  last_error: string | null;
  access_token_expires_at: string | null;
}

export interface ConnectionStatusResponseWrapper {
  data: ConnectionStatusResponse;
}

export type SocialErrorType =
  | 'validation'
  | 'unauthorized'
  | 'throttle'
  | 'csrf'
  | 'network'
  | 'server'
  | 'conflict';

export interface SocialError {
  type: SocialErrorType;
  errors?: Record<string, string[]>;
  message?: string;
}

export type Platform = 'youtube' | 'instagram' | 'tiktok';

export interface PlatformInfo {
  id: Platform;
  label: string;
  icon: string; // SVG or component name
  description: string;
}