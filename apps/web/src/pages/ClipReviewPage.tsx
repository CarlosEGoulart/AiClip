import React from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ReviewPage } from '../features/clip-review/components/ReviewPage';

export const ClipReviewPage: React.FC = () => {
  const { projectId } = useParams<{ projectId: string }>();
  const navigate = useNavigate();

  if (!projectId) {
    return (
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center">
        <div className="text-center">
          <h1 className="text-2xl font-bold text-gray-900 dark:text-white mb-4">Project not found</h1>
          <button
            onClick={() => navigate('/')}
            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            Go Home
          </button>
        </div>
      </div>
    );
  }

  return (
    <ReviewPage
      projectId={parseInt(projectId, 10)}
      projectName="Project" // In real app, fetch project name
      onBack={() => navigate('/projects')}
    />
  );
};