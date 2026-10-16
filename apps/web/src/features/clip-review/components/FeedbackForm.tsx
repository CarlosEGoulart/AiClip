import React, { useState, useEffect, useRef, useCallback } from 'react';
import type { ClipReviewStatus } from '../types';

interface FeedbackFormProps {
  clipReviewId: number;
  status: ClipReviewStatus;
  initialFeedback?: string;
  onSubmit: (feedback: string) => void;
  disabled?: boolean;
  onUnsavedChange?: (hasUnsaved: boolean) => void;
}

export const FeedbackForm: React.FC<FeedbackFormProps> = ({
  clipReviewId,
  status,
  initialFeedback = '',
  onSubmit,
  disabled = false,
  onUnsavedChange,
}) => {
  const [feedback, setFeedback] = useState(initialFeedback);
  const [loading, setLoading] = useState(false);
  const [announcement, setAnnouncement] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const isSubmittedRef = useRef(false);
  const initialFeedbackRef = useRef(initialFeedback);

  useEffect(() => {
    initialFeedbackRef.current = initialFeedback;
  }, [initialFeedback]);

  const draftKey = `clip-review-feedback-${clipReviewId}`;
  const isRequired = status === 'rejected' || status === 'needs_changes';
  const minLength = isRequired ? 10 : 0;
  const maxLength = 1000;

  const hasUnsavedChanges = feedback !== initialFeedbackRef.current && !isSubmittedRef.current;

  useEffect(() => {
    onUnsavedChange?.(hasUnsavedChanges);
  }, [hasUnsavedChanges, onUnsavedChange]);

  useEffect(() => {
    const draft = localStorage.getItem(draftKey);
    if (draft && !isSubmittedRef.current) {
      setFeedback(draft);
    }
  }, [draftKey]);

  useEffect(() => {
    if (!isSubmittedRef.current) {
      localStorage.setItem(draftKey, feedback);
    }
  }, [feedback, draftKey]);

  useEffect(() => {
    const textarea = textareaRef.current;
    if (textarea) {
      textarea.style.height = 'auto';
      textarea.style.height = `${textarea.scrollHeight}px`;
    }
  }, [feedback]);

  const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const value = e.target.value;
    if (value.length <= maxLength) {
      setFeedback(value);
    }
  };

  const focusFirstError = useCallback(() => {
    const errorElement = document.getElementById('feedback-error');
    if (errorElement && errorElement.textContent) {
      errorElement.focus();
      return;
    }
    textareaRef.current?.focus();
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (isRequired && feedback.trim().length < minLength) {
      setAnnouncement(`Feedback must be at least ${minLength} characters`);
      focusFirstError();
      return;
    }

    if (feedback.length > maxLength) {
      setAnnouncement('Feedback too long');
      focusFirstError();
      return;
    }

    setLoading(true);
    try {
      isSubmittedRef.current = true;
      localStorage.removeItem(draftKey);
      await onSubmit(feedback.trim());
      setAnnouncement('Feedback submitted successfully');
    } catch {
      setAnnouncement('Failed to submit feedback');
    } finally {
      setLoading(false);
    }
  };

  const charCount = feedback.length;
  const isOverLimit = charCount > maxLength;
  const isWarning = charCount > 800 && charCount <= maxLength;
  const isError = isOverLimit || (isRequired && charCount > 0 && charCount < minLength);
  const submitDisabled = disabled || loading || (isRequired && charCount < minLength) || isOverLimit;

  const statusLabel = status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ');

  return (
    <div className="space-y-4" role="group" aria-label="Feedback form">
      <div aria-live="polite" aria-atomic="true" className="sr-only">
        {announcement}
      </div>

      <label htmlFor="feedback" className="block text-sm font-medium text-gray-900 dark:text-white">
        Feedback {isRequired ? <span className="text-gray-500 dark:text-gray-400 font-normal">(required for {statusLabel})</span> : <span className="text-gray-500 dark:text-gray-400 font-normal">(optional)</span>}
      </label>

      <textarea
        ref={textareaRef}
        id="feedback"
        name="feedback"
        value={feedback}
        onChange={handleChange}
        onSubmit={handleSubmit}
        disabled={disabled || loading}
        rows={4}
        autoComplete="off"
        className={`
          w-full px-4 py-3 border rounded-lg
          focus:outline-none focus:ring-2 transition-colors resize-none
          bg-white dark:bg-gray-800
          text-gray-900 dark:text-white
          placeholder:text-gray-400 dark:placeholder:text-gray-500
          ${isError ? 'border-red-500 focus:ring-red-500/30' : isWarning ? 'border-yellow-500 focus:ring-yellow-500/30' : 'border-gray-300 dark:border-gray-600 focus:ring-blue-500/30'}
          ${disabled ? 'opacity-50 cursor-not-allowed' : ''}
        `}
        placeholder={isRequired ? `Explain why you are ${statusLabel.toLowerCase()}ing this clip…` : 'Optional feedback…'}
        aria-describedby="feedback-count feedback-error"
        aria-invalid={isError}
        aria-required={isRequired}
      />

      <div className="flex items-center justify-between text-sm">
        <span id="feedback-error" className={isError ? 'text-red-600 dark:text-red-400' : isWarning ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-500 dark:text-gray-400'}>
          {isOverLimit ? 'Exceeds 1000 characters' : isRequired && charCount > 0 && charCount < minLength ? `Minimum ${minLength} characters required` : ''}
        </span>
        <span id="feedback-count" className={`tabular-nums ${isOverLimit ? 'text-red-600 dark:text-red-400' : isWarning ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-500 dark:text-gray-400'}`}>
          {charCount}/{maxLength}
        </span>
      </div>

      <button
        type="button"
        onClick={handleSubmit}
        disabled={submitDisabled}
        className="w-full py-2.5 px-4 bg-blue-600 text-white rounded-lg font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-white dark:focus:ring-offset-gray-900 disabled:opacity-50 disabled:cursor-not-allowed transition-colors touch-action-manipulation"
      >
        {loading ? 'Submitting…' : 'Submit Feedback'}
      </button>
    </div>
  );
};