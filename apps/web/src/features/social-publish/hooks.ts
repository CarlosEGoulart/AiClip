import { useState, useCallback } from 'react';
import type {
  SocialPublish,
  PublishClipRequest,
  PublishResponse,
  SocialPublishError,
} from './types';
import * as socialPublishApi from './api';

export interface UsePublishClipReturn {
  publish: (body: PublishClipRequest) => Promise<PublishResponse | null>;
  isPending: boolean;
  error: string | null;
  clearErrors: () => void;
}

export interface UsePublishesReturn {
  publishes: SocialPublish[];
  loading: boolean;
  error: string | null;
  fetchPublishes: () => Promise<void>;
  clearErrors: () => void;
}

function classifyError(error: unknown): { message: string } {
  const socialError = error as SocialPublishError;
  if (!socialError?.type) {
    return { message: 'An unexpected error occurred' };
  }

  switch (socialError.type) {
    case 'validation':
      return { message: 'Validation failed. Please check your inputs.' };
    case 'throttle':
      return { message: 'Too many attempts. Please try again later.' };
    case 'unauthorized':
      return { message: 'Your session has expired or you do not have access.' };
    case 'csrf':
      return { message: 'Session expired. Please refresh the page.' };
    case 'network':
      return { message: 'Network error. Please check your connection.' };
    case 'conflict':
      return { message: socialError.message || 'A publish already exists for this clip.' };
    case 'server':
      return { message: socialError.message || 'Server error' };
    default:
      return { message: 'An unexpected error occurred' };
  }
}

export function usePublishClip(): UsePublishClipReturn {
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const publish = useCallback(async (body: PublishClipRequest): Promise<PublishResponse | null> => {
    setIsPending(true);
    setError(null);
    try {
      const result = await socialPublishApi.publishClip(body);
      return result;
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
      return null;
    } finally {
      setIsPending(false);
    }
  }, []);

  const clearErrors = useCallback(() => {
    setError(null);
  }, []);

  return {
    publish,
    isPending,
    error,
    clearErrors,
  };
}

export function usePublishes(): UsePublishesReturn {
  const [publishes, setPublishes] = useState<SocialPublish[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchPublishes = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await socialPublishApi.getPublishes();
      setPublishes(Array.isArray(response?.data) ? response.data : []);
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
    } finally {
      setLoading(false);
    }
  }, []);

  const clearErrors = useCallback(() => {
    setError(null);
  }, []);

  return {
    publishes,
    loading,
    error,
    fetchPublishes,
    clearErrors,
  };
}
