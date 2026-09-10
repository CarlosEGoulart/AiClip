"""Permission regression tests using parsed frontmatter and rule decisions.

These tests load agent frontmatter, parse the YAML permission mappings,
and evaluate rules with the same last-match glob logic used by OpenCode.
Substring checks are replaced by actual permission-decision assertions.
"""

import fnmatch
import unittest
from pathlib import Path

import yaml
from yaml.constructor import ConstructorError


class _UniqueKeyLoader(yaml.SafeLoader):
    pass


def _unique_construct_mapping(self, node, deep=False):
    seen = set()
    for k_node, _ in node.value:
        key = self.construct_object(k_node, deep=True)
        if key in seen:
            raise ConstructorError(
                "while constructing a mapping", node.start_mark,
                f"found duplicate key: {key}", k_node.start_mark)
        seen.add(key)
    return yaml.SafeLoader.construct_mapping(self, node, deep=deep)


_UniqueKeyLoader.add_constructor(
    yaml.resolver.BaseResolver.DEFAULT_MAPPING_TAG, _unique_construct_mapping)

REPO_ROOT = Path(__file__).resolve().parents[2]
AGENTS_DIR = REPO_ROOT / ".opencode" / "agents"

FRONTMATTER_RE = __import__("re").compile(r"^---\s*\n(.*?)\n---\s*\n", __import__("re").DOTALL)


def load_frontmatter(path):
    p = Path(path)
    text = p.read_text()
    m = FRONTMATTER_RE.match(text)
    if not m:
        raise AssertionError(f"No YAML frontmatter in {p}")
    try:
        data = yaml.load(m.group(1), Loader=_UniqueKeyLoader)
    except ConstructorError as e:
        raise AssertionError(f"Duplicate YAML key in {p}: {e}")
    except yaml.YAMLError as e:
        raise AssertionError(f"Malformed YAML in {p}: {e}")
    if not isinstance(data, dict):
        raise AssertionError(f"Frontmatter in {p} must be a mapping")
    return data


def decide(rules, key):
    """Last-match glob decision; flat string applies to every key."""
    if rules is None:
        return None
    if isinstance(rules, str):
        return rules
    result = None
    for pattern, action in rules.items():
        if fnmatch.fnmatch(key, pattern):
            result = action
    return result


class TestBuilderPermissions(unittest.TestCase):
    """Verify Builder has correct permission boundaries via parsed rules."""

    def setUp(self):
        fm = load_frontmatter(AGENTS_DIR / "builder.md")
        self.perm = fm.get("permission", {})
        self.edit_rules = self.perm.get("edit", {})
        self.read_rules = self.perm.get("read", {})
        self.bash_rules = self.perm.get("bash", {})
        self.content = (AGENTS_DIR / "builder.md").read_text()

    def test_builder_denies_governance_test_edits(self):
        self.assertEqual(decide(self.edit_rules, "tests/governance/test_governance.py"), "deny")
        self.assertEqual(decide(self.edit_rules, "tests/governance/test_agent_permissions.py"), "deny")

    def test_builder_allows_application_test_edits(self):
        self.assertEqual(decide(self.edit_rules, "apps/api/tests/Feature/ExampleTest.php"), "allow")
        self.assertEqual(decide(self.edit_rules, "apps/web/src/__tests__/App.test.tsx"), "allow")

    def test_builder_denies_planner_files(self):
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/spec.md"), "deny")
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/plan.md"), "deny")
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/test-plan.md"), "deny")

    def test_builder_allows_evidence(self):
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/evidence.md"), "allow")

    def test_builder_allows_application_code(self):
        self.assertEqual(decide(self.edit_rules, "apps/api/app/Http/Controllers/AuthController.php"), "allow")
        self.assertEqual(decide(self.edit_rules, "apps/web/src/components/App.tsx"), "allow")

    def test_builder_denies_control_plane_files(self):
        self.assertEqual(decide(self.edit_rules, "apps/api/AGENTS.md"), "deny")
        self.assertEqual(decide(self.edit_rules, "apps/api/opencode.json"), "deny")
        self.assertEqual(decide(self.edit_rules, "apps/api/boost.json"), "deny")
        self.assertEqual(decide(self.edit_rules, "apps/api/.agents/skills/test.md"), "deny")
        self.assertEqual(decide(self.edit_rules, "apps/api/.claude/skills/test.md"), "deny")

    def test_builder_denies_agent_config(self):
        self.assertEqual(decide(self.edit_rules, ".opencode/agents/builder.md"), "deny")
        self.assertEqual(decide(self.edit_rules, ".opencode/agents/planner.md"), "deny")

    def test_builder_denies_env_secrets(self):
        self.assertEqual(decide(self.read_rules, ".env"), "deny")
        self.assertEqual(decide(self.read_rules, "apps/api/.env"), "deny")
        self.assertEqual(decide(self.read_rules, "apps/api/.env.production"), "deny")
        self.assertEqual(decide(self.read_rules, ".env.local"), "deny")
        self.assertEqual(decide(self.read_rules, ".env.staging"), "deny")

    def test_builder_allows_env_example(self):
        self.assertEqual(decide(self.read_rules, ".env.example"), "allow")
        self.assertEqual(decide(self.read_rules, "apps/api/.env.example"), "allow")

    def test_builder_bash_allows_laravel_tests(self):
        self.assertEqual(decide(self.bash_rules, "cd apps/api && php artisan test"), "allow")
        self.assertEqual(decide(self.bash_rules, "cd apps/api && vendor/bin/pest"), "allow")

    def test_builder_bash_denies_git(self):
        self.assertEqual(decide(self.bash_rules, "git commit -m test"), "deny")
        self.assertEqual(decide(self.bash_rules, "git push origin main"), "deny")

    def test_bash_requires_command_prefix(self):
        bash_rules = self.bash_rules
        self.assertIsInstance(bash_rules, dict)
        first_key = list(bash_rules.keys())[0]
        self.assertEqual(first_key, "**")


class TestPlannerPermissions(unittest.TestCase):
    """Verify Planner has correct permission boundaries via parsed rules."""

    def setUp(self):
        fm = load_frontmatter(AGENTS_DIR / "planner.md")
        self.perm = fm.get("permission", {})
        self.edit_rules = self.perm.get("edit", {})
        self.read_rules = self.perm.get("read", {})
        self.bash_rules = self.perm.get("bash", {})

    def test_planner_allows_spec_files(self):
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/spec.md"), "allow")
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/plan.md"), "allow")
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/test-plan.md"), "allow")

    def test_planner_denies_evidence(self):
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/evidence.md"), "deny")

    def test_planner_denies_production_code(self):
        self.assertEqual(decide(self.edit_rules, "apps/api/app/Models/User.php"), "deny")
        self.assertEqual(decide(self.edit_rules, "apps/web/src/App.tsx"), "deny")

    def test_planner_bash_denied(self):
        self.assertEqual(self.bash_rules, "deny")

    def test_planner_denies_env_secrets(self):
        self.assertEqual(decide(self.read_rules, ".env"), "deny")
        self.assertEqual(decide(self.read_rules, "apps/api/.env"), "deny")
        self.assertEqual(decide(self.read_rules, ".env.production"), "deny")

    def test_planner_allows_env_example(self):
        self.assertEqual(decide(self.read_rules, ".env.example"), "allow")
        self.assertEqual(decide(self.read_rules, "apps/api/.env.example"), "allow")


class TestTesterPermissions(unittest.TestCase):
    """Verify Tester has correct permission boundaries via parsed rules."""

    def setUp(self):
        fm = load_frontmatter(AGENTS_DIR / "tester.md")
        self.perm = fm.get("permission", {})
        self.edit_rules = self.perm.get("edit", {})
        self.read_rules = self.perm.get("read", {})
        self.bash_rules = self.perm.get("bash", {})

    def test_tester_allows_evidence_only(self):
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/evidence.md"), "allow")

    def test_tester_denies_production_code(self):
        self.assertEqual(decide(self.edit_rules, "apps/api/app/Models/User.php"), "deny")
        self.assertEqual(decide(self.edit_rules, "apps/web/src/App.tsx"), "deny")

    def test_tester_denies_planner_files(self):
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/spec.md"), "deny")
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/plan.md"), "deny")
        self.assertEqual(decide(self.edit_rules, "specs/025-new-feature/test-plan.md"), "deny")

    def test_tester_bash_allows_test_commands(self):
        self.assertEqual(decide(self.bash_rules, "cd apps/api && php artisan test"), "allow")
        self.assertEqual(decide(self.bash_rules, "cd apps/web && npm test"), "allow")
        self.assertEqual(decide(self.bash_rules, "python -m unittest discover -s tests/governance"), "allow")

    def test_tester_bash_denies_git_mutation(self):
        self.assertEqual(decide(self.bash_rules, "git commit -m test"), "deny")
        self.assertEqual(decide(self.bash_rules, "git push"), "deny")

    def test_tester_bash_allows_git_readonly(self):
        self.assertEqual(decide(self.bash_rules, "git status"), "allow")
        self.assertEqual(decide(self.bash_rules, "git diff"), "allow")
        self.assertEqual(decide(self.bash_rules, "git log --oneline -5"), "allow")

    def test_tester_bash_denies_lifecycle(self):
        self.assertEqual(decide(self.bash_rules, "gh pr merge 1 --merge"), "deny")
        self.assertEqual(decide(self.bash_rules, "gh issue close 1"), "deny")

    def test_tester_denies_env_secrets(self):
        self.assertEqual(decide(self.read_rules, ".env"), "deny")
        self.assertEqual(decide(self.read_rules, "apps/api/.env"), "deny")
        self.assertEqual(decide(self.read_rules, ".env.production"), "deny")

    def test_tester_allows_env_example(self):
        self.assertEqual(decide(self.read_rules, ".env.example"), "allow")
        self.assertEqual(decide(self.read_rules, "apps/api/.env.example"), "allow")


class TestOrchestratorPermissions(unittest.TestCase):
    """Verify Orchestrator owns lifecycle commands via parsed rules."""

    def setUp(self):
        fm = load_frontmatter(AGENTS_DIR / "orchestrator.md")
        self.perm = fm.get("permission", {})
        self.edit_rules = self.perm.get("edit", {})
        self.read_rules = self.perm.get("read", {})
        self.bash_rules = self.perm.get("bash", {})
        self.task_rules = self.perm.get("task", {})

    def test_orchestrator_allows_git_lifecycle(self):
        self.assertEqual(decide(self.bash_rules, "git commit -m test"), "allow")
        self.assertEqual(decide(self.bash_rules, "git push origin main"), "allow")
        self.assertEqual(decide(self.bash_rules, "git add ."), "allow")
        self.assertEqual(decide(self.bash_rules, "git branch feature/test"), "allow")

    def test_orchestrator_allows_github_lifecycle(self):
        self.assertEqual(decide(self.bash_rules, "gh pr create --title test"), "allow")
        self.assertEqual(decide(self.bash_rules, "gh pr merge 1 --merge"), "allow")
        self.assertEqual(decide(self.bash_rules, "gh issue create --title test"), "allow")
        self.assertEqual(decide(self.bash_rules, "gh issue close 1"), "allow")

    def test_orchestrator_delegates_to_known_agents(self):
        self.assertEqual(decide(self.task_rules, "planner"), "allow")
        self.assertEqual(decide(self.task_rules, "builder"), "allow")
        self.assertEqual(decide(self.task_rules, "tester"), "allow")

    def test_orchestrator_denies_unknown_agents(self):
        self.assertEqual(decide(self.task_rules, "explorer"), "deny")
        self.assertEqual(decide(self.task_rules, "unknown-agent"), "deny")

    def test_orchestrator_cannot_edit_production_code(self):
        self.assertEqual(decide(self.edit_rules, "apps/api/app/Models/User.php"), "deny")
        self.assertEqual(decide(self.edit_rules, "apps/web/src/App.tsx"), "deny")

    def test_orchestrator_allows_project_state(self):
        self.assertEqual(decide(self.edit_rules, "docs/project-state.md"), "allow")

    def test_orchestrator_denies_env_secrets(self):
        self.assertEqual(decide(self.read_rules, ".env"), "deny")
        self.assertEqual(decide(self.read_rules, "apps/api/.env"), "deny")
        self.assertEqual(decide(self.read_rules, ".env.production"), "deny")

    def test_orchestrator_allows_env_example(self):
        self.assertEqual(decide(self.read_rules, ".env.example"), "allow")
        self.assertEqual(decide(self.read_rules, "apps/api/.env.example"), "allow")


class TestEnvProtection(unittest.TestCase):
    """Verify all agents protect .env secrets but allow .env.example."""

    def setUp(self):
        self.agents = {}
        for name in ["orchestrator.md", "planner.md", "builder.md", "tester.md"]:
            fm = load_frontmatter(AGENTS_DIR / name)
            self.agents[name] = fm.get("permission", {}).get("read", {})

    def test_all_agents_deny_root_env(self):
        for name, rules in self.agents.items():
            with self.subTest(agent=name):
                self.assertEqual(decide(rules, ".env"), "deny", f"{name} must deny .env")

    def test_all_agents_deny_nested_env(self):
        for name, rules in self.agents.items():
            with self.subTest(agent=name):
                self.assertEqual(decide(rules, "apps/api/.env"), "deny", f"{name} must deny apps/api/.env")

    def test_all_agents_deny_env_production(self):
        for name, rules in self.agents.items():
            with self.subTest(agent=name):
                self.assertEqual(decide(rules, ".env.production"), "deny", f"{name} must deny .env.production")

    def test_all_agents_allow_env_example(self):
        for name, rules in self.agents.items():
            with self.subTest(agent=name):
                self.assertEqual(decide(rules, ".env.example"), "allow", f"{name} must allow .env.example")

    def test_all_agents_allow_nested_env_example(self):
        for name, rules in self.agents.items():
            with self.subTest(agent=name):
                self.assertEqual(decide(rules, "apps/api/.env.example"), "allow", f"{name} must allow apps/api/.env.example")


class TestNoIssueSpecificDependencies(unittest.TestCase):
    """Verify no agent configuration depends on a specific issue number."""

    def test_no_issue_001_references(self):
        for agent_file in AGENTS_DIR.glob("*.md"):
            content = agent_file.read_text()
            self.assertNotIn(
                '001-init-opencode-agent-architecture',
                content,
                f"{agent_file.name} still references Issue #1"
            )

    def test_no_issue_specific_evidence_paths(self):
        import re
        for agent_file in AGENTS_DIR.glob("*.md"):
            content = agent_file.read_text()
            matches = re.findall(r'specs/\d{3}-[a-z-]+/evidence\.md', content)
            for match in matches:
                self.fail(f"{agent_file.name} contains issue-specific path: {match}")

    def test_no_self_configuration_exceptions(self):
        for agent_file in AGENTS_DIR.glob("*.md"):
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

    def test_dod_requires_explicit_authorization(self):
        self.assertIn('explicit authorization', self.content.lower())


if __name__ == '__main__':
    unittest.main()
