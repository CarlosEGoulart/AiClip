import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../hooks', () => ({
  useAuth: vi.fn(),
}));

vi.mock('../../media', () => ({
  ProjectMediaSection: () => null,
}));

vi.mock('../../projects', () => ({
  useProjects: vi.fn(),
  CreateProjectForm: () => (
    <div data-testid="create-project-form">
      <h2>Create New Project</h2>
      <button>Create Project</button>
    </div>
  ),
  ProjectList: () => (
    <div data-testid="project-list">No projects yet. Create your first project above.</div>
  ),
}));

import { useAuth } from '../hooks';
import { useProjects } from '../../projects';
import { AuthenticatedShell } from './AuthenticatedShell';

const mockUseAuth = vi.mocked(useAuth);
const mockUseProjects = vi.mocked(useProjects);

function renderShell(overrides: {
  projects?: unknown[];
  loading?: boolean;
  projectError?: string | null;
  validationErrors?: Record<string, string[]>;
} = {}) {
  mockUseAuth.mockReturnValue({
    state: 'authenticated',
    user: { name: 'Test User', email: 'test@example.com' },
  } as any);

  mockUseProjects.mockReturnValue({
    projects: overrides.projects ?? [],
    loading: overrides.loading ?? false,
    error: overrides.projectError ?? null,
    validationErrors: overrides.validationErrors ?? {},
    fetchProjects: vi.fn(),
    createProject: vi.fn().mockResolvedValue(true),
    deleteProject: vi.fn().mockResolvedValue(true),
  } as any);

  return render(<AuthenticatedShell />);
}

afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});

describe('AuthenticatedShell (dashboard)', () => {
  it('renders the dashboard hero headline (h1 on first screen)', () => {
    renderShell();

    expect(
      screen.getByRole('heading', { name: 'Turn long video into short clips' }),
    ).toBeInTheDocument();
  });

  it('renders the moving picture (hero image)', () => {
    renderShell();

    const img = screen.getByAltText(/AiClip workspace/);
    expect(img).toBeInTheDocument();
    expect(img).toHaveClass('moving-picture');
  });

  it('renders the create-project form as the primary action', () => {
    renderShell();

    expect(screen.getByTestId('create-project-form')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create Project' })).toBeInTheDocument();
  });

  it('shows empty projects state when no projects exist', () => {
    renderShell();

    expect(screen.getByTestId('project-list')).toBeInTheDocument();
    expect(screen.getByText(/No projects yet/)).toBeInTheDocument();
  });

  it('the primary button touch target is at least 44px', () => {
    renderShell();

    const button = screen.getByRole('button', { name: 'Create Project' });
    const box = button.getBoundingClientRect();
    expect(box.height).toBeGreaterThanOrEqual(44);
    expect(box.width).toBeGreaterThanOrEqual(44);
  });
});
