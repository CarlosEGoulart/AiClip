import { useState } from 'react';
import { useAuth } from '../hooks';

export function RegisterForm() {
  const { register, state, validationErrors, clearErrors } = useAuth();
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');

  const isLoading = state === 'registering';

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
    <form onSubmit={handleSubmit} className="auth-form">
      <h2>Create Account</h2>

      <div className="form-group">
        <label htmlFor="name">Name</label>
        <input
          id="name"
          type="text"
          value={name}
          onChange={(e) => {
            setName(e.target.value);
            clearErrors();
          }}
          disabled={isLoading}
          autoComplete="name"
          required
          aria-invalid={!!validationErrors.name}
          aria-describedby={validationErrors.name ? 'name-error' : undefined}
        />
        {validationErrors.name && (
          <span id="name-error" className="field-error" role="alert">
            {validationErrors.name[0]}
          </span>
        )}
      </div>

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
          autoComplete="new-password"
          required
          aria-invalid={!!validationErrors.password}
          aria-describedby={validationErrors.password ? 'password-error' : undefined}
        />
        {validationErrors.password && (
          <span id="password-error" className="field-error" role="alert">
            {validationErrors.password[0]}
          </span>
        )}
      </div>

      <div className="form-group">
        <label htmlFor="password_confirmation">Confirm Password</label>
        <input
          id="password_confirmation"
          type="password"
          value={passwordConfirmation}
          onChange={(e) => setPasswordConfirmation(e.target.value)}
          disabled={isLoading}
          autoComplete="new-password"
          required
        />
      </div>

      <button type="submit" disabled={isLoading}>
        {isLoading ? 'Creating account...' : 'Create Account'}
      </button>
    </form>
  );
}
