import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ReviewActions } from './ReviewActions';

describe('ReviewActions', () => {
  const onStatusChange = vi.fn().mockResolvedValue(undefined);

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders three buttons: Approve, Reject, Needs Changes', () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    expect(screen.getByRole('button', { name: /approve clip/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /reject clip/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /needs changes clip/i })).toBeInTheDocument();
  });

  it('opens confirmation dialog when clicking Approve', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));

    expect(screen.getByRole('alertdialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /confirm approve/i })).toBeInTheDocument();
  });

  it('opens confirmation dialog when clicking Reject', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /reject clip/i }));

    expect(screen.getByRole('alertdialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /confirm reject/i })).toBeInTheDocument();
    expect(screen.getByText(/feedback will be required/i)).toBeInTheDocument();
  });

  it('opens confirmation dialog when clicking Needs Changes', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /needs changes clip/i }));

    expect(screen.getByRole('alertdialog')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /confirm needs changes/i })).toBeInTheDocument();
    expect(screen.getByText(/feedback will be required/i)).toBeInTheDocument();
  });

  it('calls onStatusChange with approved when confirmed', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm approve/i }));

    await waitFor(() => {
      expect(onStatusChange).toHaveBeenCalledWith('approved');
    });
  });

  it('calls onStatusChange with rejected when confirmed', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /reject clip/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm reject/i }));

    await waitFor(() => {
      expect(onStatusChange).toHaveBeenCalledWith('rejected');
    });
  });

  it('calls onStatusChange with needs_changes when confirmed', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /needs changes clip/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm needs changes/i }));

    await waitFor(() => {
      expect(onStatusChange).toHaveBeenCalledWith('needs_changes');
    });
  });

  it('does not call onStatusChange when dialog is cancelled', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));
    await userEvent.click(screen.getByRole('button', { name: /cancel/i }));

    await waitFor(() => {
      expect(onStatusChange).not.toHaveBeenCalled();
    });
  });

  it('disables buttons during mutation', async () => {
    const slowOnStatusChange = vi.fn(() => new Promise((resolve) => setTimeout(resolve, 100)));

    render(<ReviewActions currentStatus="pending" onStatusChange={slowOnStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm approve/i }));

    expect(screen.getByRole('button', { name: /approve clip/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /reject clip/i })).toBeDisabled();
    expect(screen.getByRole('button', { name: /needs changes clip/i })).toBeDisabled();
  });

  it('shows spinner during loading', async () => {
    const slowOnStatusChange = vi.fn(() => new Promise((resolve) => setTimeout(resolve, 100)));

    render(<ReviewActions currentStatus="pending" onStatusChange={slowOnStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm approve/i }));

    // During loading, confirm button shows "Processing..."
    expect(screen.getByRole('button', { name: /processing…/i })).toBeInTheDocument();
  });

  it('announces result via aria-live', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));
    await userEvent.click(screen.getByRole('button', { name: /confirm approve/i }));

    await waitFor(() => {
      expect(screen.getByText(/clip approved/i)).toBeInTheDocument();
    });
  });

  it('closes dialog on Escape key', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));
    expect(screen.getByRole('alertdialog')).toBeInTheDocument();

    fireEvent.keyDown(document, { key: 'Escape' });

    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
  });

  it('shows current status as pressed', () => {
    render(<ReviewActions currentStatus="approved" onStatusChange={onStatusChange} />);

    expect(screen.getByRole('button', { name: /approve clip/i })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByRole('button', { name: /reject clip/i })).toHaveAttribute('aria-pressed', 'false');
  });

  it('traps focus in dialog', async () => {
    render(<ReviewActions currentStatus="pending" onStatusChange={onStatusChange} />);

    await userEvent.click(screen.getByRole('button', { name: /approve clip/i }));
    const dialog = screen.getByRole('alertdialog');
    const focusableElements = dialog.querySelectorAll<HTMLElement>(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );

    expect(focusableElements.length).toBeGreaterThan(0);
    expect(focusableElements[0]).toHaveFocus();
  });
});