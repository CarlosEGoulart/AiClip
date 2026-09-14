export interface Project {
  id: number;
  name: string;
  description: string | null;
  created_at: string;
  updated_at: string;
}

export interface ProjectsResponse {
  data: Project[];
}

export interface ProjectResponse {
  data: Project;
}

export interface ValidationErrors {
  [key: string]: string[];
}

export type ProjectErrorType =
  | 'validation'
  | 'unauthorized'
  | 'throttle'
  | 'csrf'
  | 'network'
  | 'server';

export interface ProjectError {
  type: ProjectErrorType;
  errors?: Record<string, string[]>;
  message?: string;
}
