import type {
  ClipReview,
  ClipReviewListResponse,
  ClipReviewStatus,
} from './types';

export type { ClipReviewStatus } from './types';

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

export async function fetchClipReviews(projectId: number): Promise<ClipReviewListResponse> {
  // The backend wraps the pagination collection in a top-level "data" key
  // (ClipReviewController::index: response()->json(['data' => new
  // ClipReviewCollection(...)])). Unwrap it here so consumers receive the
  // standard pagination object: { data: ClipReview[], links, meta }.
  const json = await fetchWithCsrf<{ data: ClipReviewListResponse }>(
    `${API_BASE}/projects/${projectId}/clip-reviews`
  );
  return json.data;
}

export async function fetchClipReview(clipReviewId: number): Promise<{ data: ClipReview }> {
  return fetchWithCsrf<{ data: ClipReview }>(`${API_BASE}/clip-reviews/${clipReviewId}`);
}

export async function updateClipReviewStatus(
  clipReviewId: number,
  status: Exclude<ClipReviewStatus, 'pending'>
): Promise<{ data: ClipReview }> {
  return fetchWithCsrf<{ data: ClipReview }>(`${API_BASE}/clip-reviews/${clipReviewId}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  });
}

export async function submitClipReviewFeedback(
  clipReviewId: number,
  feedback: string
): Promise<{ data: ClipReview }> {
  return fetchWithCsrf<{ data: ClipReview }>(`${API_BASE}/clip-reviews/${clipReviewId}/feedback`, {
    method: 'POST',
    body: JSON.stringify({ feedback }),
  });
}

export function getVideoUrl(clipReviewId: number): string {
  return `${API_BASE}/clip-reviews/${clipReviewId}/video`;
}