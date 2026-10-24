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
    deleteMedia,
  } = useMediaAssets(projectId);

  const [activeTab, setActiveTab] = useState<Tab>('media');

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

      <div className="tab-navigation border-b border-gray-200 dark:border-gray-700 mb-6">
        <nav className="flex gap-4" role="tablist" aria-label="Project sections">
          <button
            role="tab"
            aria-selected={activeTab === 'media'}
            aria-controls="media-panel"
            id="media-tab"
            onClick={() => setActiveTab('media')}
            className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
              activeTab === 'media'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
            }`}
          >
            Media
          </button>
          <button
            role="tab"
            aria-selected={activeTab === 'review'}
            aria-controls="review-panel"
            id="review-tab"
            onClick={() => setActiveTab('review')}
            className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
              activeTab === 'review'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
            }`}
          >
            Review Clips
          </button>
          <button
            role="tab"
            aria-selected={activeTab === 'image-studio'}
            aria-controls="image-studio-panel"
            id="image-studio-tab"
            onClick={() => setActiveTab('image-studio')}
            className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
              activeTab === 'image-studio'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
            }`}
          >
            Image Studio
          </button>
          <button
            role="tab"
            aria-selected={activeTab === 'social'}
            aria-controls="social-panel"
            id="social-tab"
            onClick={() => setActiveTab('social')}
            className={`px-4 py-2 font-medium text-sm border-b-2 transition-colors ${
              activeTab === 'social'
                ? 'border-blue-600 text-blue-600'
                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'
            }`}
          >
            Social
          </button>
        </nav>
      </div>

      {activeTab === 'media' && (
        <div id="media-panel" role="tabpanel" aria-labelledby="media-tab">
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
