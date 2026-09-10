import type { Project } from '../types';
import { ProjectCard } from './ProjectCard';

interface ProjectListProps {
  projects: Project[];
  onDelete: (id: number) => Promise<boolean>;
  loading: boolean;
}

export function ProjectList({ projects, onDelete, loading }: ProjectListProps) {
  if (loading) {
    return (
      <div className="project-list-loading" role="status">
        Loading projects...
      </div>
    );
  }

  if (projects.length === 0) {
    return (
      <div className="project-list-empty">
        <p>No projects yet. Create your first project above.</p>
      </div>
    );
  }

  return (
    <div className="project-list" role="list" aria-label="Projects">
      {projects.map((project) => (
        <ProjectCard
          key={project.id}
          project={project}
          onDelete={onDelete}
        />
      ))}
    </div>
  );
}
