import React, { useState, useRef, useEffect } from 'react';
import type { ClipReviewStatus } from '../types';

interface ReviewActionsProps {
  currentStatus: ClipReviewStatus;
  onStatusChange: (status: Exclude<ClipReviewStatus, 'pending'>) => void;
  disabled?: boolean;
}

const statusInfo: Record<Exclude<ClipReviewStatus, 'pending'>, { label: string; color: string; bgColor: string }> = {
  approved: { label: 'Approve', color: 'text-green-700', bgColor: 'bg-green-100 dark:bg-green-900/30' },
  rejected: { label: 'Reject', color: 'text-red-700', bgColor: 'bg-red-100 dark:bg-red-900/30' },
  needs_changes: { label: 'Needs Changes', color: 'text-orange-700', bgColor: 'bg-orange-100 dark:bg-orange-900/30' },
};

const statusIcons: Record<Exclude<ClipReviewStatus, 'pending'>, React.ReactNode> = {
  approved: (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
    </svg>
  ),
  rejected: (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
  ),
  needs_changes: (
    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
  ),
};

const actions: Array<{ status: Exclude<ClipReviewStatus, 'pending'>; label: string }> = [
  { status: 'approved', label: 'Approve' },
  { status: 'rejected', label: 'Reject' },
  { status: 'needs_changes', label: 'Needs Changes' },
];

export const ReviewActions: React.FC<ReviewActionsProps> = ({
  currentStatus,
  onStatusChange,
  disabled = false,
}) => {
  const [openDialog, setOpenDialog] = useState<Exclude<ClipReviewStatus, 'pending'> | null>(null);
  const [loading, setLoading] = useState(false);
  const [announcement, setAnnouncement] = useState('');
  const dialogRef = useRef<HTMLDialogElement>(null);
  const previousFocusRef = useRef<HTMLElement | null>(null);

  const handleOpenDialog = (status: Exclude<ClipReviewStatus, 'pending'>) => {
    if (disabled || loading) return;
    previousFocusRef.current = document.activeElement as HTMLElement;
    setOpenDialog(status);
  };

  const handleCloseDialog = () => {
    setOpenDialog(null);
    previousFocusRef.current?.focus();
  };

  const handleConfirm = async (status: Exclude<ClipReviewStatus, 'pending'>) => {
    setLoading(true);
    try {
      await onStatusChange(status);
      setAnnouncement(`Clip ${status}`);
    } catch {
      setAnnouncement(`Failed to ${status} clip`);
    } finally {
      setLoading(false);
      setOpenDialog(null);
      previousFocusRef.current?.focus();
    }
  };

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog || !openDialog) return;

    const focusableElements = dialog.querySelectorAll<HTMLElement>(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    const firstElement = focusableElements[0];
    const lastElement = focusableElements[focusableElements.length - 1];

    const handleTab = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return;

      if (e.shiftKey) {
        if (document.activeElement === firstElement) {
          e.preventDefault();
          lastElement?.focus();
        }
      } else {
        if (document.activeElement === lastElement) {
          e.preventDefault();
          firstElement?.focus();
        }
      }
    };

    dialog.addEventListener('keydown', handleTab);
    firstElement?.focus();

    return () => {
      dialog.removeEventListener('keydown', handleTab);
    };
  }, [openDialog]);

  useEffect(() => {
    const handleEscape = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && openDialog) {
        handleCloseDialog();
      }
    };

    document.addEventListener('keydown', handleEscape);
    return () => document.removeEventListener('keydown', handleEscape);
  }, [openDialog]);

  return (
    <div className="space-y-3" role="group" aria-label="Review actions">
      <div aria-live="polite" aria-atomic="true" className="sr-only">
        {announcement}
      </div>

      {actions.map((action) => {
        const info = statusInfo[action.status];
        const isActive = currentStatus === action.status;

        return (
          <button
            key={action.status}
            onClick={() => handleOpenDialog(action.status)}
            disabled={disabled || loading || isActive}
            className={`
              w-full py-3 px-4 rounded-lg font-medium transition-colors
              focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900
              disabled:opacity-50 disabled:cursor-not-allowed
              flex items-center justify-center gap-2
              ${isActive ? 'opacity-60 cursor-default' : 'hover:bg-gray-50 dark:hover:bg-gray-800/50'}
              ${info.bgColor} ${info.color}
            `}
            aria-label={`${action.label} clip`}
            aria-pressed={isActive}
            style={{ touchAction: 'manipulation' }}
          >
            {loading && openDialog === action.status ? (
              <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24" aria-hidden="true">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
            ) : (
              statusIcons[action.status]
            )}
            {action.label}
          </button>
        );
      })}

      {openDialog && (
        <dialog
          ref={dialogRef}
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="dialog-title"
          aria-describedby="dialog-description"
          open
          style={{ overscrollBehavior: 'contain' }}
        >
          <div className="bg-white dark:bg-gray-800 rounded-xl p-6 max-w-md w-full">
            <h2 id="dialog-title" className="text-lg font-semibold mb-2">
              Confirm {openDialog.charAt(0).toUpperCase() + openDialog.slice(1).replace('_', ' ')}
            </h2>
            <p id="dialog-description" className="text-gray-600 dark:text-gray-300 mb-4 leading-relaxed">
              Are you sure you want to mark this clip as <strong>{openDialog.replace('_', ' ')}</strong>?
              {openDialog !== 'approved' && (
                <span className="block mt-2 text-sm text-orange-700 dark:text-orange-400">
                  Feedback will be required (minimum 10 characters).
                </span>
              )}
            </p>
            <div className="flex justify-end gap-3">
              <button
                onClick={handleCloseDialog}
                disabled={loading}
                className="px-4 py-2 text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-gray-500 touch-action-manipulation"
              >
                Cancel
              </button>
              <button
                onClick={() => handleConfirm(openDialog)}
                disabled={loading}
                className={`
                  px-4 py-2 rounded-lg font-medium transition-colors
                  focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900
                  disabled:opacity-50
                  ${statusInfo[openDialog].bgColor} ${statusInfo[openDialog].color}
                  touch-action-manipulation
                `}
              >
                {loading ? 'Processing…' : `Confirm ${openDialog.charAt(0).toUpperCase() + openDialog.slice(1).replace('_', ' ')}`}
              </button>
            </div>
          </div>
        </dialog>
      )}
    </div>
  );
};