export type ImageStatus = 'pending' | 'generated' | 'failed';

export interface StylePreset {
  id: string;
  label: string;
}

export interface DimensionPreset {
  id: string;
  label: string;
  width: number;
  height: number;
}

/**
 * Fixed style preset catalog, mirroring the backend slice constant
 * (GenerateImageRequest::STYLE_PRESET_IDS). There is no style-presets API
 * endpoint; the frontend mirrors the catalog as constants.
 */
export const STYLE_PRESETS: StylePreset[] = [
  { id: 'photorealistic', label: 'Photorealistic' },
  { id: 'illustration', label: 'Illustration' },
  { id: 'cinematic', label: 'Cinematic' },
  { id: 'digital_art', label: 'Digital Art' },
];

export const DEFAULT_STYLE_PRESET = 'photorealistic';

/**
 * Fixed dimension presets, mirroring the backend allowed width/height pairs
 * (GenerateImageRequest::DIMENSION_PRESETS).
 */
export const DIMENSION_PRESETS: DimensionPreset[] = [
  { id: 'square', label: 'Square', width: 1024, height: 1024 },
  { id: 'vertical', label: 'Vertical 9:16', width: 1080, height: 1920 },
  { id: 'landscape', label: 'Landscape 16:9', width: 1920, height: 1080 },
];

export const DEFAULT_DIMENSION_PRESET = 'square';

export const PROMPT_MAX_LENGTH = 1000;

export interface ImageItem {
  id: number;
  project_id: number;
  prompt: string;
  parameters: {
    style_preset: string;
    width: number;
    height: number;
  } | null;
  mime_type: string;
  size_bytes: number;
  width: number;
  height: number;
  status: ImageStatus;
  /**
   * Time-limited signed API URL (1 hour) minted only for generated
   * images; null for pending/failed rows or when no artifact exists.
   * The API always sends the key.
   */
  download_url: string | null;
  created_at: string;
  updated_at: string;
}

export interface ImageListResponse {
  data: ImageItem[];
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

export interface GenerateImageRequest {
  projectId: number;
  prompt: string;
  stylePreset: string;
  width: number;
  height: number;
}
