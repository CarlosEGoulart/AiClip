import { useState, useCallback, useLayoutEffect, useRef } from 'react';
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

interface MediaScope {
  projectId: number | null;
  active: boolean;
  listVersion: number;
  pendingUploads: number;
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
  const [viewProjectId, setViewProjectId] = useState(projectId);
  const [mediaAssets, setMediaAssets] = useState<MediaAsset[]>([]);
  const [loading, setLoading] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const scopeRef = useRef<MediaScope | null>(null);

  // Reset during render so children never receive another project's state.
  if (viewProjectId !== projectId) {
    setViewProjectId(projectId);
    setMediaAssets([]);
    setError(null);
    setLoading(false);
    setUploading(false);
  }

  useLayoutEffect(() => {
    const scope: MediaScope = { projectId, active: true, listVersion: 0, pendingUploads: 0 };
    scopeRef.current = scope;
    return () => {
      scope.active = false;
      // Cancel pending indicators as well as results during StrictMode cleanup.
      setLoading(false);
      setUploading(false);
    };
  }, [projectId]);

  const fetchMedia = useCallback(async () => {
    const scope = scopeRef.current;
    if (projectId === null || !scope?.active || scope.projectId !== projectId) return;
    const version = ++scope.listVersion;
    const isCurrent = () => scope.active && scope.listVersion === version;
    setLoading(true);
    setError(null);
    try {
      const response = await mediaApi.getMediaAssets(projectId);
      if (isCurrent()) setMediaAssets(Array.isArray(response?.data) ? response.data : []);
    } catch (err: unknown) {
      if (isCurrent()) setError(classifyError(err).message);
    } finally {
      if (isCurrent()) setLoading(false);
    }
  }, [projectId]);

  const uploadFile = useCallback(async (file: File): Promise<boolean> => {
    const scope = scopeRef.current;
    if (projectId === null || !scope?.active || scope.projectId !== projectId) return false;
    scope.pendingUploads++;
    setUploading(true);
    setError(null);
    try {
      const response = await mediaApi.uploadMediaAsset(projectId, file);
      if (!scope.active) return false;
      // A successful mutation supersedes older list snapshots, not other mutations.
      scope.listVersion++;
      setLoading(false);
      setError(null);
      setMediaAssets((prev) => [response.data, ...prev.filter((m) => m.id !== response.data.id)]);
      return true;
    } catch (err: unknown) {
      if (scope.active) setError(classifyError(err).message);
      return false;
    } finally {
      scope.pendingUploads--;
      if (scope.active) setUploading(scope.pendingUploads > 0);
    }
  }, [projectId]);

  const deleteMedia = useCallback(async (id: number): Promise<boolean> => {
    const scope = scopeRef.current;
    if (projectId === null || !scope?.active || scope.projectId !== projectId) return false;
    setError(null);
    try {
      await mediaApi.deleteMediaAsset(id);
      if (!scope.active) return false;
      scope.listVersion++;
      setLoading(false);
      setError(null);
      setMediaAssets((prev) => prev.filter((m) => m.id !== id));
      return true;
    } catch (err: unknown) {
      if (scope.active) setError(classifyError(err).message);
      return false;
    }
  }, [projectId]);

  const clearErrors = useCallback(() => {
    const scope = scopeRef.current;
    if (scope?.active && scope.projectId === projectId) setError(null);
  }, [projectId]);

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
