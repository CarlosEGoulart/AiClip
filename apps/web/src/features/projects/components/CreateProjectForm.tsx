import { useState, useEffect, useRef } from 'react';
import type { ValidationErrors } from '../types';

interface CreateProjectFormProps {
  onSubmit: (name: string) => Promise<boolean>;
  validationErrors: ValidationErrors;
  error: string | null;
  disabled?: boolean;
}

export function CreateProjectForm({ onSubmit, validationErrors, error, disabled }: CreateProjectFormProps) {
  const [name, setName] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const firstInvalidRef = useRef<HTMLInputElement>(null);

  const hasValidationErrors = Object.keys(validationErrors).length > 0;

  useEffect(() => {
    if (hasValidationErrors && firstInvalidRef.current) {
      firstInvalidRef.current.focus();
    }
  }, [hasValidationErrors]);

  const nameInvalid = !!validationErrors.name;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (isSubmitting || disabled) return;

    setIsSubmitting(true);
    const success = await onSubmit(name);
    if (success) {
      setName('');
    }
    setIsSubmitting(false);
  };

  const isLoading = isSubmitting;
  const isDisabled = isLoading || disabled;

  return (
    <form
      onSubmit={handleSubmit}
      className="project-form"
      noValidate
      aria-busy={isLoading || undefined}
    >
      <h3>Create New Project</h3>

      {error && (
        <div className="error-message" role="alert">
          {error}
        </div>
      )}

      <div className="form-group">
        <label htmlFor="project-name">Project Name</label>
        <input
          ref={nameInvalid ? firstInvalidRef : undefined}
          id="project-name"
          name="name"
          type="text"
          value={name}
          onChange={(e) => {
            setName(e.target.value);
          }}
          disabled={isDisabled}
          autoComplete="off"
          required
          aria-invalid={nameInvalid}
          aria-describedby={nameInvalid ? 'project-name-error' : undefined}
          placeholder="Enter project name"
        />
        {nameInvalid && (
          <span id="project-name-error" className="field-error" role="alert">
            {validationErrors.name?.[0]}
          </span>
        )}
      </div>

      <button type="submit" disabled={isDisabled}>
        {isLoading ? 'Creating...' : 'Create Project'}
      </button>
    </form>
  );
}
