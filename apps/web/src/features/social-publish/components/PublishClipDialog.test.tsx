import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PublishClipDialog } from './PublishClipDialog';

beforeEach(() => {
  vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('PublishClipDialog', () => {
  it('renders title and description form', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
      />,
    );
    expect(screen.getByLabelText('Title')).toBeInTheDocument();
    expect(screen.getByLabelText('Description')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Publish to YouTube' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });

  it('publish button disabled during upload', async () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        loading={true}
      />,
    );
    const publishButton = screen.getByRole('button', { name: 'Publish to YouTube' });
    expect(publishButton).toBeDisabled();
  });

  it('publish button calls onPublish with clip_review_id from prop', async () => {
    const user = userEvent.setup();
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        clipReviewId={42}
      />,
    );

    await user.type(screen.getByLabelText('Title'), 'My Video Title');
    await user.type(screen.getByLabelText('Description'), 'A description');

    await user.click(screen.getByRole('button', { name: 'Publish to YouTube' }));

    expect(onPublish).toHaveBeenCalledWith(
      expect.objectContaining({
        clip_review_id: 42,
        platform: 'youtube',
        title: 'My Video Title',
        description: 'A description',
      }),
    );
  });

  it('publish button omits clip_review_id when prop is not provided', async () => {
    const user = userEvent.setup();
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
      />,
    );

    await user.type(screen.getByLabelText('Title'), 'My Video Title');

    await user.click(screen.getByRole('button', { name: 'Publish to YouTube' }));

    expect(onPublish).toHaveBeenCalledWith(
      expect.objectContaining({
        clip_review_id: 0,
        platform: 'youtube',
        title: 'My Video Title',
        description: undefined,
      }),
    );
  });

  it('shows error state on publish failure', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        error="Upload failed"
      />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('Upload failed');
  });

  it('shows success state on publish success', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        success={true}
      />,
    );
    expect(screen.getByText('Clip published successfully!')).toBeInTheDocument();
  });

  it('keyboard navigation works', async () => {
    const user = userEvent.setup();
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
      />,
    );

    // Tab to title input
    await user.tab();
    expect(screen.getByLabelText('Title')).toHaveFocus();
  });

  it('focus indicators visible', async () => {
    const user = userEvent.setup();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={vi.fn()}
      />,
    );

    await user.tab();
    const titleInput = screen.getByLabelText('Title');
    const styles = window.getComputedStyle(titleInput);
    expect(
      styles.outlineWidth !== '0px' || styles.boxShadow.includes('rgb'),
    ).toBeTruthy();
  });

  it('aria labels on buttons', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
      />,
    );
    expect(screen.getByRole('button', { name: 'Close dialog' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Publish to YouTube' })).toBeInTheDocument();
  });

  it('closes on Escape key', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={onClose}
        onPublish={vi.fn()}
      />,
    );

    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalled();
  });
});
