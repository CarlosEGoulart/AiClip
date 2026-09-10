import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useProjects } from '../hooks';
import { CreateProjectForm } from '../components/CreateProjectForm';
import { ProjectCard } from '../components/ProjectCard';
import { ProjectList } from '../components/ProjectList';
import type { Project } from '../types';

// Mock the API module
vi.mock('../api', () => ({
  getProjects: vi.fn(),
  createProject: vi.fn(),
  deleteProject: vi.fn(),
}));

import * as projectApi from '../api';

afterEach(cleanup);

const mockProject: Project = {
  id: 1,
  name: 'Test Project',
  user_id: 1,
  created_at: '2026-09-10T12:00:00.000000Z',
  updated_at: '2026-09-10T12:00:00.000000Z',
};

const mockProjects: Project[] = [
  mockProject,
  { ...mockProject, id: 2, name: 'Second Project' },
];

describe('useProjects hook', () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it('starts with empty projects, no loading, no error', () => {
    function TestComponent() {
      const { projects, loading, error } = useProjects();
      return (
        <div>
          <span data-testid="count">{projects.length}</span>
          <span data-testid="loading">{String(loading)}</span>
          <span data-testid="error">{error ?? 'null'}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('count').textContent).toBe('0');
    expect(screen.getByTestId('loading').textContent).toBe('false');
    expect(screen.getByTestId('error').textContent).toBe('null');
  });

  it('fetches projects successfully', async () => {
    vi.mocked(projectApi.getProjects).mockResolvedValue({ data: mockProjects });

    function TestComponent() {
      const { projects, loading, fetchProjects } = useProjects();
      return (
        <div>
          <button onClick={fetchProjects}>Load</button>
          <span data-testid="count">{projects.length}</span>
          <span data-testid="loading">{String(loading)}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('count').textContent).toBe('0');

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('2');
    });
    expect(screen.getByTestId('loading').textContent).toBe('false');
  });

  it('handles fetch error', async () => {
    vi.mocked(projectApi.getProjects).mockRejectedValue({ type: 'network' });

    function TestComponent() {
      const { error, fetchProjects } = useProjects();
      return (
        <div>
          <button onClick={fetchProjects}>Load</button>
          <span data-testid="error">{error ?? 'null'}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('error').textContent).toBe('null');

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('error').textContent).toContain('Network');
    });
  });

  it('creates a project and adds it to the list', async () => {
    const newProject = { ...mockProject, id: 3, name: 'New Project' };
    vi.mocked(projectApi.createProject).mockResolvedValue({ data: newProject });

    function TestComponent() {
      const { projects, createProject } = useProjects();
      return (
        <div>
          <button onClick={() => createProject('New Project')}>Create</button>
          <span data-testid="count">{projects.length}</span>
        </div>
      );
    }

    render(<TestComponent />);
    expect(screen.getByTestId('count').textContent).toBe('0');

    await act(async () => {
      fireEvent.click(screen.getByText('Create'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('1');
    });
    expect(projectApi.createProject).toHaveBeenCalledWith({ name: 'New Project' });
  });

  it('handles create error', async () => {
    vi.mocked(projectApi.createProject).mockRejectedValue({
      type: 'validation',
      errors: { name: ['The name is required.'] },
    });

    function TestComponent() {
      const { error, validationErrors, createProject } = useProjects();
      return (
        <div>
          <button onClick={() => createProject('')}>Create</button>
          <span data-testid="error">{error ?? 'null'}</span>
          <span data-testid="validation">{validationErrors.name?.[0] ?? 'none'}</span>
        </div>
      );
    }

    render(<TestComponent />);

    await act(async () => {
      fireEvent.click(screen.getByText('Create'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('validation').textContent).toBe('The name is required.');
    });
  });

  it('deletes a project and removes it from the list', async () => {
    vi.mocked(projectApi.getProjects).mockResolvedValue({ data: mockProjects });
    vi.mocked(projectApi.deleteProject).mockResolvedValue(undefined);

    function TestComponent() {
      const { projects, fetchProjects, deleteProject } = useProjects();
      return (
        <div>
          <button onClick={fetchProjects}>Load</button>
          <button onClick={() => deleteProject(1)}>Delete 1</button>
          <span data-testid="count">{projects.length}</span>
        </div>
      );
    }

    render(<TestComponent />);

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });
    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('2');
    });

    await act(async () => {
      fireEvent.click(screen.getByText('Delete 1'));
    });

    await waitFor(() => {
      expect(screen.getByTestId('count').textContent).toBe('1');
    });
    expect(projectApi.deleteProject).toHaveBeenCalledWith(1);
  });

  it('clears errors', async () => {
    vi.mocked(projectApi.getProjects).mockRejectedValue({ type: 'server' });

    function TestComponent() {
      const { error, fetchProjects, clearErrors } = useProjects();
      return (
        <div>
          <button onClick={fetchProjects}>Load</button>
          <button onClick={clearErrors}>Clear</button>
          <span data-testid="error">{error ?? 'null'}</span>
        </div>
      );
    }

    render(<TestComponent />);

    await act(async () => {
      fireEvent.click(screen.getByText('Load'));
    });
    await waitFor(() => {
      expect(screen.getByTestId('error').textContent).toContain('Server');
    });

    await act(async () => {
      fireEvent.click(screen.getByText('Clear'));
    });

    expect(screen.getByTestId('error').textContent).toBe('null');
  });
});

describe('CreateProjectForm', () => {
  it('renders with input and submit button', () => {
    render(
      <CreateProjectForm
        onSubmit={vi.fn()}
        validationErrors={{}}
        error={null}
      />,
    );
    expect(screen.getByLabelText('Project Name')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Create Project' })).toBeInTheDocument();
  });

  it('calls onSubmit with the project name', async () => {
    const onSubmit = vi.fn().mockResolvedValue(true);
    render(
      <CreateProjectForm
        onSubmit={onSubmit}
        validationErrors={{}}
        error={null}
      />,
    );

    fireEvent.change(screen.getByLabelText('Project Name'), { target: { value: 'My Project' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Create Project' }));

    await waitFor(() => {
      expect(onSubmit).toHaveBeenCalledWith('My Project');
    });
  });

  it('clears input on successful submission', async () => {
    const onSubmit = vi.fn().mockResolvedValue(true);
    render(
      <CreateProjectForm
        onSubmit={onSubmit}
        validationErrors={{}}
        error={null}
      />,
    );

    const input = screen.getByLabelText('Project Name');
    fireEvent.change(input, { target: { value: 'My Project' } });
    fireEvent.submit(screen.getByRole('button', { name: 'Create Project' }));

    await waitFor(() => {
      expect(input).toHaveValue('');
    });
  });

  it('shows validation errors', () => {
    render(
      <CreateProjectForm
        onSubmit={vi.fn()}
        validationErrors={{ name: ['The name field is required.'] }}
        error={null}
      />,
    );

    expect(screen.getByText('The name field is required.')).toBeInTheDocument();
    expect(screen.getByLabelText('Project Name')).toHaveAttribute('aria-invalid', 'true');
  });

  it('shows error message', () => {
    render(
      <CreateProjectForm
        onSubmit={vi.fn()}
        validationErrors={{}}
        error="Server error"
      />,
    );

    expect(screen.getByRole('alert')).toHaveTextContent('Server error');
  });

  it('disables form when disabled prop is true', () => {
    render(
      <CreateProjectForm
        onSubmit={vi.fn()}
        validationErrors={{}}
        error={null}
        disabled
      />,
    );

    expect(screen.getByLabelText('Project Name')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Create Project' })).toBeDisabled();
  });
});

describe('ProjectCard', () => {
  it('renders project name and date', () => {
    render(
      <ProjectCard project={mockProject} onDelete={vi.fn()} />,
    );

    expect(screen.getByText('Test Project')).toBeInTheDocument();
    expect(screen.getByText(/Created/)).toBeInTheDocument();
  });

  it('shows delete button', () => {
    render(
      <ProjectCard project={mockProject} onDelete={vi.fn()} />,
    );

    expect(screen.getByRole('button', { name: 'Delete Test Project' })).toBeInTheDocument();
  });

  it('shows confirmation dialog on delete click', () => {
    render(
      <ProjectCard project={mockProject} onDelete={vi.fn()} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete Test Project' }));

    expect(screen.getByText('Delete this project?')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Confirm delete Test Project' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Cancel delete Test Project' })).toBeInTheDocument();
  });

  it('calls onDelete when confirmed', async () => {
    const onDelete = vi.fn().mockResolvedValue(true);
    render(
      <ProjectCard project={mockProject} onDelete={onDelete} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete Test Project' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm delete Test Project' }));

    await waitFor(() => {
      expect(onDelete).toHaveBeenCalledWith(1);
    });
  });

  it('cancels delete when cancel is clicked', () => {
    render(
      <ProjectCard project={mockProject} onDelete={vi.fn()} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Delete Test Project' }));
    expect(screen.getByText('Delete this project?')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel delete Test Project' }));
    expect(screen.queryByText('Delete this project?')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Delete Test Project' })).toBeInTheDocument();
  });
});

describe('ProjectList', () => {
  it('shows loading state', () => {
    render(
      <ProjectList projects={[]} onDelete={vi.fn()} loading={true} />,
    );

    expect(screen.getByRole('status')).toHaveTextContent('Loading projects...');
  });

  it('shows empty state when no projects', () => {
    render(
      <ProjectList projects={[]} onDelete={vi.fn()} loading={false} />,
    );

    expect(screen.getByText(/No projects yet/)).toBeInTheDocument();
  });

  it('renders project cards', () => {
    render(
      <ProjectList projects={mockProjects} onDelete={vi.fn()} loading={false} />,
    );

    expect(screen.getByText('Test Project')).toBeInTheDocument();
    expect(screen.getByText('Second Project')).toBeInTheDocument();
    expect(screen.queryByText(/No projects yet/)).not.toBeInTheDocument();
  });

  it('renders correct number of cards', () => {
    render(
      <ProjectList projects={mockProjects} onDelete={vi.fn()} loading={false} />,
    );

    const cards = screen.getAllByRole('article');
    expect(cards).toHaveLength(2);
  });
});
