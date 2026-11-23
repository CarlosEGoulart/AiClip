import { useRef, useState } from 'react';

interface MediaUploadFormProps {
  onUpload: (file: File) => Promise<boolean>;
  onUploadYouTubeUrl: (url: string) => Promise<boolean>;
  uploading: boolean;
  error: string | null;
}

// Client-side YouTube URL pattern: youtube.com/watch?v=VIDEO_ID or youtu.be/VIDEO_ID
const YOUTUBE_URL_PATTERN = /^(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([A-Za-z0-9_-]{11})(?:[?&].*)?$/;

function isValidYouTubeUrl(url: string): boolean {
  return YOUTUBE_URL_PATTERN.test(url.trim());
}

export function MediaUploadForm({
  onUpload,
  onUploadYouTubeUrl,
  uploading,
  error,
}: MediaUploadFormProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const submitterRef = useRef<string | null>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [youtubeUrl, setYoutubeUrl] = useState('');
  const [youtubeError, setYoutubeError] = useState<string | null>(null);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] || null;
    setSelectedFile(file);
  };

  const handleYouTubeUrlChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value;
    setYoutubeUrl(value);

    // Clear error when user types a new value
    if (youtubeError && value.trim() !== '') {
      setYoutubeError(null);
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    // Read synchronously from ref (state would be stale), then reset so
    // each submission independently determines which button was clicked.
    const submitterName = submitterRef.current;
    submitterRef.current = null;

    if (submitterName === 'youtube') {
      // YouTube URL import
      if (uploading) return;

      const trimmed = youtubeUrl.trim();

      if (!trimmed) {
        setYoutubeError('Please enter a YouTube URL.');
        return;
      }

      if (!isValidYouTubeUrl(trimmed)) {
        setYoutubeError('Please enter a valid YouTube URL (youtube.com/watch?v=... or youtu.be/...).');
        return;
      }

      setYoutubeError(null);
      const success = await onUploadYouTubeUrl(trimmed);
      if (success) {
        setYoutubeUrl('');
      }
      return;
    }

    // File upload
    if (!selectedFile || uploading) return;

    const success = await onUpload(selectedFile);
    if (success) {
      setSelectedFile(null);
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    }
  };

  return (
    <form onSubmit={handleSubmit} className="media-upload-form">
      <div className="media-upload-field">
        <label htmlFor="media-file-input" className="media-upload-label">
          Upload Video
        </label>
        <input
          ref={fileInputRef}
          id="media-file-input"
          type="file"
          accept="video/mp4,video/quicktime,video/webm"
          onChange={handleFileChange}
          disabled={uploading}
          className="media-upload-input"
          aria-describedby={error ? 'upload-error' : undefined}
        />
      </div>

      <div className="media-upload-field">
        <label htmlFor="media-youtube-url" className="media-upload-label">
          Import from YouTube URL
        </label>
        <input
          id="media-youtube-url"
          type="text"
          value={youtubeUrl}
          onChange={handleYouTubeUrlChange}
          disabled={uploading}
          placeholder="https://youtu.be/... or https://www.youtube.com/watch?v=..."
          className="media-upload-input media-url-input"
          aria-describedby={youtubeError ? 'youtube-error' : undefined}
        />
      </div>

      {error && (
        <div id="upload-error" role="alert" className="media-upload-error">
          {error}
        </div>
      )}
      {youtubeError && (
        <div id="youtube-error" role="alert" className="media-upload-error">
          {youtubeError}
        </div>
      )}

      <div className="media-upload-actions">
        <button
          type="submit"
          name="file"
          onClick={() => (submitterRef.current = 'file')}
          disabled={!selectedFile || uploading}
          className="media-upload-button"
        >
          {uploading ? 'Uploading...' : 'Upload'}
        </button>
        <button
          type="submit"
          name="youtube"
          onClick={() => (submitterRef.current = 'youtube')}
          disabled={!youtubeUrl.trim() || uploading}
          className="media-upload-button media-youtube-button"
        >
          {uploading ? 'Importing...' : 'Import'}
        </button>
      </div>
    </form>
  );
}
