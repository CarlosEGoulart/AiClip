import { useState, useEffect, useRef } from 'react';
import { useAuth } from '../hooks';

export function LoginForm() {
  const { login, state, validationErrors, errorMessage, clearErrors } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const firstInvalidRef = useRef<HTMLInputElement>(null);

  const isLoading = state === 'logging-in';

  const hasValidationErrors = Object.keys(validationErrors).length > 0;

  // Focus first invalid field when validation errors appear
  useEffect(() => {
    if (hasValidationErrors && firstInvalidRef.current) {
      firstInvalidRef.current.focus();
    }
  }, [hasValidationErrors]);

  // Determine which field is the first invalid one for focus ref
  const emailInvalid = !!validationErrors.email;
  const passwordInvalid = !!validationErrors.password;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await login({ email, password });
  };

  return (
    <form
      onSubmit={handleSubmit}
      className="auth-form"
      noValidate
      aria-busy={isLoading || undefined}
    >
      <h2>Sign In</h2>

      {isLoading && (
        <div role="status" aria-label="authentication progress">
          Signing in...
        </div>
      )}

      {errorMessage && (
        <div className="error-message" role="alert">
          {errorMessage}
        </div>
      )}

      <div className="form-group">
        <label htmlFor="login-email">Email</label>
        <input
          ref={emailInvalid ? firstInvalidRef : undefined}
          id="login-email"
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
          aria-describedby={emailInvalid ? 'login-email-error' : undefined}
        />
        {emailInvalid && (
          <span id="login-email-error" className="field-error" role="alert">
            {validationErrors.email?.[0]}
          </span>
        )}
      </div>

      <div className="form-group">
        <label htmlFor="login-password">Password</label>
        <input
          ref={passwordInvalid && !emailInvalid ? firstInvalidRef : undefined}
          id="login-password"
          name="password"
          type="password"
          value={password}
          onChange={(e) => {
            setPassword(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="current-password"
          required
          aria-invalid={passwordInvalid}
          aria-describedby={passwordInvalid ? 'login-password-error' : undefined}
        />
        {passwordInvalid && (
          <span id="login-password-error" className="field-error" role="alert">
            {validationErrors.password?.[0]}
          </span>
        )}
      </div>

      <button type="submit" disabled={isLoading}>
        {isLoading ? 'Signing in...' : 'Sign In'}
      </button>
    </form>
  );
}
