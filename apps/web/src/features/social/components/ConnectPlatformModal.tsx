import { useState, useEffect, useRef, useCallback } from 'react';
import type { Platform } from '../types';
import '../design-tokens.css';

interface ConnectPlatformModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConnect: (platform: Platform) => void;
  loading?: boolean;
  error?: string | null;
}

const platforms: { id: Platform; label: string; description: string; color: string; icon: React.ReactNode }[] = [
  {
    id: 'youtube',
    label: 'YouTube',
    description: 'Publish videos to your YouTube channel',
    color: '#ff0000',
    icon: (
      <svg className="w-8 h-8" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true" style={{ color: '#fff' }}>
        <path d="M23.498 6.186a3.167 3.167 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.17 3.17 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.166 3.166 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.167 3.167 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
      </svg>
    ),
  },
  {
    id: 'instagram',
    label: 'Instagram',
    description: 'Publish reels and posts to Instagram',
    color: 'linear-gradient(135deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%)',
    icon: (
      <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" style={{ color: '#fff', strokeWidth: 2 }}>
        <rect x="2" y="2" width="20" height="20" rx="5" ry="5" stroke="currentColor" />
        <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" stroke="currentColor" />
        <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" stroke="currentColor" />
      </svg>
    ),
  },
  {
    id: 'tiktok',
    label: 'TikTok',
    description: 'Publish videos to your TikTok account',
    color: '#000000',
    icon: (
      <svg className="w-8 h-8" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true" style={{ color: '#fff' }}>
        <path d="M12.521 21.64c-1.793 0-3.519-.586-4.736-1.57a20.174 20.174 0 0 1-5.273-1.722 1.58 1.58 0 0 1-.38-2.423c.469-.468 3.21-2.232 5.582-2.807v-10.94c-.464-.464-.464-1.216 0-1.68a1.58 1.58 0 0 1 2.43-.46c.147.148 2.345 2.305 2.54 2.507.123.125 2.946 1.534 3.213 1.79a1.58 1.58 0 0 1 .46 2.43c-.464.465-1.216.464-1.68 0V12c-.572.103-1.558.54-2.72 1.035-1.166.496-2.546.812-4.112.812zm2.426-9.552c.34.158 1.143.844 1.547 1.372.403.527.852 1.22 1.036 1.62.185.4.44.903.44 1.032v.767h2.907V10.43c0-.629-.263-1.418-.766-2.112-.504-.693-1.307-1.13-2.16-1.13-.692 0-1.33.256-1.727.618-.397.362-.73.855-.73 1.32v.748h1.4zm-6.403 8.586c-.013.493-.166 1.32-.42 2.082-.254.762-.723 1.403-1.262 1.802a1.75 1.75 0 0 1-2.34.12v-2.707c.37-.27 2.151-2.257 2.455-2.724a1.47 1.47 0 0 1 1.566.744z" />
      </svg>
    ),
  },
];

export function ConnectPlatformModal({
  isOpen,
  onClose,
  onConnect,
  loading = false,
  error = null,
}: ConnectPlatformModalProps) {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const modalRef = useRef<HTMLDivElement>(null);
  const previousActiveElement = useRef<HTMLElement | null>(null);
  const platformButtonRefs = useRef<(HTMLButtonElement | null)[]>([]);
  const closeButtonRef = useRef<HTMLButtonElement>(null);

  const focusableElements = useCallback(() => {
    if (!modalRef.current) return [];
    const elements: HTMLElement[] = [];
    if (closeButtonRef.current) elements.push(closeButtonRef.current);
    platformButtonRefs.current.forEach((btn) => {
      if (btn) elements.push(btn);
    });
    return elements;
  }, []);

  const focusElement = useCallback((index: number) => {
    const elements = focusableElements();
    if (elements[index]) {
      elements[index].focus();
    }
  }, [focusableElements]);

  useEffect(() => {
    if (isOpen) {
      previousActiveElement.current = document.activeElement as HTMLElement;
      document.body.style.overflow = 'hidden';
      // Focus first platform button after render
      setTimeout(() => {
        const firstPlatformButton = platformButtonRefs.current[0];
        firstPlatformButton?.focus();
        setSelectedIndex(0);
      }, 0);
    } else {
      document.body.style.overflow = '';
      previousActiveElement.current?.focus();
    }

    return () => {
      document.body.style.overflow = '';
    };
  }, [isOpen]);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (!isOpen) return;

      if (e.key === 'Escape') {
        e.preventDefault();
        onClose();
        return;
      }

      const elements = focusableElements();
      if (elements.length === 0) return;
      const currentIndex = elements.findIndex((el) => el === document.activeElement);

      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
        e.preventDefault();
        const nextIndex = (currentIndex + 1) % elements.length;
        setSelectedIndex((prev) => (prev + 1) % platforms.length);
        focusElement(nextIndex);
        return;
      }

      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        e.preventDefault();
        const prevIndex = (currentIndex - 1 + elements.length) % elements.length;
        setSelectedIndex((prev) => (prev - 1 + platforms.length) % platforms.length);
        focusElement(prevIndex);
        return;
      }

      if (e.key === 'Enter' || e.key === ' ') {
        // Only trigger connect if a platform button is focused
        const activeElement = document.activeElement;
        const isPlatformButton = platformButtonRefs.current.some((btn) => btn === activeElement);
        if (isPlatformButton && !loading) {
          e.preventDefault();
          const platform = platforms[selectedIndex];
          onConnect(platform.id);
        }
        return;
      }

      // Trap focus within modal - cycle through close button and platform buttons
      if (e.key === 'Tab') {
        if (elements.length <= 1) return;

        const currentIndex = elements.findIndex((el) => el === document.activeElement);
        if (currentIndex === -1) return;

        if (e.shiftKey) {
          // Shift+Tab: move to previous element
          e.preventDefault();
          const prevIndex = currentIndex === 0 ? elements.length - 1 : currentIndex - 1;
          elements[prevIndex].focus();
        } else {
          // Tab: move to next element
          e.preventDefault();
          const nextIndex = currentIndex === elements.length - 1 ? 0 : currentIndex + 1;
          elements[nextIndex].focus();
        }
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, selectedIndex, loading, onClose, onConnect, focusableElements, focusElement]);

  if (!isOpen) return null;

  return (
    <div
      className="connect-modal-overlay"
      role="dialog"
      aria-modal="true"
      aria-labelledby="modal-title"
    >
      <div
        ref={modalRef}
        tabIndex={-1}
        className="connect-modal"
      >
        <div className="connect-modal-header">
          <h2 id="modal-title" className="connect-modal-title">
            Connect Social Account
          </h2>
          <button
            ref={closeButtonRef}
            type="button"
            onClick={onClose}
            className="connect-modal-close"
            aria-label="Close modal"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {error && (
          <div className="connect-modal-error" role="alert">
            {error}
          </div>
        )}

        <p className="connect-modal-description">
          Choose a platform to connect. You'll be redirected to authorize the connection.
        </p>

        <div className="platform-grid" role="group" aria-label="Platform selection">
          {platforms.map((platform, index) => (
            <button
              ref={(el) => { platformButtonRefs.current[index] = el; }}
              key={platform.id}
              type="button"
              onClick={() => !loading && onConnect(platform.id)}
              disabled={loading}
              className={`platform-button ${selectedIndex === index ? 'selected' : ''}`}
              aria-selected={selectedIndex === index}
              aria-label={`Connect ${platform.label}`}
              style={{ '--platform-color': platform.color } as React.CSSProperties}
            >
              <div
                className="platform-icon"
                aria-hidden="true"
                style={{ background: platform.color } as React.CSSProperties}
              >
                {platform.icon}
              </div>
              <h3 className="platform-name">{platform.label}</h3>
              <p className="platform-description">{platform.description}</p>
              <div className="platform-selected-indicator" aria-hidden="true" />
            </button>
          ))}
        </div>

        {loading && (
          <div className="connect-modal-loading" role="status" aria-live="polite">
            <div className="spinner" style={{ width: 24, height: 24, borderWidth: 3 }} aria-hidden="true" />
            <span>Connecting…</span>
          </div>
        )}
      </div>
    </div>
  );
}