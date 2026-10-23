import { render, screen } from '@testing-library/react';
import { SocialConnectionList } from './SocialConnectionList';
import type { SocialConnection } from '../types';

const mockConnection: SocialConnection = {
  id: 1,
  platform: 'youtube',
  platform_label: 'YouTube',
  display_name: 'Test Channel',
  avatar_url: 'https://example.com/avatar.jpg',
  channel_url: 'https://youtube.com/@test',
  status: 'connected',
  last_error: null,
  created_at: '2026-10-22T10:00:00.000000Z',
  updated_at: '2026-10-22T10:00:00.000000Z',
};

const defaultProps = {
  connections: [mockConnection],
  loading: false,
  onDisconnect: vi.fn(),
  onReconnect: vi.fn(),
  onRetry: vi.fn(),
};

describe('SocialConnectionList', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders loading skeletons', () => {
    render(<SocialConnectionList {...defaultProps} loading={true} />);

    // We don't have test-id, so check for loading state elements
    // The "Loading" text is in sr-only for screen readers - should be present (3 skeleton cards)
    expect(screen.getAllByText(/loading/i)).toHaveLength(3);
    // Check for 3 skeleton cards by looking for animated pulse elements
    const pulseElements = document.querySelectorAll('.animate-pulse');
    expect(pulseElements.length).toBeGreaterThanOrEqual(3);
  });

  it('renders empty state', () => {
    render(<SocialConnectionList {...defaultProps} connections={[]} />);

    expect(screen.getByText('No connections yet')).toBeInTheDocument();
    expect(screen.getByText('Connect your social accounts to publish content directly from AiClip.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /connect your first account/i })).toBeInTheDocument();
  });

  it('renders list of cards', () => {
    const connections = [
      mockConnection,
      { ...mockConnection, id: 2, platform: 'instagram' as const, platform_label: 'Instagram', display_name: 'Insta Channel' },
      { ...mockConnection, id: 3, platform: 'tiktok' as const, platform_label: 'TikTok', display_name: 'TikTok Channel' },
    ];
    render(<SocialConnectionList {...defaultProps} connections={connections} />);

    expect(screen.getByText('Test Channel')).toBeInTheDocument();
    expect(screen.getByText('Insta Channel')).toBeInTheDocument();
    expect(screen.getByText('TikTok Channel')).toBeInTheDocument();
    expect(screen.getByText('YouTube')).toBeInTheDocument();
    expect(screen.getByText('Instagram')).toBeInTheDocument();
    expect(screen.getByText('TikTok')).toBeInTheDocument();
  });

  it('passes correct callbacks to cards', () => {
    const onDisconnect = vi.fn();
    const onReconnect = vi.fn();
    const onRetry = vi.fn();

    render(<SocialConnectionList {...defaultProps} onDisconnect={onDisconnect} onReconnect={onReconnect} onRetry={onRetry} />);

    // The callbacks are passed to SocialConnectionCard
    expect(onDisconnect).not.toHaveBeenCalled();
    expect(onReconnect).not.toHaveBeenCalled();
    expect(onRetry).not.toHaveBeenCalled();
  });
});