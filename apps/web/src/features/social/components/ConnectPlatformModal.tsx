import { useState, useEffect, useRef, useCallback } from 'react';
import type { Platform } from '../types';

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
    color: 'bg-red-500',
    icon: (
      <svg className="w-8 h-8" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M23.498 6.186a3.167 3.167 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.17 3.17 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.166 3.166 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.167 3.167 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z" />
      </svg>
    ),
  },
  {
    id: 'instagram',
    label: 'Instagram',
    description: 'Publish reels and posts to Instagram',
    color: 'bg-gradient-to-br from-purple-500 via-pink-500 to-orange-500',
    icon: (
      <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <rect x="2" y="2" width="20" height="20" rx="5" ry="5" stroke="currentColor" strokeWidth="2" />
        <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" stroke="currentColor" strokeWidth="2" />
        <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" stroke="currentColor" strokeWidth="2" />
      </svg>
    ),
  },
  {
    id: 'tiktok',
    label: 'TikTok',
    description: 'Publish videos to your TikTok account',
    color: 'bg-black',
    icon: (
      <svg className="w-8 h-8" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
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
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="modal-title"
    >
      <div
        ref={modalRef}
        tabIndex={-1}
        className="bg-white dark:bg-gray-800 rounded-xl p-6 w-full max-w-2xl shadow-xl max-h-[90vh] overflow-y-auto"
      >
        <div className="flex items-center justify-between mb-6">
          <h2 id="modal-title" className="text-xl font-semibold text-gray-900 dark:text-white">
            Connect Social Account
          </h2>
          <button
            ref={closeButtonRef}
            type="button"
            onClick={onClose}
            className="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800"
            aria-label="Close modal"
          >
            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {error && (
          <div className="mb-4 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
            <p className="text-sm text-red-700 dark:text-red-300" role="alert">
              {error}
            </p>
          </div>
        )}

        <p className="text-gray-600 dark:text-gray-400 mb-4">
          Choose a platform to connect. You'll be redirected to authorize the connection.
        </p>

        <div className="grid grid-cols-3 gap-4" role="group" aria-label="Platform selection">
          {platforms.map((platform, index) => (
            <button
              ref={(el) => { platformButtonRefs.current[index] = el; }}
              key={platform.id}
              type="button"
              onClick={() => !loading && onConnect(platform.id)}
              disabled={loading}
              className={`relative group p-4 rounded-xl border-2 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 ${
                selectedIndex === index
                  ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                  : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600'
              } ${loading ? 'opacity-50 cursor-not-allowed' : ''}`}
              aria-selected={selectedIndex === index}
              aria-label={`Connect ${platform.label}`}
            >
              <div
                className={`w-14 h-14 rounded-xl flex items-center justify-center mx-auto mb-3 ${platform.color}`}
                aria-hidden="true"
              >
                {platform.icon}
              </div>
              <h3 className="font-medium text-gray-900 dark:text-white text-center mb-1">
                {platform.label}
              </h3>
              <p className="text-xs text-gray-500 dark:text-gray-400 text-center">
                {platform.description}
              </p>
              {selectedIndex === index && (
                <div className="absolute bottom-0 left-1/2 -translate-x-1/2 translate-y-1/2 w-3 h-3 bg-blue-500 rounded-full" aria-hidden="true" />
              )}
            </button>
          ))}
        </div>

        {loading && (
          <div className="mt-6 text-center" role="status" aria-live="polite">
            <div className="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400">
              <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
              <span>Connecting...</span>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}