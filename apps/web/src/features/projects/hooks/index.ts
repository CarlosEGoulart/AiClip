import { useState, useCallback } from 'react';
import type { Project, ValidationErrors, ProjectError } from '../types';
import * as projectApi from '../api';

export interface UseProjectsReturn {
  projects: Project[];
  loading: boolean;
  error: string | null;
  validationErrors: ValidationErrors;
  fetchProjects: () => Promise<void>;
  createProject: (name: string) => Promise<boolean>;
  deleteProject: (id: number) => Promise<boolean>;
  clearErrors: () => void;
}

function classifyError(error: unknown): { message: string; validationErrors?: ValidationErrors } {
  const projectError = error as ProjectError;
  if (!projectError?.type) {
    return { message: 'An unexpected error occurred' };
  }

  switch (projectError.type) {
    case 'validation':
      return { message: 'Validation failed', validationErrors: projectError.errors || {} };
    case 'throttle':
      return { message: 'Too many attempts. Please try again later.' };
    case 'unauthorized':
      return { message: 'Your session has expired' };
    case 'csrf':
      return { message: 'Session expired. Please refresh the page.' };
    case 'network':
      return { message: 'Network error. Please check your connection.' };
    case 'server':
      return { message: projectError.message || 'Server error' };
    default:
      return { message: 'An unexpected error occurred' };
  }
}

export function useProjects(): UseProjectsReturn {
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [validationErrors, setValidationErrors] = useState<ValidationErrors>({});

  const fetchProjects = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const response = await projectApi.getProjects();
      setProjects(response.data);
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
    } finally {
      setLoading(false);
    }
  }, []);

  const createProject = useCallback(async (name: string): Promise<boolean> => {
    setError(null);
    setValidationErrors({});
    try {
      const response = await projectApi.createProject({ name });
      setProjects((prev) => [response.data, ...prev]);
      return true;
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
      if (classified.validationErrors) {
        setValidationErrors(classified.validationErrors);
      }
      return false;
    }
  }, []);

  const deleteProject = useCallback(async (id: number): Promise<boolean> => {
    setError(null);
    try {
      await projectApi.deleteProject(id);
      setProjects((prev) => prev.filter((p) => p.id !== id));
      return true;
    } catch (err: unknown) {
      const classified = classifyError(err);
      setError(classified.message);
      return false;
    }
  }, []);

  const clearErrors = useCallback(() => {
    setError(null);
    setValidationErrors({});
  }, []);

  return {
    projects,
    loading,
    error,
    validationErrors,
    fetchProjects,
    createProject,
    deleteProject,
    clearErrors,
  };
}
