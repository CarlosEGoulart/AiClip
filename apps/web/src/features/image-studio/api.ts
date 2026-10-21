import type {
  GenerateImageRequest,
  ImageItem,
  ImageListResponse,
} from './types';

const API_BASE = '/api/v1';

async function getCsrfToken(): Promise<string> {
  const response = await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
  });
  if (!response.ok) {
    throw new Error('Failed to get CSRF token');
  }
  // CSRF token is set as cookie, we need to read it from document.cookie
  const cookies = document.cookie.split('; ');
  const xsrfCookie = cookies.find((c) => c.startsWith('XSRF-TOKEN='));
  return xsrfCookie ? decodeURIComponent(xsrfCookie.split('=')[1]) : '';
}

async function fetchWithCsrf<T>(
  url: string,
  options: RequestInit = {}
): Promise<T> {
  const xsrfToken = await getCsrfToken();

  const response = await fetch(url, {
    ...options,
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': xsrfToken,
      ...options.headers,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Request failed' }));
    throw new Error(error.message || `HTTP ${response.status}`);
  }

  return response.json();
}

export async function generateImage(
  request: GenerateImageRequest
): Promise<{ data: ImageItem }> {
  return fetchWithCsrf<{ data: ImageItem }>(`${API_BASE}/images/generate`, {
    method: 'POST',
    body: JSON.stringify({
      project_id: request.projectId,
      prompt: request.prompt,
      style_preset: request.stylePreset,
      width: request.width,
      height: request.height,
    }),
  });
}

export async function fetchImages(projectId: number): Promise<ImageListResponse> {
  // The backend wraps the pagination collection in a top-level "data" key
  // (ImageController::index: response()->json(['data' => new
  // ImageCollection(...)])). Unwrap it here so consumers receive the
  // standard pagination object: { data: ImageItem[], links, meta }.
  const json = await fetchWithCsrf<{ data: ImageListResponse }>(
    `${API_BASE}/images?project_id=${projectId}`
  );
  return json.data;
}
