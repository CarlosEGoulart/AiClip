import { useRef, useEffect, useCallback, useState } from 'react';
import type { PublishClipRequest } from '../types';
import '../design-tokens.css';

type Platform = 'youtube' | 'instagram' | 'tiktok';

const PLATFORM_CONFIG: Record<Platform, { label: string; ariaLabel: string }> = {
  youtube: { label: 'YouTube', ariaLabel: 'Publish to YouTube' },
  instagram: { label: 'Instagram', ariaLabel: 'Publish to Instagram' },
  tiktok: { label: 'TikTok', ariaLabel: 'Publish to TikTok' },
};

interface PublishClipDialogProps {
  isOpen: boolean;
  onClose: () => void;
  onPublish: (body: PublishClipRequest) => void;
  clipReviewId?: number;
  availablePlatforms?: Platform[];
  loading?: boolean;
  error?: string | null;
  success?: boolean;
}

export function PublishClipDialog({
  isOpen,
  onClose,
  onPublish,
  clipReviewId = 0,
  availablePlatforms = [],
  loading = false,
  error = null,
  success = false,
}: PublishClipDialogProps) {
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [platform, setPlatform] = useState<Platform>(
    availablePlatforms.includes('youtube') ? 'youtube' : (availablePlatforms[0] ?? 'youtube'),
  );
  const [formError, setFormError] = useState<string | null>(null);
  const modalRef = useRef<HTMLDivElement>(null);
  const titleInputRef = useRef<HTMLInputElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const publishButtonRef = useRef<HTMLButtonElement>(null);
  const liveRegionRef = useRef<HTMLSpanElement>(null);

  const focusableElements = useCallback(() => {
    if (!modalRef.current) return [];
    const elements: HTMLElement[] = [];
    if (closeButtonRef.current) elements.push(closeButtonRef.current);
    if (titleInputRef.current) elements.push(titleInputRef.current);
    if (publishButtonRef.current) elements.push(publishButtonRef.current);
    return elements;
  }, []);

  useEffect(() => {
    if (isOpen) {
      document.body.style.overflow = 'hidden';
      setTimeout(() => {
        titleInputRef.current?.focus();
      }, 0);
    } else {
      document.body.style.overflow = '';
    }

    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
        return;
      }

      if (e.key === 'Tab') {
        const elements = focusableElements();
        if (elements.length <= 1) return;
        const currentIndex = elements.findIndex((el) => el === document.activeElement);
        if (currentIndex === -1) return;

        if (e.shiftKey) {
          e.preventDefault();
          const prevIndex = currentIndex === 0 ? elements.length - 1 : currentIndex - 1;
          elements[prevIndex].focus();
        } else {
          e.preventDefault();
          const nextIndex = currentIndex === elements.length - 1 ? 0 : currentIndex + 1;
          elements[nextIndex].focus();
        }
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose, focusableElements]);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!title.trim()) {
      setFormError('Title is required');
      return;
    }
    setFormError(null);

    const body: PublishClipRequest = {
      clip_review_id: clipReviewId,
      platform,
      title: title.trim(),
      description: description.trim() || undefined,
    };

    onPublish(body);
  };

  const handleClose = () => {
    setTitle('');
    setDescription('');
    setFormError(null);
    onClose();
  };

  if (!isOpen) return null;

  const platformOptions = availablePlatforms.map((p) => {
    const config = PLATFORM_CONFIG[p];
    return (
      <label key={p} className="platform-option">
        <input
          type="radio"
          name="publish-platform"
          value={p}
          checked={platform === p}
          onChange={() => setPlatform(p)}
          disabled={!availablePlatforms.includes(p)}
          aria-label={config.ariaLabel}
        />
        {config.label}
        {!availablePlatforms.includes(p) && <span className="platform-disabled">(not connected)</span>}
      </label>
    );
  });

  return (
    <div
      className="publish-dialog-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="publish-dialog-title"
    >
      <div
        ref={modalRef}
        tabIndex={-1}
        className="publish-dialog"
      >
        <div className="publish-dialog-header">
          <h2 id="publish-dialog-title" className="publish-dialog-title">
            Publish to {PLATFORM_CONFIG[platform].label}
          </h2>
          <button
            ref={closeButtonRef}
            type="button"
            onClick={handleClose}
            className="publish-dialog-close"
            aria-label="Close dialog"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {success && (
          <div className="publish-dialog-success" role="status" aria-live="polite">
            <p>Clip published successfully!</p>
          </div>
        )}

        {!success && (
          <>
            <form onSubmit={handleSubmit} className="publish-dialog-form">
              <div className="form-group">
                <label htmlFor="publish-platform" className="form-label">
                  Platform
                </label>
                <div
                  id="publish-platform"
                  className="platform-selector"
                  role="radiogroup"
                  aria-label="Publish platform"
                >
                  {platformOptions}
                </div>
              </div>

              <div className="form-group">
                <label htmlFor="publish-title" className="form-label">
                  Title
                </label>
                <input
                  id="publish-title"
                  ref={titleInputRef}
                  type="text"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  className="form-input"
                  placeholder="Enter a title for your video"
                  maxLength={255}
                  required
                  aria-required="true"
                />
              </div>

              <div className="form-group">
                <label htmlFor="publish-description" className="form-label">
                  Description
                </label>
                <textarea
                  id="publish-description"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  className="form-textarea"
                  placeholder="Optional description"
                  maxLength={1000}
                  rows={3}
                />
              </div>

              {formError && (
                <div className="form-error" role="alert">
                  {formError}
                </div>
              )}

              <div className="form-actions">
                <button
                  type="button"
                  onClick={handleClose}
                  className="btn btn-secondary"
                  disabled={loading}
                >
                  Cancel
                </button>
                <button
                  ref={publishButtonRef}
                  type="submit"
                  className="btn btn-primary"
                  disabled={loading || !title.trim()}
                  aria-label={PLATFORM_CONFIG[platform].ariaLabel}
                >
                  {loading ? 'Publishing…' : 'Publish'}
                </button>
              </div>
            </form>

            {error && (
              <div className="dialog-error" role="alert">
                {error}
              </div>
            )}
          </>
        )}

        <span ref={liveRegionRef} className="sr-only" aria-live="polite"></span>
      </div>
    </div>
  );
}
