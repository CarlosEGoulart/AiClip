import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MediaUploadForm } from './MediaUploadForm';

afterEach(cleanup);

function renderForm(overrides: Partial<{
  onUpload: (file: File) => Promise<boolean>;
  onUploadYouTubeUrl: (url: string) => Promise<boolean>;
  uploading: boolean;
  error: string | null;
}> = {}) {
  const props = {
    onUpload: vi.fn().mockResolvedValue(true),
    onUploadYouTubeUrl: vi.fn().mockResolvedValue(true),
    uploading: false,
    error: null,
    ...overrides,
  };
  return render(<MediaUploadForm {...props} />);
}

describe('MediaUploadForm', () => {
  it('renders file input with correct accept attribute', () => {
    renderForm();

    const input = screen.getByLabelText('Upload Video');
    expect(input).toBeInTheDocument();
    expect(input).toHaveAttribute('accept', 'video/mp4,video/quicktime,video/webm');
  });

  it('renders YouTube URL input with label', () => {
    renderForm();

    const input = screen.getByLabelText('Import from YouTube URL');
    expect(input).toBeInTheDocument();
    expect(input).toHaveAttribute('type', 'text');
    expect(input).toHaveAttribute(
      'placeholder',
      'https://youtu.be/... or https://www.youtube.com/watch?v=...',
    );
  });

  it('renders upload button', () => {
    renderForm();

    expect(screen.getByRole('button', { name: 'Upload' })).toBeInTheDocument();
  });

  it('renders YouTube import button', () => {
    renderForm();

    expect(screen.getByRole('button', { name: 'Import' })).toBeInTheDocument();
  });

  it('shows uploading state', () => {
    renderForm({ uploading: true });

    expect(screen.getByText('Uploading...')).toBeInTheDocument();
    expect(screen.getByLabelText('Upload Video')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Uploading...' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Importing...' })).toBeDisabled();
  });

  it('shows error message', () => {
    renderForm({ error: 'File too large' });

    expect(screen.getByRole('alert')).toHaveTextContent('File too large');
  });

  it('shows YouTube URL error message', () => {
    const onUploadYouTubeUrl = vi.fn().mockResolvedValue(false);
    renderForm({ onUploadYouTubeUrl });

    const urlInput = screen.getByLabelText('Import from YouTube URL');
    fireEvent.change(urlInput, { target: { value: 'https://example.com/video' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import' }));

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Please enter a valid YouTube URL (youtube.com/watch?v=... or youtu.be/...).',
    );
  });

  it('calls onUpload with the selected file', async () => {
    const onUpload = vi.fn().mockResolvedValue(true);
    renderForm({ onUpload });

    const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
    const input = screen.getByLabelText('Upload Video');

    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }));

    await waitFor(() => {
      expect(onUpload).toHaveBeenCalledWith(file);
    });
  });

  it('calls onUploadYouTubeUrl with a valid YouTube URL', async () => {
    const onUploadYouTubeUrl = vi.fn().mockResolvedValue(true);
    renderForm({ onUploadYouTubeUrl });

    const urlInput = screen.getByLabelText('Import from YouTube URL');

    fireEvent.change(urlInput, { target: { value: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import' }));

    await waitFor(() => {
      expect(onUploadYouTubeUrl).toHaveBeenCalledWith('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    });
  });

  it('resets file input after successful upload', async () => {
    const onUpload = vi.fn().mockResolvedValue(true);
    renderForm({ onUpload });

    const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
    const input = screen.getByLabelText('Upload Video') as HTMLInputElement;

    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.click(screen.getByRole('button', { name: 'Upload' }));

    await waitFor(() => {
      expect(input.value).toBe('');
    });
  });

  it('resets YouTube URL input after successful import', async () => {
    const onUploadYouTubeUrl = vi.fn().mockResolvedValue(true);
    renderForm({ onUploadYouTubeUrl });

    const urlInput = screen.getByLabelText('Import from YouTube URL') as HTMLInputElement;

    fireEvent.change(urlInput, { target: { value: 'https://youtu.be/dQw4w9WgXcQ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import' }));

    await waitFor(() => {
      expect(urlInput.value).toBe('');
    });
  });

  it('disables file upload button when no file selected', () => {
    renderForm();

    expect(screen.getByRole('button', { name: 'Upload' })).toBeDisabled();
  });

  it('disables YouTube import button when no URL entered', () => {
    renderForm();

    expect(screen.getByRole('button', { name: 'Import' })).toBeDisabled();
  });

  it('has accessible label for file input', () => {
    renderForm();

    const input = screen.getByLabelText('Upload Video');
    expect(input).toBeInTheDocument();
  });

  it('has accessible label for YouTube URL input', () => {
    renderForm();

    const input = screen.getByLabelText('Import from YouTube URL');
    expect(input).toBeInTheDocument();
  });

  it('accepts youtube.com/watch?v= URLs', async () => {
    const onUploadYouTubeUrl = vi.fn().mockResolvedValue(true);
    renderForm({ onUploadYouTubeUrl });

    const urlInput = screen.getByLabelText('Import from YouTube URL') as HTMLInputElement;

    fireEvent.change(urlInput, { target: { value: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import' }));

    await waitFor(() => {
      expect(onUploadYouTubeUrl).toHaveBeenCalledTimes(1);
      expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });
  });

  it('accepts youtu.be URLs', async () => {
    const onUploadYouTubeUrl = vi.fn().mockResolvedValue(true);
    renderForm({ onUploadYouTubeUrl });

    const urlInput = screen.getByLabelText('Import from YouTube URL') as HTMLInputElement;

    fireEvent.change(urlInput, { target: { value: 'https://youtu.be/dQw4w9WgXcQ' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import' }));

    await waitFor(() => {
      expect(onUploadYouTubeUrl).toHaveBeenCalledTimes(1);
    });
  });

  it('rejects non-YouTube URLs with client-side error', async () => {
    const onUploadYouTubeUrl = vi.fn().mockResolvedValue(true);
    renderForm({ onUploadYouTubeUrl });

    const urlInput = screen.getByLabelText('Import from YouTube URL') as HTMLInputElement;

    fireEvent.change(urlInput, { target: { value: 'https://example.com/video' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import' }));

    await waitFor(() => {
      expect(onUploadYouTubeUrl).not.toHaveBeenCalled();
      expect(screen.getByRole('alert')).toHaveTextContent(
        'Please enter a valid YouTube URL (youtube.com/watch?v=... or youtu.be/...).',
      );
    });
  });

  it('clears YouTube error when user types a new value', async () => {
    const onUploadYouTubeUrl = vi.fn().mockResolvedValue(true);
    renderForm({ onUploadYouTubeUrl });

    const urlInput = screen.getByLabelText('Import from YouTube URL') as HTMLInputElement;

    // First submit invalid URL to trigger error
    fireEvent.change(urlInput, { target: { value: 'https://example.com/video' } });
    fireEvent.click(screen.getByRole('button', { name: 'Import' }));
    await waitFor(() => {
      expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    // Then type a valid URL — error should clear
    fireEvent.change(urlInput, { target: { value: 'https://youtu.be/dQw4w9WgXcQ' } });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });
});
