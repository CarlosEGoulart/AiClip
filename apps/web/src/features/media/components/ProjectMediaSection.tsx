import { useEffect, useState } from 'react';
import { useMediaAssets } from '../hooks';
import { MediaUploadForm } from './MediaUploadForm';
import { MediaList } from './MediaList';
import { ReviewPage } from '../../clip-review/components/ReviewPage';
import { ImageStudio } from '../../image-studio/components/ImageStudio';
import { SocialConnections } from '../../social/components/SocialConnections';

type Tab = 'media' | 'review' | 'image-studio' | 'social';

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
    uploadYouTubeUrl,
    deleteMedia,
  } = useMediaAssets(projectId);

  const [activeTab, setActiveTab] = useState<Tab>('media');

  useEffect(() => {
    fetchMedia();
  }, [fetchMedia]);

  return (
    <div className="project-media-section">
      <div className="project-media-header">
        <button onClick={onBack} className="btn btn-ghost back-button" aria-label="Back to projects">
          <span aria-hidden="true">←</span> Back to Projects
        </button>
        <h2 className="project-media-title">{projectName}</h2>
      </div>

      <nav className="tab-navigation" role="tablist" aria-label="Project sections">
        <button
          role="tab"
          aria-selected={activeTab === 'media'}
          aria-controls="media-panel"
          id="media-tab"
          onClick={() => setActiveTab('media')}
          className={`tab-button ${activeTab === 'media' ? 'tab-button--active' : ''}`}
        >
          Media
        </button>
        <button
          role="tab"
          aria-selected={activeTab === 'review'}
          aria-controls="review-panel"
          id="review-tab"
          onClick={() => setActiveTab('review')}
          className={`tab-button ${activeTab === 'review' ? 'tab-button--active' : ''}`}
        >
          Review Clips
        </button>
        <button
          role="tab"
          aria-selected={activeTab === 'image-studio'}
          aria-controls="image-studio-panel"
          id="image-studio-tab"
          onClick={() => setActiveTab('image-studio')}
          className={`tab-button ${activeTab === 'image-studio' ? 'tab-button--active' : ''}`}
        >
          Image Studio
        </button>
        <button
          role="tab"
          aria-selected={activeTab === 'social'}
          aria-controls="social-panel"
          id="social-tab"
          onClick={() => setActiveTab('social')}
          className={`tab-button ${activeTab === 'social' ? 'tab-button--active' : ''}`}
        >
          Social
        </button>
      </nav>

      {activeTab === 'media' && (
        <div id="media-panel" role="tabpanel" aria-labelledby="media-tab">
          <MediaUploadForm
            onUpload={uploadFile}
            onUploadYouTubeUrl={uploadYouTubeUrl}
            uploading={uploading}
            error={error}
          />

          <MediaList
            mediaAssets={mediaAssets}
            loading={loading}
            onDelete={deleteMedia}
          />
        </div>
      )}

      {activeTab === 'review' && (
        <div id="review-panel" role="tabpanel" aria-labelledby="review-tab">
          <ReviewPage
            projectId={projectId}
            projectName={projectName}
            onBack={onBack}
          />
        </div>
      )}

      {activeTab === 'image-studio' && (
        <div id="image-studio-panel" role="tabpanel" aria-labelledby="image-studio-tab">
          <ImageStudio projectId={projectId} />
        </div>
      )}

      {activeTab === 'social' && (
        <div id="social-panel" role="tabpanel" aria-labelledby="social-tab">
          <SocialConnections />
        </div>
      )}
    </div>
  );
}
