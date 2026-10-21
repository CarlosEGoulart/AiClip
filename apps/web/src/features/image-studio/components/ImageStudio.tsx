import React, { useCallback, useEffect, useMemo, useState } from 'react';
import type { ImageItem } from '../types';
import { useGenerateImage, useImages } from '../hooks';
import { GenerateImageForm, type GenerateImageFormSubmitValues } from './GenerateImageForm';
import { ImageGallery } from './ImageGallery';

interface ImageStudioProps {
  projectId: number;
}

/*
 * Image Studio composition: the generate form and the metadata gallery.
 *
 * The 202 response's pending resource is merged into the gallery
 * immediately as a local pending entry, so the request is visible at
 * once; the pending-aware useImages poll then replaces it with server
 * truth and flips the badge when generation completes (QUEUE_CONNECTION=
 * sync in tests/e2e makes the transition observable on the first
 * refetch; production uses the database queue).
 */
export const ImageStudio: React.FC<ImageStudioProps> = ({ projectId }) => {
  const [localPendingImages, setLocalPendingImages] = useState<ImageItem[]>([]);

  const imagesQuery = useImages(projectId, localPendingImages.length);
  const generateMutation = useGenerateImage();

  const serverImages = useMemo(() => imagesQuery.data?.data ?? [], [imagesQuery.data]);

  // Once the server list knows about a local pending entry, server truth
  // replaces it: drop the local copy so the entry is rendered exactly once.
  useEffect(() => {
    setLocalPendingImages((current) => {
      const serverIds = new Set(serverImages.map((image) => image.id));
      const remaining = current.filter((image) => !serverIds.has(image.id));
      return remaining.length === current.length ? current : remaining;
    });
  }, [serverImages]);

  const displayImages = useMemo(() => {
    const serverIds = new Set(serverImages.map((image) => image.id));
    const pendingOnly = localPendingImages.filter((image) => !serverIds.has(image.id));
    return [...pendingOnly, ...serverImages];
  }, [localPendingImages, serverImages]);

  const handleGenerate = useCallback(
    async (values: GenerateImageFormSubmitValues) => {
      try {
        const result = await generateMutation.mutateAsync({
          projectId,
          prompt: values.prompt,
          stylePreset: values.stylePreset,
          width: values.width,
          height: values.height,
        });
        setLocalPendingImages((current) => [result.data, ...current]);
      } catch {
        // React Query records the rejection in the mutation error state;
        // the form renders it in the polite live region. Swallow here so
        // no unhandled rejection escapes to the browser.
      }
    },
    [generateMutation, projectId]
  );

  const mutationErrorMessage =
    generateMutation.error instanceof Error ? generateMutation.error.message : null;
  const queryErrorMessage =
    imagesQuery.error instanceof Error ? imagesQuery.error.message : null;

  return (
    <section aria-label="Image Studio" className="image-studio">
      <header className="image-studio-header">
        <h2>Image Studio</h2>
        <p className="image-studio-intro">
          Generate images from text prompts and follow their progress in this project.
        </p>
      </header>

      <div className="image-studio-layout">
        <GenerateImageForm
          onGenerate={handleGenerate}
          generating={generateMutation.isPending}
          error={mutationErrorMessage}
        />

        <ImageGallery
          images={displayImages}
          loading={imagesQuery.isLoading}
          error={queryErrorMessage}
          onRetry={() => {
            void imagesQuery.refetch();
          }}
        />
      </div>
    </section>
  );
};
