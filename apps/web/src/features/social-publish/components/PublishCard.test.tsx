import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PublishCard } from './PublishCard';
import type { SocialPublish } from '../types';

const mockPublish: SocialPublish = {
  id: 1,
  platform: 'youtube',
  platform_label: 'YouTube',
  status: 'pending',
  external_video_id: null,
  video_url: null,
  title: 'Test Clip',
  description: null,
  error_message: null,
  created_at: '2026-10-26T10:00:00.000000Z',
  updated_at: '2026-10-26T10:00:00.000000Z',
};

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('PublishCard', () => {
  it('renders pending status badge', () => {
    render(<PublishCard publish={mockPublish} />);
    expect(screen.getByText('Pending')).toBeInTheDocument();
  });

  it('renders uploading status badge', () => {
    render(<PublishCard publish={{ ...mockPublish, status: 'uploading' }} />);
    expect(screen.getByText('Uploading')).toBeInTheDocument();
  });

  it('renders published status badge with video link', () => {
    const published: SocialPublish = {
      ...mockPublish,
      status: 'published',
      external_video_id: 'dQw4w9WgXcQ',
      video_url: 'https://youtu.be/dQw4w9WgXcQ',
    };
    render(<PublishCard publish={published} />);
    expect(screen.getByText('Published')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View on YouTube' })).toBeInTheDocument();
  });

  it('renders failed status badge with error message', () => {
    const failed: SocialPublish = {
      ...mockPublish,
      status: 'failed',
      error_message: 'Upload failed',
    };
    render(<PublishCard publish={failed} />);
    expect(screen.getByText('Failed')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent('Upload failed');
  });

  it('status badge uses text not color only', () => {
    render(<PublishCard publish={mockPublish} />);
    expect(screen.getByText('Pending')).toBeInTheDocument();
  });

  it('video link opens youtube watch url', () => {
    const published: SocialPublish = {
      ...mockPublish,
      status: 'published',
      external_video_id: 'dQw4w9WgXcQ',
      video_url: 'https://youtu.be/dQw4w9WgXcQ',
    };
    const { container } = render(<PublishCard publish={published} />);
    const link = container.querySelector('a');
    expect(link?.getAttribute('href')).toBe('https://youtu.be/dQw4w9WgXcQ');
    expect(link?.getAttribute('target')).toBe('_blank');
  });
});
