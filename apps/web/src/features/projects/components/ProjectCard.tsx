import { useState } from 'react';
import type { Project } from '../types';

interface ProjectCardProps {
  project: Project;
  onDelete: (id: number) => Promise<boolean>;
}

export function ProjectCard({ project, onDelete }: ProjectCardProps) {
  const [confirming, setConfirming] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const handleDelete = async () => {
    if (deleting) return;
    setDeleting(true);
    await onDelete(project.id);
    setDeleting(false);
    setConfirming(false);
  };

  return (
    <div className="project-card" role="article" aria-label={`Project: ${project.name}`}>
      <div className="project-card-content">
        <h4 className="project-card-name">{project.name}</h4>
        <p className="project-card-date">
          Created {new Date(project.created_at).toLocaleDateString()}
        </p>
      </div>
      <div className="project-card-actions">
        {confirming ? (
          <>
            <span className="confirm-text">Delete this project?</span>
            <button
              onClick={handleDelete}
              disabled={deleting}
              className="delete-confirm-button"
              aria-label={`Confirm delete ${project.name}`}
            >
              {deleting ? 'Deleting...' : 'Yes, delete'}
            </button>
            <button
              onClick={() => setConfirming(false)}
              disabled={deleting}
              className="delete-cancel-button"
              aria-label={`Cancel delete ${project.name}`}
            >
              Cancel
            </button>
          </>
        ) : (
          <button
            onClick={() => setConfirming(true)}
            className="delete-button"
            aria-label={`Delete ${project.name}`}
          >
            Delete
          </button>
        )}
      </div>
    </div>
  );
}
