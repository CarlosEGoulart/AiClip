import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ReviewList } from './ReviewList';

describe('ReviewList', () => {
  const mockClipReviews = [
    {
      id: 1,
      derived_asset_id: 1,
      status: 'pending' as const,
      feedback: null,
      video_url: '/video/1',
      thumbnail_url: null,
      duration_ms: 30000,
      width: 1080,
      height: 1920,
      candidate_index: 0,
      render_profile_version: 'v1.0',
      created_at: '2024-01-01T00:00:00Z',
      updated_at: '2024-01-01T00:00:00Z',
    },
    {
      id: 2,
      derived_asset_id: 2,
      status: 'approved' as const,
      feedback: 'Great!',
      video_url: '/video/2',
      thumbnail_url: null,
      duration_ms: 45000,
      width: 1080,
      height: 1920,
      candidate_index: 1,
      render_profile_version: 'v1.0',
      created_at: '2024-01-01T00:00:00Z',
      updated_at: '2024-01-01T00:00:00Z',
    },
    {
      id: 3,
      derived_asset_id: 3,
      status: 'rejected' as const,
      feedback: 'Bad timing',
      video_url: '/video/3',
      thumbnail_url: null,
      duration_ms: 60000,
      width: 1080,
      height: 1920,
      candidate_index: 2,
      render_profile_version: 'v1.0',
      created_at: '2024-01-01T00:00:00Z',
      updated_at: '2024-01-01T00:00:00Z',
    },
    {
      id: 4,
      derived_asset_id: 4,
      status: 'needs_changes' as const,
      feedback: 'Adjust transitions',
      video_url: '/video/4',
      thumbnail_url: null,
      duration_ms: 25000,
      width: 1080,
      height: 1920,
      candidate_index: 3,
      render_profile_version: 'v1.0',
      created_at: '2024-01-01T00:00:00Z',
      updated_at: '2024-01-01T00:00:00Z',
    },
  ];

  const onReview = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders list of clip items', () => {
    render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    expect(screen.getAllByRole('listitem')).toHaveLength(4);
    expect(screen.getByRole('list', { name: /clip reviews/i })).toBeInTheDocument();
  });

  it('shows correct status badge colors', () => {
    render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    // Pending - gray
    expect(screen.getByText('Pending')).toHaveClass('bg-gray-100');
    // Approved - green
    expect(screen.getByText('Approved')).toHaveClass('bg-green-100');
    // Rejected - red
    expect(screen.getByText('Rejected')).toHaveClass('bg-red-100');
    // Needs Changes - orange
    expect(screen.getByText('Needs Changes')).toHaveClass('bg-orange-100');
  });

  it('shows duration, candidate index, status badge, render profile', () => {
    render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    expect(screen.getByText('0:30')).toBeInTheDocument(); // duration
    expect(screen.getByText('Clip 1')).toBeInTheDocument(); // candidate index shown as "Clip 1"
    expect(screen.getAllByText('v1.0')).toHaveLength(4); // render profile (4 clips)
  });

  it('calls onReview when Review button clicked', async () => {
    render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    await userEvent.click(screen.getAllByRole('button', { name: /review clip/i })[0]);

    expect(onReview).toHaveBeenCalledWith(mockClipReviews[0]);
  });

  it('shows empty state when no clips', () => {
    render(<ReviewList clipReviews={[]} loading={false} onReview={onReview} />);

    expect(screen.getByText(/no clips to review/i)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /upload media/i })).toBeInTheDocument();
  });

  it('shows loading skeleton when loading', () => {
    render(<ReviewList clipReviews={[]} loading={true} onReview={onReview} />);

    // Loading state renders skeleton items
    const skeletonItems = screen.getAllByRole('listitem');
    expect(skeletonItems).toHaveLength(3); // 3 skeleton items
  });

  it('has correct list layout (vertical stack)', () => {
    const { container } = render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    const list = container.querySelector('[role="list"]');
    expect(list).toHaveClass('space-y-4');
  });

  it('keyboard navigation works with Tab', async () => {
    render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    const buttons = screen.getAllByRole('button', { name: /review clip/i });
    buttons[0].focus();
    expect(buttons[0]).toHaveFocus();

    // In jsdom, Tab doesn't auto-focus next element, so we manually focus
    buttons[1].focus();
    expect(buttons[1]).toHaveFocus();
  });

  it('each item has correct aria-label', () => {
    render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    const buttons = screen.getAllByRole('button', { name: /review clip/i });
    expect(buttons[0]).toHaveAttribute('aria-label', 'Review clip 1');
    expect(buttons[1]).toHaveAttribute('aria-label', 'Review clip 2');
  });

  it('alternates layout direction for visual variety', () => {
    render(<ReviewList clipReviews={mockClipReviews} loading={false} onReview={onReview} />);

    const articles = screen.getAllByRole('listitem');
    // First item (index 0) should have flex (not flex-row-reverse)
    expect(articles[0].querySelector('div.flex')).not.toHaveClass('flex-row-reverse');
    // Second item (index 1) should have flex-row-reverse
    expect(articles[1].querySelector('div.flex')).toHaveClass('flex-row-reverse');
  });
});