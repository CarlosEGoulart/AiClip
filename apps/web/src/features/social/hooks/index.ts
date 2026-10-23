import { useState, useCallback } from 'react';
import type { SocialConnection, ConnectResponse, SocialError } from '../types';
import * as socialApi from '../api';

export interface UseSocialConnectionsReturn {
  connections: SocialConnection[];
  loading: boolean;
  error: string | null;
  fetchConnections: () => Promise<void>;
  clearErrors: () => void;
}

function classifyError(error: unknown): { message: string } {
  const socialError = error as SocialError;
  if (!socialError?.type) {
    return { message: 'An unexpected error occurred' };
  }

  switch (socialError.type) {
    case 'validation':
      return { message: 'Validation failed' };
    case 'throttle':
      return { message: 'Too many attempts. Please try again later.' };
    case 'unauthorized':
      return { message: 'Your session has expired' };
    case 'csrf':
      return { message: 'Session expired. Please refresh the page.' };
    case 'network':
      return { message: 'Network error. Please check your connection.' };
    case 'conflict':
      return { message: socialError.message || 'Connection already exists' };
    case 'server':
      return { message: socialError.message || 'Server error' };
    default:
      return { message: 'An unexpected error occurred' };
  }
}

export function useSocialConnections(): UseSocialConnectionsReturn {
  const [connections, setConnections] = useState<SocialConnection[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchConnections = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await socialApi.getConnections();
      setConnections(Array.isArray(response?.data) ? response.data : []);
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
    connections,
    loading,
    error,
    fetchConnections,
    clearErrors,
  };
}

export interface UseConnectPlatformReturn {
  connect: (platform: string) => Promise<ConnectResponse | null>;
  isPending: boolean;
  error: string | null;
  clearErrors: () => void;
}

export function useConnectPlatform(): UseConnectPlatformReturn {
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const connect = useCallback(async (platform: string): Promise<ConnectResponse | null> => {
    setIsPending(true);
    setError(null);
    try {
      const result = await socialApi.connectPlatform(platform);
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
    connect,
    isPending,
    error,
    clearErrors,
  };
}

export interface UseDisconnectConnectionReturn {
  disconnect: (id: number) => Promise<boolean>;
  isPending: boolean;
  error: string | null;
  clearErrors: () => void;
}

export function useDisconnectConnection(): UseDisconnectConnectionReturn {
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const disconnect = useCallback(async (id: number): Promise<boolean> => {
    setIsPending(true);
    setError(null);
    try {
      await socialApi.disconnectConnection(id);
      return true;
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
      return false;
    } finally {
      setIsPending(false);
    }
  }, []);

  const clearErrors = useCallback(() => {
    setError(null);
  }, []);

  return {
    disconnect,
    isPending,
    error,
    clearErrors,
  };
}