import { useRef, useState } from 'react';

interface MediaUploadFormProps {
  onUpload: (file: File) => Promise<boolean>;
  uploading: boolean;
  error: string | null;
}

export function MediaUploadForm({ onUpload, uploading, error }: MediaUploadFormProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [selectedFile, setSelectedFile] = useState<File | null>(null);

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0] || null;
    setSelectedFile(file);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
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
      {error && (
        <div id="upload-error" role="alert" className="media-upload-error">
          {error}
        </div>
      )}
      <button
        type="submit"
        disabled={!selectedFile || uploading}
        className="media-upload-button"
      >
        {uploading ? 'Uploading...' : 'Upload'}
      </button>
    </form>
  );
}
