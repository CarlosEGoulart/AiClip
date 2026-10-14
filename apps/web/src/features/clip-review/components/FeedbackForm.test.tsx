import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FeedbackForm } from './FeedbackForm';

describe('FeedbackForm', () => {
  const clipReviewId = 1;
  const onSubmit = vi.fn().mockResolvedValue(undefined);

  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
  });

  it('renders textarea with label', () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    expect(screen.getByLabelText(/feedback \(required for rejected\)/i)).toBeInTheDocument();
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });

  it('shows character counter 0/1000 initially', () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    expect(screen.getByText('0/1000')).toBeInTheDocument();
  });

  it('updates counter when typing', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'Hello world');

    expect(screen.getByText('11/1000')).toBeInTheDocument();
  });

  it('shows warning at 800 characters', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    const longText = 'a'.repeat(801);
    await userEvent.type(textarea, longText);

    const counter = screen.getByText('801/1000');
    expect(counter).toHaveClass('text-yellow-600');
  });

  it('caps counter at 1000 characters (max allowed)', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    const longText = 'a'.repeat(1001);
    await userEvent.type(textarea, longText);

    // Counter caps at 1000 (warning yellow since at max but not over)
    const counter = screen.getByText('1000/1000');
    expect(counter).toHaveClass('text-yellow-600');
    // Button is enabled at exactly 1000 (not over limit)
    expect(screen.getByRole('button', { name: /submit feedback/i })).not.toBeDisabled();
  });

  it('requires minimum 10 characters for rejected status', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'Short');

    expect(screen.getByRole('button', { name: /submit feedback/i })).toBeDisabled();
    expect(screen.getByText(/minimum 10 characters required/i)).toBeInTheDocument();
  });

  it('enables submit when 10+ characters for rejected', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'This is long enough feedback');

    expect(screen.getByRole('button', { name: /submit feedback/i })).not.toBeDisabled();
  });

  it('does not require minimum for approved status', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="approved" onSubmit={onSubmit} />);

    expect(screen.getByRole('button', { name: /submit feedback/i })).not.toBeDisabled();
  });

  it('saves draft to localStorage on change', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'Draft feedback');

    expect(localStorage.getItem(`clip-review-feedback-${clipReviewId}`)).toBe('Draft feedback');
  });

  it('loads draft from localStorage on mount', () => {
    localStorage.setItem(`clip-review-feedback-${clipReviewId}`, 'Saved draft');

    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    expect(screen.getByRole('textbox')).toHaveValue('Saved draft');
  });

  it('clears draft on submit', async () => {
    localStorage.setItem(`clip-review-feedback-${clipReviewId}`, 'Saved draft');

    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'New feedback');

    await userEvent.click(screen.getByRole('button', { name: /submit feedback/i }));

    await waitFor(() => {
      expect(localStorage.getItem(`clip-review-feedback-${clipReviewId}`)).toBeNull();
    });
  });

  it('shows validation error when submitting with less than 10 chars', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'Short'); // 5 chars

    // Button is disabled, so we can't click it. The error is already shown.
    expect(screen.getByText(/minimum 10 characters required/i)).toBeInTheDocument();
  });

  it('calls onSubmit with feedback on valid submit', async () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'Valid feedback for rejection');

    await userEvent.click(screen.getByRole('button', { name: /submit feedback/i }));

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalledWith('Valid feedback for rejection');
    });
  });

  it('shows loading state during submit', async () => {
    const slowSubmit = vi.fn(() => new Promise((resolve) => setTimeout(resolve, 100)));

    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={slowSubmit} />);

    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'Valid feedback for rejection');

    await userEvent.click(screen.getByRole('button', { name: /submit feedback/i }));

    expect(screen.getByRole('button', { name: /submitting…/i })).toBeInTheDocument();
  });

  it('disables form when disabled prop is true', () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} disabled />);

    expect(screen.getByRole('textbox')).toBeDisabled();
    expect(screen.getByRole('button', { name: /submit feedback/i })).toBeDisabled();
  });

  it('shows optional label for approved status', () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="approved" onSubmit={onSubmit} />);

    expect(screen.getByLabelText(/feedback \(optional\)/i)).toBeInTheDocument();
  });

  it('uses tabular-nums for character counter', () => {
    render(<FeedbackForm clipReviewId={clipReviewId} status="rejected" onSubmit={onSubmit} />);

    const counter = screen.getByText('0/1000');
    expect(counter).toHaveClass('tabular-nums');
  });
});