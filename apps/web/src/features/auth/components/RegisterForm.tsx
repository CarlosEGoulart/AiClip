import { useState, useEffect, useRef } from 'react';
import { useAuth } from '../hooks';

export function RegisterForm() {
  const { register, state, validationErrors, errorMessage, clearErrors } = useAuth();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const firstInvalidRef = useRef<HTMLInputElement>(null);

  const isLoading = state === 'registering';

  const hasValidationErrors = Object.keys(validationErrors).length > 0;

  // Focus first invalid field when validation errors appear
  useEffect(() => {
    if (hasValidationErrors && firstInvalidRef.current) {
      firstInvalidRef.current.focus();
    }
  }, [hasValidationErrors]);

  // Determine which field is the first invalid one for focus ref
  const nameInvalid = !!validationErrors.name;
  const emailInvalid = !!validationErrors.email;
  const passwordInvalid = !!validationErrors.password;
  const passwordConfirmationInvalid = !!validationErrors.password_confirmation;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await register({
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
  };

  return (
    <form
      onSubmit={handleSubmit}
      className="auth-form"
      noValidate
      aria-busy={isLoading || undefined}
    >
      <h2>Create Account</h2>

      {isLoading && (
        <div role="status" aria-label="authentication progress">
          Creating account...
        </div>
      )}

      {errorMessage && (
        <div className="error-message" role="alert">
          {errorMessage}
        </div>
      )}

      <div className="form-group">
        <label htmlFor="register-name">Name</label>
        <input
          ref={nameInvalid ? firstInvalidRef : undefined}
          id="register-name"
          name="name"
          type="text"
          value={name}
          onChange={(e) => {
            setName(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="name"
          required
          aria-invalid={nameInvalid}
          aria-describedby={nameInvalid ? 'register-name-error' : undefined}
        />
        {nameInvalid && (
          <span id="register-name-error" className="field-error" role="alert">
            {validationErrors.name?.[0]}
          </span>
        )}
      </div>

      <div className="form-group">
        <label htmlFor="register-email">Email</label>
        <input
          ref={emailInvalid && !nameInvalid ? firstInvalidRef : undefined}
          id="register-email"
          name="email"
          type="email"
          value={email}
          onChange={(e) => {
            setEmail(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="email"
          required
          spellCheck="false"
          aria-invalid={emailInvalid}
          aria-describedby={emailInvalid ? 'register-email-error' : undefined}
        />
        {emailInvalid && (
          <span id="register-email-error" className="field-error" role="alert">
            {validationErrors.email?.[0]}
          </span>
        )}
      </div>

      <div className="form-group">
        <label htmlFor="register-password">Password</label>
        <input
          ref={passwordInvalid && !nameInvalid && !emailInvalid ? firstInvalidRef : undefined}
          id="register-password"
          name="password"
          type="password"
          value={password}
          onChange={(e) => {
            setPassword(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="new-password"
          required
          aria-invalid={passwordInvalid}
          aria-describedby={passwordInvalid ? 'register-password-error' : undefined}
        />
        {passwordInvalid && (
          <span id="register-password-error" className="field-error" role="alert">
            {validationErrors.password?.[0]}
          </span>
        )}
      </div>

      <div className="form-group">
        <label htmlFor="register-password-confirmation">Confirm Password</label>
        <input
          ref={passwordConfirmationInvalid && !nameInvalid && !emailInvalid && !passwordInvalid ? firstInvalidRef : undefined}
          id="register-password-confirmation"
          name="password_confirmation"
          type="password"
          value={passwordConfirmation}
          onChange={(e) => {
            setPasswordConfirmation(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="new-password"
          required
          aria-invalid={passwordConfirmationInvalid}
          aria-describedby={passwordConfirmationInvalid ? 'register-password-confirmation-error' : undefined}
        />
        {passwordConfirmationInvalid && (
          <span id="register-password-confirmation-error" className="field-error" role="alert">
            {validationErrors.password_confirmation?.[0]}
          </span>
        )}
      </div>

      <button type="submit" disabled={isLoading}>
        {isLoading ? 'Creating account...' : 'Create Account'}
      </button>
    </form>
  );
}
