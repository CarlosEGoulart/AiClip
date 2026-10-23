import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { SocialConnections } from './SocialConnections';
import * as hooks from '../hooks';
import { MemoryRouter } from 'react-router-dom';
import { toast } from 'sonner';

const mockConnection = {
  id: 1,
  platform: 'youtube' as const,
  platform_label: 'YouTube',
  display_name: 'Test Channel',
  avatar_url: 'https://example.com/avatar.jpg',
  channel_url: 'https://youtube.com/@test',
  status: 'connected' as const,
  last_error: null,
  created_at: '2026-10-22T10:00:00.000000Z',
  updated_at: '2026-10-22T10:00:00.000000Z',
};

const wrapper = ({ children }: { children: React.ReactNode }) => (
  <MemoryRouter initialEntries={['/connections']}>
    {children}
  </MemoryRouter>
);

vi.mock('../hooks');
vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    dismiss: vi.fn(),
  },
  Toaster: () => null,
}));

describe('SocialConnections', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('loads connections on mount', () => {
    vi.mocked(hooks.useSocialConnections).mockReturnValue({
      connections: [],
      loading: false,
      error: null,
      fetchConnections: vi.fn(),
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useConnectPlatform).mockReturnValue({
      connect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useDisconnectConnection).mockReturnValue({
      disconnect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    render(<SocialConnections />, { wrapper });

    expect(hooks.useSocialConnections).toHaveBeenCalled();
  });

  it('opens modal on connect click', () => {
    vi.mocked(hooks.useSocialConnections).mockReturnValue({
      connections: [],
      loading: false,
      error: null,
      fetchConnections: vi.fn(),
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useConnectPlatform).mockReturnValue({
      connect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useDisconnectConnection).mockReturnValue({
      disconnect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    render(<SocialConnections />, { wrapper });

    const connectButton = screen.getByRole('button', { name: /connect account/i });
    fireEvent.click(connectButton);

    expect(screen.getByText('Connect Social Account')).toBeInTheDocument();
  });

  it('handles connect success redirect', async () => {
    vi.mocked(hooks.useSocialConnections).mockReturnValue({
      connections: [],
      loading: false,
      error: null,
      fetchConnections: vi.fn().mockResolvedValue(undefined),
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useConnectPlatform).mockReturnValue({
      connect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useDisconnectConnection).mockReturnValue({
      disconnect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    render(<SocialConnections />, {
      wrapper: ({ children }) => (
        <MemoryRouter initialEntries={[{ pathname: '/connections', search: '?connected=youtube' }]}>
          {children}
        </MemoryRouter>
      ),
    });

    await waitFor(() => {
      expect(toast.success).toHaveBeenCalledWith('YouTube connected successfully!');
    });
  });

  it('handles connect error redirect', async () => {
    vi.mocked(hooks.useSocialConnections).mockReturnValue({
      connections: [],
      loading: false,
      error: null,
      fetchConnections: vi.fn(),
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useConnectPlatform).mockReturnValue({
      connect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useDisconnectConnection).mockReturnValue({
      disconnect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    render(<SocialConnections />, {
      wrapper: ({ children }) => (
        <MemoryRouter initialEntries={[{ pathname: '/connections', search: '?error=invalid_state' }]}>
          {children}
        </MemoryRouter>
      ),
    });

    await waitFor(() => {
      expect(toast.error).toHaveBeenCalledWith('Invalid or expired authorization state. Please try again.');
    });
  });

  it('handles disconnect success', async () => {
    const disconnectFn = vi.fn().mockResolvedValue(true);
    const fetchFn = vi.fn().mockResolvedValue(undefined);

    vi.mocked(hooks.useSocialConnections).mockReturnValue({
      connections: [mockConnection],
      loading: false,
      error: null,
      fetchConnections: fetchFn,
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useConnectPlatform).mockReturnValue({
      connect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useDisconnectConnection).mockReturnValue({
      disconnect: disconnectFn,
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    render(<SocialConnections />, { wrapper });

    // Find and click disconnect button
    const disconnectButton = screen.getByRole('button', { name: /disconnect youtube/i });
    fireEvent.click(disconnectButton);

    // Confirm in dialog - the confirm button is inside the dialog with role="dialog"
    // and has class "btn btn-danger" with just "Disconnect" text
    const dialog = screen.getByRole('dialog');
    const confirmButton = within(dialog).getByRole('button', { name: /^disconnect$/i });
    fireEvent.click(confirmButton);

    await waitFor(() => {
      expect(disconnectFn).toHaveBeenCalledWith(1);
    });
  });

  it('handles reconnect success', async () => {
    const connectFn = vi.fn().mockResolvedValue({
      authorization_url: 'https://example.com/auth',
      state: 'abc123',
      connection_id: 5,
    });

    vi.mocked(hooks.useSocialConnections).mockReturnValue({
      connections: [{ ...mockConnection, status: 'expired' as const }],
      loading: false,
      error: null,
      fetchConnections: vi.fn(),
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useConnectPlatform).mockReturnValue({
      connect: connectFn,
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    vi.mocked(hooks.useDisconnectConnection).mockReturnValue({
      disconnect: vi.fn(),
      isPending: false,
      error: null,
      clearErrors: vi.fn(),
    });

    render(<SocialConnections />, { wrapper });

    const reconnectButton = screen.getByRole('button', { name: /reconnect youtube/i });
    fireEvent.click(reconnectButton);

    await waitFor(() => {
      expect(connectFn).toHaveBeenCalledWith('youtube');
    });
  });
});