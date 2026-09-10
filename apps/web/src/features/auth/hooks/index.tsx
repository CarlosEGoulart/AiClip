import { useState, useEffect, useCallback, createContext, useContext, type ReactNode } from 'react';
import type { User, AuthState, ValidationErrors } from '../types';
import * as authApi from '../api';

interface AuthContextType {
  user: User | null;
  state: AuthState;
  validationErrors: ValidationErrors;
  register: (data: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => Promise<void>;
  login: (data: { email: string; password: string }) => Promise<void>;
  logout: () => Promise<void>;
  clearErrors: () => void;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [state, setState] = useState<AuthState>('checking-session');
  const [validationErrors, setValidationErrors] = useState<ValidationErrors>({});

  useEffect(() => {
    authApi.getMe()
      .then((response) => {
        setUser(response.user);
        setState('authenticated');
      })
      .catch(() => {
        setUser(null);
        setState('guest');
      });
  }, []);

  const clearErrors = useCallback(() => {
    setValidationErrors({});
  }, []);

  const handleRegister = useCallback(async (data: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => {
    setState('registering');
    setValidationErrors({});
    try {
      const response = await authApi.register(data);
      setUser(response.user);
      setState('authenticated');
    } catch (error: any) {
      if (error.type === 'validation') {
        setValidationErrors(error.errors);
        setState('validation-error');
      } else if (error.type === 'network') {
        setState('network-error');
      } else {
        setState('guest');
      }
    }
  }, []);

  const handleLogin = useCallback(async (data: { email: string; password: string }) => {
    setState('logging-in');
    setValidationErrors({});
    try {
      const response = await authApi.login(data);
      setUser(response.user);
      setState('authenticated');
    } catch (error: any) {
      if (error.type === 'validation') {
        setValidationErrors(error.errors);
        setState('validation-error');
      } else if (error.type === 'credential') {
        setState('credential-error');
      } else if (error.type === 'throttle') {
        setState('credential-error');
      } else if (error.type === 'network') {
        setState('network-error');
      } else {
        setState('guest');
      }
    }
  }, []);

  const handleLogout = useCallback(async () => {
    setState('logging-out');
    try {
      await authApi.logout();
    } catch {
      // Logout should succeed even if server is unreachable
    }
    setUser(null);
    setState('guest');
    setValidationErrors({});
  }, []);

  return (
    <AuthContext.Provider
      value={{
        user,
        state,
        validationErrors,
        register: handleRegister,
        login: handleLogin,
        logout: handleLogout,
        clearErrors,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
