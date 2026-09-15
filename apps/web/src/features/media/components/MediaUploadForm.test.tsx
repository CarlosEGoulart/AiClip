import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MediaUploadForm } from './MediaUploadForm';

afterEach(cleanup);

describe('MediaUploadForm', () => {
  it('renders file input with correct accept attribute', () => {
    render(
      <MediaUploadForm
        onUpload={vi.fn()}
        uploading={false}
        error={null}
      />,
    );

    const input = screen.getByLabelText('Upload Video');
    expect(input).toBeInTheDocument();
    expect(input).toHaveAttribute('accept', 'video/mp4,video/quicktime,video/webm');
  });

  it('renders upload button', () => {
    render(
      <MediaUploadForm
        onUpload={vi.fn()}
        uploading={false}
        error={null}
      />,
    );

    expect(screen.getByRole('button', { name: 'Upload' })).toBeInTheDocument();
  });

  it('shows uploading state', () => {
    render(
      <MediaUploadForm
        onUpload={vi.fn()}
        uploading={true}
        error={null}
      />,
    );

    expect(screen.getByText('Uploading...')).toBeInTheDocument();
    expect(screen.getByLabelText('Upload Video')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Uploading...' })).toBeDisabled();
  });

  it('shows error message', () => {
    render(
      <MediaUploadForm
        onUpload={vi.fn()}
        uploading={false}
        error="File too large"
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('File too large');
  });

  it('calls onUpload with the selected file', async () => {
    const onUpload = vi.fn().mockResolvedValue(true);
    render(
      <MediaUploadForm
        onUpload={onUpload}
        uploading={false}
        error={null}
      />,
    );

    const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
    const input = screen.getByLabelText('Upload Video');

    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.submit(screen.getByRole('button', { name: 'Upload' }));

    await waitFor(() => {
      expect(onUpload).toHaveBeenCalledWith(file);
    });
  });

  it('resets file input after successful upload', async () => {
    const onUpload = vi.fn().mockResolvedValue(true);
    render(
      <MediaUploadForm
        onUpload={onUpload}
        uploading={false}
        error={null}
      />,
    );

    const file = new File(['content'], 'test.mp4', { type: 'video/mp4' });
    const input = screen.getByLabelText('Upload Video') as HTMLInputElement;

    fireEvent.change(input, { target: { files: [file] } });
    fireEvent.submit(screen.getByRole('button', { name: 'Upload' }));

    await waitFor(() => {
      expect(input.value).toBe('');
    });
  });

  it('disables button when no file selected', () => {
    render(
      <MediaUploadForm
        onUpload={vi.fn()}
        uploading={false}
        error={null}
      />,
    );

    expect(screen.getByRole('button', { name: 'Upload' })).toBeDisabled();
  });

  it('has accessible label for file input', () => {
    render(
      <MediaUploadForm
        onUpload={vi.fn()}
        uploading={false}
        error={null}
      />,
    );

    const input = screen.getByLabelText('Upload Video');
    expect(input).toBeInTheDocument();
  });
});
