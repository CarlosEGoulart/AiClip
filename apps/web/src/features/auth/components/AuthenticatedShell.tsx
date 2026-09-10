import { useEffect } from 'react';
import { useAuth } from '../hooks';
import { CreateProjectForm, ProjectList, useProjects } from '../../projects';

export function AuthenticatedShell() {
  const { user, logout, state, errorMessage } = useAuth();
  const {
    projects,
    loading,
    error: projectError,
    validationErrors,
    fetchProjects,
    createProject,
    deleteProject,
  } = useProjects();

  const isLoggingOut = state === 'logging-out';
  const logoutFailed = state === 'authenticated' && errorMessage;

  useEffect(() => {
    if (state === 'authenticated') {
      fetchProjects();
    }
  }, [state, fetchProjects]);

  return (
    <div className="authenticated-shell">
      <header className="app-header">
        <h1>AiClip</h1>
      </header>
      <div className="app-main">
        <div className="user-info">
          <p className="welcome-message">
            Signed in as <strong>{user?.name}</strong>
          </p>
          <p className="user-email">{user?.email}</p>
        </div>

        {logoutFailed && (
          <div className="error-message" role="alert">
            {errorMessage || 'Logout failed. The server session may still be active.'}
          </div>
        )}

        <div className="projects-section">
          <CreateProjectForm
            onSubmit={createProject}
            validationErrors={validationErrors}
            error={projectError}
          />

          <ProjectList
            projects={projects}
            onDelete={deleteProject}
            loading={loading}
          />
        </div>

        <button onClick={logout} disabled={isLoggingOut} className="logout-button">
          {isLoggingOut ? 'Logging out...' : 'Log out'}
        </button>
      </div>
    </div>
  );
}
