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

      <label htmlFor="feedback" className="review-feedback-label">
        Feedback {isRequired ? <span className="review-feedback-required">(required for {statusLabel})</span> : <span className="review-feedback-optional">(optional)</span>}
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
        className={`review-feedback-textarea ${isError ? 'review-feedback-textarea--error' : ''} ${disabled ? 'review-feedback-textarea--disabled' : ''}`}
        placeholder={isRequired ? `Explain why you are ${statusLabel.toLowerCase()}ing this clip…` : 'Optional feedback…'}
        aria-describedby="feedback-count feedback-error"
        aria-invalid={isError}
        aria-required={isRequired}
      />

      <div className="review-feedback-meta">
        <span id="feedback-error" className={`review-feedback-error ${isError ? 'review-feedback-error--active' : ''} ${isWarning ? 'review-feedback-error--warning' : ''}`}>
          {isOverLimit ? 'Exceeds 1000 characters' : isRequired && charCount > 0 && charCount < minLength ? `Minimum ${minLength} characters required` : ''}
        </span>
        <span id="feedback-count" className="review-feedback-count">
          {charCount}/{maxLength}
        </span>
      </div>

      <button
        type="button"
        onClick={handleSubmit}
        disabled={submitDisabled}
        className="btn btn-primary review-feedback-submit"
      >
        {loading ? 'Submitting…' : 'Submit Feedback'}
      </button>
    </div>
  );
};