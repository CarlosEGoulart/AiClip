import type { MediaAsset } from '../types';
import { MediaListItem } from './MediaListItem';

interface MediaListProps {
  mediaAssets: MediaAsset[];
  loading: boolean;
  onDelete: (id: number) => Promise<boolean>;
}

export function MediaList({ mediaAssets, loading, onDelete }: MediaListProps) {
  if (loading) {
    return (
      <div className="media-list" role="status">
        Loading media assets...
      </div>
    );
  }

  if (mediaAssets.length === 0) {
    return (
      <div className="media-list media-list-empty">
        <p>No media assets yet. Upload a video to get started.</p>
      </div>
    );
  }

  return (
    <div className="media-list">
      <h3 className="media-list-title">Media Assets</h3>
      <ul className="media-list-items" role="list">
        {mediaAssets.map((media) => (
          <MediaListItem
            key={media.id}
            media={media}
            onDelete={onDelete}
          />
        ))}
      </ul>
    </div>
  );
}
