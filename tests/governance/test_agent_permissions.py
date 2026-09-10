"""Tests proving durable agent configuration is reusable across issues.

These tests verify that agent role definitions are not dependent on any
specific issue number and support ordinary future development cycles.
"""

import re
import unittest
from pathlib import Path


REPO_ROOT = Path(__file__).resolve().parents[2]


class TestPlannerPermissions(unittest.TestCase):
    """Verify Planner supports arbitrary future SDD planning paths."""

    def setUp(self):
        self.config_path = Path(__file__).resolve().parents[2] / ".opencode/agents/planner.md"
        self.content = self.config_path.read_text()

    def test_allows_arbitrary_spec_paths(self):
        self.assertIn('specs/*/spec.md', self.content)
        self.assertIn('specs/*/plan.md', self.content)
        self.assertIn('specs/*/test-plan.md', self.content)

    def test_does_not_reference_specific_issue(self):
        self.assertNotIn('001-init', self.content)
        self.assertNotIn('019-', self.content)
        self.assertNotIn('022-', self.content)

    def test_cannot_edit_production_code(self):
        self.assertNotIn('apps/**', self.content)

    def test_cannot_edit_evidence(self):
        edit_section = self.content[self.content.index('edit:'):]
        edit_section = edit_section[:edit_section.index('task:')]
        self.assertNotIn('evidence.md', edit_section)

    def test_cannot_commit_or_push(self):
        self.assertNotIn('git commit', self.content.lower())
        self.assertNotIn('git push', self.content.lower())

    def test_bash_denied(self):
        self.assertIn('bash: deny', self.content)


class TestBuilderPermissions(unittest.TestCase):
    """Verify Builder supports application implementation paths."""

    def setUp(self):
        self.config_path = Path(__file__).resolve().parents[2] / ".opencode/agents/builder.md"
        self.content = self.config_path.read_text()

    def test_allows_application_paths(self):
        self.assertIn('apps/**', self.content)
        self.assertIn('tests/**', self.content)

    def test_allows_evidence(self):
        self.assertIn('specs/*/evidence.md', self.content)

    def test_cannot_edit_planner_files(self):
        self.assertNotIn('specs/*/spec.md', self.content.split('deny')[0] if 'deny' in self.content else '')
        spec_pattern = re.search(r'specs/\*/spec\.md', self.content)
        if spec_pattern:
            context_start = max(0, spec_pattern.start() - 50)
            context = self.content[context_start:spec_pattern.end() + 50]
            self.assertIn('deny', context.lower())

    def test_cannot_commit_or_push(self):
        self.assertNotIn('git commit', self.content)
        self.assertNotIn('git push', self.content)

    def test_cannot_create_issues(self):
        self.assertNotIn('gh issue create', self.content)

    def test_cannot_modify_agent_config(self):
        self.assertNotIn('.opencode/agents/', self.content.split('deny')[0] if 'deny' in self.content else 'denied')


class TestTesterPermissions(unittest.TestCase):
    """Verify Tester can update evidence but not production files."""

    def setUp(self):
        self.config_path = Path(__file__).resolve().parents[2] / ".opencode/agents/tester.md"
        self.content = self.config_path.read_text()

    def test_allows_evidence(self):
        self.assertIn('specs/*/evidence.md', self.content)

    def test_cannot_edit_production_code(self):
        self.assertNotIn('apps/**', self.content.split('deny')[0] if 'deny' in self.content else '')

    def test_cannot_edit_planner_files(self):
        self.assertNotIn('specs/*/spec.md', self.content.split('deny')[0] if 'deny' in self.content else '')
        self.assertNotIn('specs/*/plan.md', self.content.split('deny')[0] if 'deny' in self.content else '')

    def test_cannot_commit_or_push(self):
        self.assertNotIn('git commit', self.content)
        self.assertNotIn('git push', self.content)

    def test_can_inspect_git_state(self):
        self.assertIn('git status', self.content)
        self.assertIn('git diff', self.content)

    def test_cannot_perform_lifecycle(self):
        self.assertNotIn('gh pr merge', self.content)
        self.assertNotIn('gh issue close', self.content)


class TestOrchestratorPermissions(unittest.TestCase):
    """Verify Orchestrator owns lifecycle commands."""

    def setUp(self):
        self.config_path = Path(__file__).resolve().parents[2] / ".opencode/agents/orchestrator.md"
        self.content = self.config_path.read_text()

    def test_allows_git_lifecycle(self):
        self.assertIn('git commit', self.content)
        self.assertIn('git push', self.content)
        self.assertIn('git add', self.content)
        self.assertIn('git branch', self.content)

    def test_allows_github_lifecycle(self):
        self.assertIn('gh pr create', self.content)
        self.assertIn('gh pr merge', self.content)
        self.assertIn('gh issue create', self.content)
        self.assertIn('gh issue close', self.content)

    def test_allows_task_delegation(self):
        self.assertIn('planner', self.content)
        self.assertIn('builder', self.content)
        self.assertIn('tester', self.content)

    def test_cannot_implement_features(self):
        self.assertNotIn('apps/**', self.content.split('deny')[0] if 'deny' in self.content else '')

    def test_delegates_only_to_known_agents(self):
        task_section = self.content[self.content.index('task:'):]
        self.assertNotIn('explorer', task_section.split('---')[0])


class TestNoIssueSpecificDependencies(unittest.TestCase):
    """Verify no agent configuration depends on a specific issue number."""

    def setUp(self):
        self.agents_dir = Path(__file__).resolve().parents[2] / ".opencode" / "agents"

    def test_no_issue_001_references(self):
        for agent_file in self.agents_dir.glob("*.md"):
            content = agent_file.read_text()
            self.assertNotIn(
                '001-init-opencode-agent-architecture',
                content,
                f"{agent_file.name} still references Issue #1"
            )

    def test_no_issue_specific_evidence_paths(self):
        for agent_file in self.agents_dir.glob("*.md"):
            content = agent_file.read_text()
            matches = re.findall(r'specs/\d{3}-[a-z-]+/evidence\.md', content)
            for match in matches:
                self.fail(f"{agent_file.name} contains issue-specific path: {match}")

    def test_no_self_configuration_exceptions(self):
        for agent_file in self.agents_dir.glob("*.md"):
            content = agent_file.read_text()
            self.assertNotIn(
                'Self-Configuration Exception',
                content,
                f"{agent_file.name} still has Issue #1 self-config exception"
            )


class TestReadmeStructure(unittest.TestCase):
    """Verify root README retains essential durable sections."""

    def setUp(self):
        self.readme_path = REPO_ROOT / "README.md"
        self.content = self.readme_path.read_text()

    def test_has_product_identity(self):
        self.assertIn('AiClip', self.content)

    def test_has_technology_stack(self):
        self.assertIn('Laravel', self.content)
        self.assertIn('React', self.content)
        self.assertIn('PostgreSQL', self.content)
        self.assertIn('Playwright', self.content)

    def test_has_repository_structure(self):
        self.assertIn('apps/api', self.content)
        self.assertIn('apps/web', self.content)

    def test_has_development_method(self):
        self.assertIn('Test-Driven Development', self.content)

    def test_has_agent_system(self):
        self.assertIn('Orchestrator', self.content)
        self.assertIn('Planner', self.content)
        self.assertIn('Builder', self.content)
        self.assertIn('Tester', self.content)

    def test_has_testing_policy(self):
        self.assertIn('Pest', self.content)
        self.assertIn('Vitest', self.content)

    def test_has_local_development(self):
        self.assertIn('docker compose', self.content)

    def test_has_definition_of_done(self):
        self.assertIn('Definition of Done', self.content)

    def test_not_milestone_specific(self):
        self.assertNotIn('M1 Application Foundation', self.content.split('## ')[0] if '## ' in self.content else '')


if __name__ == '__main__':
    unittest.main()
