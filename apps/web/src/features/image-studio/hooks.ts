import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import type { GenerateImageRequest, ImageListResponse } from './types';
import { fetchImages, generateImage } from './api';

export function useImages(projectId: number, localPendingCount = 0) {
  return useQuery({
    queryKey: ['images', projectId],
    queryFn: () => fetchImages(projectId),
    enabled: !!projectId,
    // Poll every 2 seconds while any displayed image is still pending
    // (the server list or a local pending entry seeded from the 202
    // response); stop otherwise. WebSockets are out of scope for this
    // slice, so polling is the only status mechanism.
    refetchInterval: (query) => {
      const response = query.state.data as ImageListResponse | undefined;
      const serverPending = (response?.data ?? []).some(
        (image) => image.status === 'pending'
      );
      return serverPending || localPendingCount > 0 ? 2000 : false;
    },
  });
}

export function useGenerateImage() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (request: GenerateImageRequest) => generateImage(request),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['images'] });
    },
  });
}
