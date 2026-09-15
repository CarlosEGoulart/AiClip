import { useState } from 'react';
import type { MediaAsset } from '../types';

interface MediaListItemProps {
  media: MediaAsset;
  onDelete: (id: number) => Promise<boolean>;
}

function formatBytes(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

export function MediaListItem({ media, onDelete }: MediaListItemProps) {
  const [confirming, setConfirming] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const handleDelete = async () => {
    if (deleting) return;
    setDeleting(true);
    await onDelete(media.id);
    setDeleting(false);
    setConfirming(false);
  };

  return (
    <li className="media-list-item" role="listitem" aria-label={`Media: ${media.original_name}`}>
      <div className="media-list-item-content">
        <span className="media-list-item-name">{media.original_name}</span>
        <span className="media-list-item-size">{formatBytes(media.size_bytes)}</span>
        <span className="media-list-item-date">
          {new Date(media.created_at).toLocaleDateString()}
        </span>
        <span className={`media-list-item-status media-list-item-status--${media.status}`}>
          {media.status}
        </span>
      </div>
      <div className="media-list-item-actions">
        {confirming ? (
          <>
            <span className="confirm-text">Delete this media?</span>
            <button
              onClick={handleDelete}
              disabled={deleting}
              className="delete-confirm-button"
              aria-label={`Confirm delete ${media.original_name}`}
            >
              {deleting ? 'Deleting...' : 'Yes, delete'}
            </button>
            <button
              onClick={() => setConfirming(false)}
              disabled={deleting}
              className="delete-cancel-button"
              aria-label={`Cancel delete ${media.original_name}`}
            >
              Cancel
            </button>
          </>
        ) : (
          <button
            onClick={() => setConfirming(true)}
            className="delete-button"
            aria-label={`Delete ${media.original_name}`}
          >
            Delete
          </button>
        )}
      </div>
    </li>
  );
}
