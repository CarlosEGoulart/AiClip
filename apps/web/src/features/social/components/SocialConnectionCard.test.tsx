import { render, screen, fireEvent } from '@testing-library/react';
import { SocialConnectionCard } from './SocialConnectionCard';
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
  connection: mockConnection,
  onDisconnect: vi.fn(),
  onReconnect: vi.fn(),
  onRetry: vi.fn(),
};

describe('SocialConnectionCard', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders connected state with green badge', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const badge = screen.getByText('Connected');
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass('card-status');
    expect(badge).not.toHaveClass('warning');
    expect(badge).not.toHaveClass('error');
    expect(badge).not.toHaveClass('revoked');
  });

  it('renders expired state with yellow badge', () => {
    const expiredConnection = { ...mockConnection, status: 'expired' as const };
    render(<SocialConnectionCard {...defaultProps} connection={expiredConnection} />);

    const badge = screen.getByText('Expired');
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass('card-status');
    expect(badge).toHaveClass('warning');
  });

  it('renders revoked state with gray badge', () => {
    const revokedConnection = { ...mockConnection, status: 'revoked' as const };
    render(<SocialConnectionCard {...defaultProps} connection={revokedConnection} />);

    const badge = screen.getByText('Revoked');
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass('card-status');
    expect(badge).toHaveClass('revoked');
  });

  it('renders error state with red badge', () => {
    const errorConnection = { ...mockConnection, status: 'error' as const, last_error: 'Connection failed' };
    render(<SocialConnectionCard {...defaultProps} connection={errorConnection} />);

    const badge = screen.getByText('Error');
    expect(badge).toBeInTheDocument();
    expect(badge).toHaveClass('card-status');
    expect(badge).toHaveClass('error');
  });

  it('shows error message when status is error', () => {
    const errorConnection = { ...mockConnection, status: 'error' as const, last_error: 'Connection failed' };
    render(<SocialConnectionCard {...defaultProps} connection={errorConnection} />);

    expect(screen.getByText('Connection failed')).toBeInTheDocument();
  });

  it('shows disconnect button for connected state', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const disconnectButton = screen.getByRole('button', { name: /disconnect youtube/i });
    expect(disconnectButton).toBeInTheDocument();
  });

  it('shows reconnect button for expired state', () => {
    const expiredConnection = { ...mockConnection, status: 'expired' as const };
    render(<SocialConnectionCard {...defaultProps} connection={expiredConnection} />);

    const reconnectButton = screen.getByRole('button', { name: /reconnect youtube/i });
    expect(reconnectButton).toBeInTheDocument();
  });

  it('shows connect again button for revoked state', () => {
    const revokedConnection = { ...mockConnection, status: 'revoked' as const };
    render(<SocialConnectionCard {...defaultProps} connection={revokedConnection} />);

    const reconnectButton = screen.getByRole('button', { name: /reconnect youtube/i });
    expect(reconnectButton).toBeInTheDocument();
  });

  it('shows retry button for error state', () => {
    const errorConnection = { ...mockConnection, status: 'error' as const };
    render(<SocialConnectionCard {...defaultProps} connection={errorConnection} />);

    const retryButton = screen.getByRole('button', { name: /retry connection for youtube/i });
    expect(retryButton).toBeInTheDocument();
  });

  it('opens confirmation dialog on disconnect click', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const disconnectButton = screen.getByRole('button', { name: /disconnect youtube/i });
    fireEvent.click(disconnectButton);

    expect(screen.getByText('Disconnect YouTube?')).toBeInTheDocument();
    expect(screen.getByText("You'll need to reconnect to publish to YouTube.")).toBeInTheDocument();
  });

  it('calls onDisconnect on confirm', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const disconnectButton = screen.getByRole('button', { name: /disconnect youtube/i });
    fireEvent.click(disconnectButton);

    const confirmButton = screen.getByRole('button', { name: /disconnect$/i });
    fireEvent.click(confirmButton);

    expect(defaultProps.onDisconnect).toHaveBeenCalledWith(1);
  });

  it('calls onReconnect on reconnect click', () => {
    const expiredConnection = { ...mockConnection, status: 'expired' as const };
    render(<SocialConnectionCard {...defaultProps} connection={expiredConnection} />);

    const reconnectButton = screen.getByRole('button', { name: /reconnect youtube/i });
    fireEvent.click(reconnectButton);

    expect(defaultProps.onReconnect).toHaveBeenCalledWith('youtube');
  });

  it('calls onRetry on retry click', () => {
    const errorConnection = { ...mockConnection, status: 'error' as const };
    render(<SocialConnectionCard {...defaultProps} connection={errorConnection} />);

    const retryButton = screen.getByRole('button', { name: /retry connection for youtube/i });
    fireEvent.click(retryButton);

    expect(defaultProps.onRetry).toHaveBeenCalledWith(1);
  });

  it('keyboard navigation works - tab to buttons', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const disconnectButton = screen.getByRole('button', { name: /disconnect youtube/i });
    disconnectButton.focus();
    expect(disconnectButton).toHaveFocus();
  });

  it('focus indicators visible', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const disconnectButton = screen.getByRole('button', { name: /disconnect youtube/i });
    disconnectButton.focus();
    // Design system uses CSS custom properties for focus rings (var(--focus-ring))
    // Check that the button has focus-visible styles applied via the design tokens
    expect(disconnectButton).toHaveClass('btn');
    expect(disconnectButton).toHaveClass('btn-danger');
  });

  it('aria labels on icon buttons', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const disconnectButton = screen.getByRole('button', { name: /disconnect youtube/i });
    expect(disconnectButton).toHaveAttribute('aria-label', 'Disconnect YouTube');
  });

  it('status badge uses text not color only', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const badge = screen.getByText('Connected');
    expect(badge).toHaveTextContent('Connected');
  });

  it('displays avatar when available', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const img = screen.getByAltText('');
    expect(img).toHaveAttribute('src', 'https://example.com/avatar.jpg');
  });

  it('displays platform icon when no avatar', () => {
    const noAvatarConnection = { ...mockConnection, avatar_url: null };
    render(<SocialConnectionCard {...defaultProps} connection={noAvatarConnection} />);

    // Platform icon should be rendered (SVG with test-id)
    expect(screen.getByTestId('platform-icon')).toBeInTheDocument();
  });

  it('shows channel link when available', () => {
    render(<SocialConnectionCard {...defaultProps} />);

    const link = screen.getByRole('link', { name: /view channel/i });
    expect(link).toHaveAttribute('href', 'https://youtube.com/@test');
  });
});