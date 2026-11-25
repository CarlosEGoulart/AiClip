import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../hooks';
import { CreateProjectForm, ProjectList, useProjects } from '../../projects';
import { ProjectMediaSection } from '../../media';
import heroImage from '../../../assets/hero.png';

export function AuthenticatedShell() {
  const { state, errorMessage, clearErrors } = useAuth();
  const {
    projects,
    loading,
    error: projectError,
    validationErrors,
    fetchProjects,
    createProject,
    deleteProject,
  } = useProjects();

  const hasFetchedRef = useRef(false);

  const [selectedProjectId, setSelectedProjectId] = useState<number | null>(null);
  const [selectedProjectName, setSelectedProjectName] = useState<string>('');

  useEffect(() => {
    if (state === 'authenticated' && !hasFetchedRef.current) {
      hasFetchedRef.current = true;
      fetchProjects();
    }
  }, [state, fetchProjects]);

  return (
    <div className="dashboard">
      {errorMessage && (
        <div role="alert" className="auth-error" onClick={clearErrors}>
          {errorMessage}
        </div>
      )}
      {!selectedProjectId && (
        <section className="dashboard-hero section-enters" aria-label="Dashboard">
          <div className="dashboard-hero-content">
            <h1>Turn long video into short clips</h1>
            <p className="dashboard-hero-supporting">
              Upload a video, let AiClip transcribe and scene-detect it, pick the
              best clips, review them, and publish to social.
            </p>
          </div>
          <div className="dashboard-hero-picture">
            <img
              src={heroImage}
              alt="AiClip workspace: a video frame inside the editing frame"
              className="moving-picture"
              width={480}
              height={270}
              loading="lazy"
            />
          </div>
          {/* Image credit for the project's own hero picture */}
          <span className="sr-only">
            AiClip workspace frame — AiClip, project-owned
          </span>
        </section>
      )}

      {selectedProjectId ? (
        <ProjectMediaSection
          projectId={selectedProjectId}
          projectName={selectedProjectName}
          onBack={() => {
            setSelectedProjectId(null);
            setSelectedProjectName('');
          }}
        />
      ) : (
        <section className="projects-plate" aria-label="Projects">
          <CreateProjectForm
            onSubmit={createProject}
            validationErrors={validationErrors}
            error={projectError}
          />

          <ProjectList
            projects={projects}
            onDelete={deleteProject}
            onSelect={(id) => {
              const project = projects.find((p) => p.id === id);
              if (project) {
                setSelectedProjectId(project.id);
                setSelectedProjectName(project.name);
              }
            }}
            loading={loading}
          />
        </section>
      )}
    </div>
  );
}
