import { useState } from 'react';
import { useAuth } from '../hooks';

export function LoginForm() {
  const { login, state, validationErrors, clearErrors } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');

  const isLoading = state === 'logging-in';
  const isCredentialError = state === 'credential-error';

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    await login({ email, password });
  };

  return (
    <form onSubmit={handleSubmit} className="auth-form">
      <h2>Sign In</h2>

      {isCredentialError && (
        <div className="error-message" role="alert">
          Invalid email or password.
        </div>
      )}

      <div className="form-group">
        <label htmlFor="email">Email</label>
        <input
          id="email"
          type="email"
          value={email}
          onChange={(e) => {
            setEmail(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="email"
          required
          aria-invalid={!!validationErrors.email}
          aria-describedby={validationErrors.email ? 'email-error' : undefined}
        />
        {validationErrors.email && (
          <span id="email-error" className="field-error" role="alert">
            {validationErrors.email[0]}
          </span>
        )}
      </div>

      <div className="form-group">
        <label htmlFor="password">Password</label>
        <input
          id="password"
          type="password"
          value={password}
          onChange={(e) => {
            setPassword(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="current-password"
          required
        />
      </div>

      <button type="submit" disabled={isLoading}>
        {isLoading ? 'Signing in...' : 'Sign In'}
      </button>
    </form>
  );
}
