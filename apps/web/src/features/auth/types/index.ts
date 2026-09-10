export interface User {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
}

export interface AuthResponse {
  user: User;
}

export interface ValidationErrors {
  [key: string]: string[];
}

export type AuthState =
  | 'checking-session'
  | 'guest'
  | 'registering'
  | 'logging-in'
  | 'authenticated'
  | 'logging-out'
  | 'validation-error'
  | 'credential-error'
  | 'network-error'
  | 'session-expired';
