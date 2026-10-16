import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { VideoPlayer } from './VideoPlayer';

// Mock video element methods
const mockPlay = vi.fn().mockResolvedValue(undefined);
const mockPause = vi.fn();
const mockLoad = vi.fn();

HTMLMediaElement.prototype.play = mockPlay;
HTMLMediaElement.prototype.pause = mockPause;
HTMLMediaElement.prototype.load = mockLoad;

describe('VideoPlayer', () => {
  const videoUrl = 'https://example.com/video.mp4';
  const posterUrl = 'https://example.com/poster.jpg';

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders video element with correct src', () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video');
    expect(video).toBeInTheDocument();
    expect(video).toHaveAttribute('src', videoUrl);
  });

  it('renders poster when provided', () => {
    render(<VideoPlayer videoUrl={videoUrl} poster={posterUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video');
    expect(video).toHaveAttribute('poster', posterUrl);
  });

  it('shows loading state initially', () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    expect(screen.getByLabelText(/loading video/i)).toBeInTheDocument();
  });

  it('hides loading state when video can play', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));
    await waitFor(() => {
      expect(screen.queryByLabelText(/loading video/i)).not.toBeInTheDocument();
    });
  });

  it('toggles play/pause on button click', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));

    // Initially paused - button shows "Play"
    const playButton = screen.getByRole('button', { name: /play/i });
    expect(playButton).toBeInTheDocument();

    await userEvent.click(playButton);
    expect(mockPlay).toHaveBeenCalled();

    // After play, button should show pause
    // Note: This test depends on internal state, adjust as needed
  });

  it('arrow left seeks backward 5 seconds', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));
    video.focus();

    Object.defineProperty(video, 'currentTime', { value: 10, writable: true });
    Object.defineProperty(video, 'duration', { value: 60, writable: true });

    fireEvent.keyDown(document, { key: 'ArrowLeft' });
    expect(video.currentTime).toBe(5);
  });

  it('arrow right seeks forward 5 seconds', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));
    video.focus();

    Object.defineProperty(video, 'currentTime', { value: 10, writable: true });
    Object.defineProperty(video, 'duration', { value: 60, writable: true });

    fireEvent.keyDown(document, { key: 'ArrowRight' });
    expect(video.currentTime).toBe(15);
  });

  it('arrow up increases volume', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));
    video.focus();

    Object.defineProperty(video, 'volume', { value: 0.5, writable: true });

    fireEvent.keyDown(document, { key: 'ArrowUp' });
    expect(video.volume).toBeCloseTo(0.6, 1);
  });

  it('arrow down decreases volume', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));
    video.focus();

    Object.defineProperty(video, 'volume', { value: 0.5, writable: true });

    fireEvent.keyDown(document, { key: 'ArrowDown' });
    expect(video.volume).toBeCloseTo(0.4, 1);
  });

  it('M key toggles mute', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));
    video.focus();

    Object.defineProperty(video, 'muted', { value: false, writable: true });

    fireEvent.keyDown(document, { key: 'm' });
    expect(video.muted).toBe(true);

    fireEvent.keyDown(document, { key: 'm' });
    expect(video.muted).toBe(false);
  });

  it('number keys seek to percentage', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));
    video.focus();

    Object.defineProperty(video, 'duration', { value: 100, writable: true });

    fireEvent.keyDown(document, { key: '5' });
    expect(video.currentTime).toBe(50);
  });

  it('shows error state with retry button on error', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('error'));

    await waitFor(() => {
      expect(screen.getByText(/unable to play video/i)).toBeInTheDocument();
      expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
    });
  });

  it('has ARIA labels on all controls', () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));

    expect(screen.getByRole('button', { name: /play/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /mute/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /enter fullscreen/i })).toBeInTheDocument();
    expect(screen.getByLabelText(/seek/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/volume/i)).toBeInTheDocument();
  });

  it('has visible focus styles on buttons', () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));

    const playButton = screen.getByRole('button', { name: /play/i });
    expect(playButton).toHaveClass('focus:outline-none');
    expect(playButton).toHaveClass('focus:ring-2');
  });

  it('calls onLoad callback when video loads', async () => {
    const onLoad = vi.fn();
    render(<VideoPlayer videoUrl={videoUrl} onLoad={onLoad} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('loadedmetadata'));

    await waitFor(() => {
      expect(onLoad).toHaveBeenCalled();
    });
  });

  it('calls onError callback when video errors', async () => {
    const onError = vi.fn();
    render(<VideoPlayer videoUrl={videoUrl} onError={onError} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('error'));

    await waitFor(() => {
      expect(onError).toHaveBeenCalled();
    });
  });

  it('controls appear on hover/focus', async () => {
    render(<VideoPlayer videoUrl={videoUrl} />);
    const video = screen.getByRole('region', { name: /video player/i }).querySelector('video') as HTMLVideoElement;
    fireEvent(video, new Event('canplay'));

    const playerRegion = screen.getByRole('region', { name: /video player/i });
    const controls = playerRegion.querySelector('[role="group"]');

    // Initially hidden (opacity-0)
    expect(controls).toHaveClass('opacity-0');

    // On mouse enter, controls should appear
    fireEvent.mouseEnter(playerRegion);
    expect(controls).toHaveClass('opacity-100');
  });
});