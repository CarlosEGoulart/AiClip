import { useState, useEffect, useCallback, useRef, createContext, useContext, type ReactNode } from 'react';
import type { User, AuthState, ValidationErrors } from '../types';
import * as authApi from '../api';
import type { AuthError } from '../api';

export interface AuthContextType {
  user: User | null;
  state: AuthState;
  validationErrors: ValidationErrors;
  errorMessage: string | null;
  register: (data: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => Promise<void>;
  login: (data: { email: string; password: string }) => Promise<void>;
  logout: () => Promise<void>;
  retrySession: () => Promise<void>;
  clearErrors: () => void;
}

const AuthContext = createContext<AuthContextType | null>(null);

function classifyError(error: unknown): { state: AuthState; validationErrors?: ValidationErrors; message?: string } {
  const authError = error as AuthError;
  if (!authError?.type) {
    return { state: 'guest', message: 'An unexpected error occurred' };
  }

  switch (authError.type) {
    case 'validation':
      return { state: 'validation-error', validationErrors: authError.errors || {} };
    case 'throttle':
      return { state: 'throttle-error', message: 'Too many attempts. Please try again later.' };
    case 'unauthorized':
      return { state: 'session-expired', message: 'Your session has expired' };
    case 'csrf':
      return { state: 'csrf-error', message: 'Session expired. Please refresh the page.' };
    case 'network':
      return { state: 'network-error', message: 'Network error. Please check your connection.' };
    case 'server':
      return { state: 'server-error', message: authError.message || 'Server error' };
    default:
      return { state: 'guest', message: 'An unexpected error occurred' };
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [state, setState] = useState<AuthState>('checking-session');
  const [validationErrors, setValidationErrors] = useState<ValidationErrors>({});
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const generation = useRef(0);
  const mountedRef = useRef(true);
  const pendingRef = useRef(false);

  useEffect(() => {
    const gen = ++generation.current;
    let cancelled = false;
    mountedRef.current = true;

    authApi.getMe()
      .then((response) => {
        if (cancelled || gen !== generation.current) return;
        setUser(response.user);
        setState('authenticated');
      })
      .catch((error: unknown) => {
        if (cancelled || gen !== generation.current) return;
        const authError = error as AuthError;
        if (authError?.type === 'unauthorized') {
          setState('guest');
          setErrorMessage(null);
        } else {
          const classified = classifyError(error);
          setState(classified.state);
          setErrorMessage(classified.message ?? null);
        }
      });

    return () => {
      cancelled = true;
      mountedRef.current = false;
    };
  }, []);

  const retrySession = useCallback(async () => {
    if (!mountedRef.current) return;
    generation.current++;
    setState('checking-session');
    setErrorMessage(null);
    setUser(null);
    setValidationErrors({});
    try {
      const response = await authApi.getMe();
      if (!mountedRef.current) return;
      setUser(response.user);
      setState('authenticated');
    } catch (error: unknown) {
      if (!mountedRef.current) return;
      const authError = error as AuthError;
      if (authError?.type === 'unauthorized') {
        setState('guest');
        setErrorMessage(null);
      } else {
        const classified = classifyError(error);
        setState(classified.state);
        setErrorMessage(classified.message ?? null);
      }
    }
  }, []);

  const clearErrors = useCallback(() => {
    setValidationErrors({});
    setErrorMessage(null);
  }, []);

  const handleRegister = useCallback(async (data: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => {
    if (pendingRef.current || !mountedRef.current) return;
    pendingRef.current = true;
    generation.current++;
    setState('registering');
    setValidationErrors({});
    setErrorMessage(null);
    try {
      const response = await authApi.register(data);
      if (!mountedRef.current) return;
      setUser(response.user);
      setState('authenticated');
    } catch (error: unknown) {
      if (!mountedRef.current) return;
      const classified = classifyError(error);
      setUser(null);
      setValidationErrors(classified.validationErrors || {});
      setErrorMessage(classified.message ?? null);
      // For form submission errors, stay on the form (don't switch to error views)
      if (classified.state === 'network-error' || classified.state === 'server-error') {
        setState('guest');
      } else {
        setState(classified.state);
      }
    } finally {
      pendingRef.current = false;
    }
  }, []);

  const handleLogin = useCallback(async (data: { email: string; password: string }) => {
    if (pendingRef.current || !mountedRef.current) return;
    pendingRef.current = true;
    generation.current++;
    setState('logging-in');
    setValidationErrors({});
    setErrorMessage(null);
    try {
      const response = await authApi.login(data);
      if (!mountedRef.current) return;
      setUser(response.user);
      setState('authenticated');
    } catch (error: unknown) {
      if (!mountedRef.current) return;
      const classified = classifyError(error);
      setUser(null);
      setValidationErrors(classified.validationErrors || {});
      setErrorMessage(classified.message ?? null);
      // For form submission errors, stay on the form (don't switch to error views)
      if (classified.state === 'network-error' || classified.state === 'server-error') {
        setState('guest');
      } else {
        setState(classified.state);
      }
    } finally {
      pendingRef.current = false;
    }
  }, []);

  const handleLogout = useCallback(async () => {
    if (pendingRef.current || !mountedRef.current) return;
    pendingRef.current = true;
    setState('logging-out');
    setErrorMessage(null);
    try {
      await authApi.logout();
      if (!mountedRef.current) return;
      setUser(null);
      setState('guest');
      setValidationErrors({});
    } catch (error: unknown) {
      if (!mountedRef.current) return;
      const classified = classifyError(error);
      setErrorMessage(classified.message ?? 'Logout failed. The server session may still be active.');
      if (classified.state === 'session-expired') {
        setUser(null);
        setState('session-expired');
      } else {
        setState('authenticated');
      }
    } finally {
      pendingRef.current = false;
    }
  }, []);

  return (
    <AuthContext.Provider
      value={{
        user,
        state,
        validationErrors,
        errorMessage,
        register: handleRegister,
        login: handleLogin,
        logout: handleLogout,
        retrySession,
        clearErrors,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

// oxlint-disable-next-line react/only-export-components
export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
