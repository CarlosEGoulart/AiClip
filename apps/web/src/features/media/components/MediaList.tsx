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
      <div className="media-list media-list-loading" role="status">
        Loading media assets…
      </div>
    );
  }

  if (mediaAssets.length === 0) {
    return (
      <div className="media-list media-list-empty" role="status">
        <h2 className="media-list-empty-heading">No media assets yet</h2>
        <p className="media-list-empty-text">
          Upload a video to get started. AiClip turns long-form video into short-form clips.
        </p>
      </div>
    );
  }

  return (
    <div className="media-list">
      <h2 className="media-list-heading">Media Assets</h2>
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
