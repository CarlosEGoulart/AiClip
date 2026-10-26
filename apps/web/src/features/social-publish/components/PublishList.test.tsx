import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { PublishList } from './PublishList';
import type { SocialPublish } from '../types';

const mockPublishes: SocialPublish[] = [
  {
    id: 1,
    platform: 'youtube',
    platform_label: 'YouTube',
    status: 'pending',
    external_video_id: null,
    video_url: null,
    title: 'Clip 1',
    description: null,
    error_message: null,
    created_at: '2026-10-26T10:00:00.000000Z',
    updated_at: '2026-10-26T10:00:00.000000Z',
  },
  {
    id: 2,
    platform: 'youtube',
    platform_label: 'YouTube',
    status: 'published',
    external_video_id: 'abc123',
    video_url: 'https://youtu.be/abc123',
    title: 'Clip 2',
    description: null,
    error_message: null,
    created_at: '2026-10-26T11:00:00.000000Z',
    updated_at: '2026-10-26T11:00:00.000000Z',
  },
];

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('PublishList', () => {
  it('renders loading skeletons', () => {
    render(<PublishList publishes={[]} loading={true} />);
    const skeletons = document.querySelectorAll('.publish-skeleton');
    expect(skeletons).toHaveLength(3);
  });

  it('renders empty state', () => {
    render(<PublishList publishes={[]} loading={false} />);
    expect(screen.getByText('No publishes yet')).toBeInTheDocument();
  });

  it('renders list of cards', () => {
    render(<PublishList publishes={mockPublishes} loading={false} />);
    expect(screen.getByText('Clip 1')).toBeInTheDocument();
    expect(screen.getByText('Clip 2')).toBeInTheDocument();
  });
});
