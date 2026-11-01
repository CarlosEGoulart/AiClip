import { useState, useCallback } from 'react';
import type {
  SocialPublish,
  PublishClipRequest,
  PublishResponse,
  SocialPublishError,
  PublishCampaignRequest,
  PublishCampaignResponse,
  SocialPublishCampaign,
  SocialPublishCampaignStatus,
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

export interface UsePublishCampaignReturn {
  createCampaign: (body: PublishCampaignRequest) => Promise<PublishCampaignResponse | null>;
  isPending: boolean;
  error: string | null;
  clearErrors: () => void;
}

export function usePublishCampaign(): UsePublishCampaignReturn {
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const createCampaign = useCallback(async (body: PublishCampaignRequest): Promise<PublishCampaignResponse | null> => {
    setIsPending(true);
    setError(null);
    try {
      const result = await socialPublishApi.createPublishCampaign(body);
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
    createCampaign,
    isPending,
    error,
    clearErrors,
  };
}

export interface UsePublishCampaignsReturn {
  campaigns: SocialPublishCampaign[];
  loading: boolean;
  error: string | null;
  fetchCampaigns: () => Promise<void>;
  clearErrors: () => void;
}

export function usePublishCampaigns(): UsePublishCampaignsReturn {
  const [campaigns, setCampaigns] = useState<SocialPublishCampaign[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchCampaigns = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await socialPublishApi.getPublishCampaigns();
      setCampaigns(Array.isArray(response?.data) ? response.data : []);
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
    campaigns,
    loading,
    error,
    fetchCampaigns,
    clearErrors,
  };
}

export interface UsePublishCampaignReturnDetail {
  campaign: SocialPublishCampaign | null;
  loading: boolean;
  error: string | null;
  fetchCampaign: () => Promise<void>;
  clearErrors: () => void;
}

export function usePublishCampaign(id: number): UsePublishCampaignReturnDetail {
  const [campaign, setCampaign] = useState<SocialPublishCampaign | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchCampaign = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await socialPublishApi.getPublishCampaign(id);
      setCampaign(result);
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
    } finally {
      setLoading(false);
    }
  }, [id]);

  const clearErrors = useCallback(() => {
    setError(null);
  }, []);

  return {
    campaign,
    loading,
    error,
    fetchCampaign,
    clearErrors,
  };
}

export interface UseCancelPublishCampaignReturn {
  cancel: (id: number) => Promise<{ campaign_id: number; status: string } | null>;
  isPending: boolean;
  error: string | null;
  clearErrors: () => void;
}

export function useCancelPublishCampaign(): UseCancelPublishCampaignReturn {
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const cancel = useCallback(async (id: number): Promise<{ campaign_id: number; status: string } | null> => {
    setIsPending(true);
    setError(null);
    try {
      const result = await socialPublishApi.cancelPublishCampaign(id);
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
    cancel,
    isPending,
    error,
    clearErrors,
  };
}

export interface UseRetryPublishCampaignReturn {
  retry: (id: number) => Promise<{ campaign_id: number; status: string; retried_count: number } | null>;
  isPending: boolean;
  error: string | null;
  clearErrors: () => void;
}

export function useRetryPublishCampaign(): UseRetryPublishCampaignReturn {
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const retry = useCallback(async (id: number): Promise<{ campaign_id: number; status: string; retried_count: number } | null> => {
    setIsPending(true);
    setError(null);
    try {
      const result = await socialPublishApi.retryPublishCampaign(id);
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
    retry,
    isPending,
    error,
    clearErrors,
  };
}

export function campaignStatusColor(status: SocialPublishCampaignStatus): string {
  const colors: Record<SocialPublishCampaignStatus, string> = {
    pending: 'gray',
    processing: 'blue',
    published: 'green',
    partially_published: 'yellow',
    failed: 'red',
    cancelled: 'gray',
  };

  return colors[status] ?? 'gray';
}
