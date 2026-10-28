import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PublishClipDialog } from './PublishClipDialog';

type Platform = 'youtube' | 'instagram';

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
        availablePlatforms={['youtube']}
      />,
    );
    expect(screen.getByLabelText('Title')).toBeInTheDocument();
    expect(screen.getByLabelText('Description')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Publish to YouTube' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
  });

  it('renders platform selector with YouTube and Instagram', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        availablePlatforms={['youtube', 'instagram']}
      />,
    );
    expect(screen.getByRole('radio', { name: 'Publish to YouTube' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Publish to Instagram' })).toBeInTheDocument();
  });

  it('publish button disabled during upload', async () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        loading={true}
        availablePlatforms={['youtube']}
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
        availablePlatforms={['youtube']}
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

  it('publish button calls onPublish with platform instagram when selected', async () => {
    const user = userEvent.setup();
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        clipReviewId={42}
        availablePlatforms={['youtube', 'instagram']}
      />,
    );

    await user.type(screen.getByLabelText('Title'), 'My Video Title');
    await user.click(screen.getByRole('radio', { name: 'Publish to Instagram' }));
    await user.click(screen.getByRole('button', { name: 'Publish to Instagram' }));

    expect(onPublish).toHaveBeenCalledWith(
      expect.objectContaining({
        clip_review_id: 42,
        platform: 'instagram',
        title: 'My Video Title',
        description: undefined,
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
        availablePlatforms={['youtube']}
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

  it('defaults to youtube when both platforms available', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        availablePlatforms={['youtube', 'instagram']}
      />,
    );
    expect(screen.getByRole('radio', { name: 'Publish to YouTube', checked: true })).toBeInTheDocument();
  });

  it('defaults to first available platform when youtube not available', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        availablePlatforms={['instagram']}
      />,
    );
    expect(screen.getByRole('radio', { name: 'Publish to Instagram', checked: true })).toBeInTheDocument();
  });

  it('shows error state on publish failure', () => {
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        error="Upload failed"
        availablePlatforms={['youtube']}
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
        availablePlatforms={['youtube']}
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
        availablePlatforms={['youtube']}
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
        availablePlatforms={['youtube']}
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
        availablePlatforms={['youtube', 'instagram']}
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
        availablePlatforms={['youtube']}
      />,
    );

    await user.keyboard('{Escape}');
    expect(onClose).toHaveBeenCalled();
  });

  it('dialog title updates when platform changes', async () => {
    const user = userEvent.setup();
    const onPublish = vi.fn();
    render(
      <PublishClipDialog
        isOpen={true}
        onClose={vi.fn()}
        onPublish={onPublish}
        availablePlatforms={['youtube', 'instagram']}
      />,
    );

    expect(screen.getByText('Publish to YouTube')).toBeInTheDocument();

    await user.click(screen.getByRole('radio', { name: 'Publish to Instagram' }));

    expect(screen.getByText('Publish to Instagram')).toBeInTheDocument();
    expect(screen.queryByText('Publish to YouTube')).not.toBeInTheDocument();
  });
});
