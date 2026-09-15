import { useEffect } from 'react';
import { useMediaAssets } from '../hooks';
import { MediaUploadForm } from './MediaUploadForm';
import { MediaList } from './MediaList';

interface ProjectMediaSectionProps {
  projectId: number;
  projectName: string;
  onBack: () => void;
}

export function ProjectMediaSection({ projectId, projectName, onBack }: ProjectMediaSectionProps) {
  const {
    mediaAssets,
    loading,
    uploading,
    error,
    fetchMedia,
    uploadFile,
    deleteMedia,
  } = useMediaAssets(projectId);

  useEffect(() => {
    fetchMedia();
  }, [fetchMedia]);

  return (
    <div className="project-media-section">
      <div className="project-media-header">
        <button onClick={onBack} className="back-button" aria-label="Back to projects">
          &larr; Back to Projects
        </button>
        <h2 className="project-media-title">{projectName}</h2>
      </div>

      <MediaUploadForm
        onUpload={uploadFile}
        uploading={uploading}
        error={error}
      />

      <MediaList
        mediaAssets={mediaAssets}
        loading={loading}
        onDelete={deleteMedia}
      />
    </div>
  );
}
