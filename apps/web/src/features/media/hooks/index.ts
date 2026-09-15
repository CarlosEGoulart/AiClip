import { useState, useCallback } from 'react';
import type { MediaAsset, MediaError } from '../types';
import * as mediaApi from '../api';

export interface UseMediaAssetsReturn {
  mediaAssets: MediaAsset[];
  loading: boolean;
  uploading: boolean;
  error: string | null;
  fetchMedia: () => Promise<void>;
  uploadFile: (file: File) => Promise<boolean>;
  deleteMedia: (id: number) => Promise<boolean>;
  clearErrors: () => void;
}

function classifyError(error: unknown): { message: string } {
  const mediaError = error as MediaError;
  if (!mediaError?.type) {
    return { message: 'An unexpected error occurred' };
  }

  switch (mediaError.type) {
    case 'validation':
      return { message: 'Validation failed. Please check the file type and size.' };
    case 'file-too-large':
      return { message: 'File exceeds the maximum upload size (100 MB).' };
    case 'throttle':
      return { message: 'Too many uploads. Please try again later.' };
    case 'unauthorized':
      return { message: 'Your session has expired' };
    case 'csrf':
      return { message: 'Session expired. Please refresh the page.' };
    case 'network':
      return { message: 'Network error. Please check your connection.' };
    case 'server':
      return { message: mediaError.message || 'Server error' };
    default:
      return { message: 'An unexpected error occurred' };
  }
}

export function useMediaAssets(projectId: number | null): UseMediaAssetsReturn {
  const [mediaAssets, setMediaAssets] = useState<MediaAsset[]>([]);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchMedia = useCallback(async () => {
    if (projectId === null) return;
    setLoading(true);
    setError(null);
    try {
      const response = await mediaApi.getMediaAssets(projectId);
      setMediaAssets(Array.isArray(response?.data) ? response.data : []);
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
    } finally {
      setLoading(false);
    }
  }, [projectId]);

  const uploadFile = useCallback(async (file: File): Promise<boolean> => {
    if (projectId === null) return false;
    setUploading(true);
    setError(null);
    try {
      const response = await mediaApi.uploadMediaAsset(projectId, file);
      setMediaAssets((prev) => [response.data, ...prev]);
      return true;
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
      return false;
    } finally {
      setUploading(false);
    }
  }, [projectId]);

  const deleteMedia = useCallback(async (id: number): Promise<boolean> => {
    setError(null);
    try {
      await mediaApi.deleteMediaAsset(id);
      setMediaAssets((prev) => prev.filter((m) => m.id !== id));
      return true;
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
      return false;
    }
  }, []);

  const clearErrors = useCallback(() => {
    setError(null);
  }, []);

  return {
    mediaAssets,
    loading,
    uploading,
    error,
    fetchMedia,
    uploadFile,
    deleteMedia,
    clearErrors,
  };
}
