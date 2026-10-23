import { render, screen, fireEvent } from '@testing-library/react';
import { ConnectPlatformModal } from './ConnectPlatformModal';

const defaultProps = {
  isOpen: true,
  onClose: vi.fn(),
  onConnect: vi.fn(),
  loading: false,
  error: null,
};

describe('ConnectPlatformModal', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders platform grid', () => {
    render(<ConnectPlatformModal {...defaultProps} />);

    expect(screen.getByText('YouTube')).toBeInTheDocument();
    expect(screen.getByText('Instagram')).toBeInTheDocument();
    expect(screen.getByText('TikTok')).toBeInTheDocument();
    expect(screen.getByText('Publish videos to your YouTube channel')).toBeInTheDocument();
    expect(screen.getByText('Publish reels and posts to Instagram')).toBeInTheDocument();
    expect(screen.getByText('Publish videos to your TikTok account')).toBeInTheDocument();
  });

  it('shows loading during initiation', () => {
    render(<ConnectPlatformModal {...defaultProps} loading={true} />);

    expect(screen.getByText('Connecting...')).toBeInTheDocument();
    expect(screen.getByRole('status')).toBeInTheDocument();
  });

  it('shows error on initiation failure', () => {
    render(<ConnectPlatformModal {...defaultProps} error="Failed to connect" />);

    expect(screen.getByText('Failed to connect')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toBeInTheDocument();
  });

  it('clicking platform calls onConnect', () => {
    render(<ConnectPlatformModal {...defaultProps} />);

    const youtubeButton = screen.getByRole('button', { name: /connect youtube/i });
    fireEvent.click(youtubeButton);

    expect(defaultProps.onConnect).toHaveBeenCalledWith('youtube');
  });

  it('does not call onConnect when loading', () => {
    render(<ConnectPlatformModal {...defaultProps} loading={true} />);

    const youtubeButton = screen.getByRole('button', { name: /connect youtube/i });
    fireEvent.click(youtubeButton);

    expect(defaultProps.onConnect).not.toHaveBeenCalled();
  });

  it('keyboard navigation between platforms', () => {
    render(<ConnectPlatformModal {...defaultProps} />);

    const youtubeButton = screen.getByRole('button', { name: /connect youtube/i });
    youtubeButton.focus();
    expect(youtubeButton).toHaveFocus();

    fireEvent.keyDown(youtubeButton, { key: 'ArrowRight' });

    const instagramButton = screen.getByRole('button', { name: /connect instagram/i });
    expect(instagramButton).toHaveFocus();
  });

  it('escape closes modal', () => {
    render(<ConnectPlatformModal {...defaultProps} />);

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(defaultProps.onClose).toHaveBeenCalled();
  });

  it('enter key selects platform', () => {
    render(<ConnectPlatformModal {...defaultProps} />);

    const youtubeButton = screen.getByRole('button', { name: /connect youtube/i });
    youtubeButton.focus();
    fireEvent.keyDown(youtubeButton, { key: 'Enter' });

    expect(defaultProps.onConnect).toHaveBeenCalledWith('youtube');
  });

  it('focus trapped in modal', () => {
    render(<ConnectPlatformModal {...defaultProps} />);

    const closeButton = screen.getByRole('button', { name: /close modal/i });
    const youtubeButton = screen.getByRole('button', { name: /connect youtube/i });

    // Tab from close button should go to first platform button
    closeButton.focus();
    fireEvent.keyDown(closeButton, { key: 'Tab', shiftKey: false });

    // Focus should be trapped within modal
    expect(document.activeElement).toBe(youtubeButton);
  });

  it('aria labels on platform buttons', () => {
    render(<ConnectPlatformModal {...defaultProps} />);

    expect(screen.getByRole('button', { name: /connect youtube/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /connect instagram/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /connect tiktok/i })).toBeInTheDocument();
  });

  it('does not render when closed', () => {
    render(<ConnectPlatformModal {...defaultProps} isOpen={false} />);

    expect(screen.queryByText('Connect Social Account')).not.toBeInTheDocument();
  });
});