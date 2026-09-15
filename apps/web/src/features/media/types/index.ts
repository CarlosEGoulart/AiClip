export interface MediaAsset {
  id: number;
  project_id: number;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  status: 'stored';
  created_at: string;
  updated_at: string;
}

export interface MediaAssetsResponse {
  data: MediaAsset[];
}

export interface MediaAssetResponse {
  data: MediaAsset;
}

export type MediaErrorType =
  | 'validation'
  | 'unauthorized'
  | 'throttle'
  | 'csrf'
  | 'network'
  | 'server'
  | 'file-too-large';

export interface MediaError {
  type: MediaErrorType;
  errors?: Record<string, string[]>;
  message?: string;
}
