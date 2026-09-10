"""Governance contract checks for issue #1 (simplified).

Covers required artifacts, frontmatter/modes, representative
allow/deny via last-match glob, six headings, and basic CI.
Prose meaning, links, scope, and skill topics are manual review.
"""

import fnmatch
import re
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
SPEC_DIR = REPO_ROOT / "specs" / "001-init-opencode-agent-architecture"
AGENTS_DIR = REPO_ROOT / ".opencode" / "agents"
SKILLS_DIR = REPO_ROOT / ".opencode" / "skills"
CI_WORKFLOW = REPO_ROOT / ".github" / "workflows" / "governance.yml"
PROJECT_STATE = REPO_ROOT / "docs" / "project-state.md"
REQUIREMENTS = REPO_ROOT / "tests" / "governance" / "requirements.txt"

REQUIRED_FILES = [
    "AGENTS.md", "README.md", "docs/bootstrap.md",
    "docs/project-state.md", "docs/prd.md", "docs/architecture.md",
    "docs/roadmap.md", "docs/adr/0001-governance-bootstrap.md",
    ".opencode/agents/orchestrator.md", ".opencode/agents/planner.md",
    ".opencode/agents/builder.md", ".opencode/agents/tester.md",
    ".opencode/skills/issue-linearity/SKILL.md",
    ".opencode/skills/tdd-enforcer/SKILL.md",
    ".opencode/skills/token-efficient-context/SKILL.md",
    ".opencode/skills/playwright-visual-qa/SKILL.md",
    ".opencode/skills/media-pipeline/SKILL.md",
    ".opencode/skills/social-publishing/SKILL.md",
    "tests/governance/test_governance.py",
    "tests/governance/requirements.txt",
    ".github/workflows/governance.yml",
    "specs/001-init-opencode-agent-architecture/spec.md",
    "specs/001-init-opencode-agent-architecture/plan.md",
    "specs/001-init-opencode-agent-architecture/test-plan.md",
    "specs/001-init-opencode-agent-architecture/evidence.md",
]

AGENT_MODES = {
    "orchestrator.md": "primary", "planner.md": "subagent",
    "builder.md": "subagent", "tester.md": "subagent",
}
VALID_TOOLS = {"*", "read", "edit", "glob", "grep", "list", "bash", "task",
               "external_directory", "todowrite", "question", "webfetch",
               "websearch", "lsp", "doom_loop", "skill"}
VALID_ACTIONS = {"allow", "deny", "ask"}
STATE_HEADINGS = ["Current Architecture", "Completed Capabilities",
                  "Important Decisions", "Known Limitations",
                  "Current Milestone", "Next Architectural Goal"]
FRONTMATTER_RE = re.compile(r"^---\s*\n(.*?)\n---\s*\n", re.DOTALL)
NAME_RE = re.compile(r"^[a-z0-9]+(-[a-z0-9]+)*$")
TEST_CMD = "python -m unittest discover -s tests/governance -p 'test_*.py' -v"


def load_frontmatter(path):
    p = Path(path)
    if not p.exists():
        raise AssertionError(f"Missing required path: {p}")
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


def load_workflow():
    if not CI_WORKFLOW.exists():
        raise AssertionError(f"Missing required path: {CI_WORKFLOW}")
    try:
        data = yaml.load(CI_WORKFLOW.read_text(), Loader=_UniqueKeyLoader)
    except ConstructorError as e:
        raise AssertionError(f"Duplicate YAML key in CI: {e}")
    if True in data and "on" not in data:
        data["on"] = data.pop(True)
    return data


class TestGovernanceContract(unittest.TestCase):
    def test_g1_required_artifacts_exist_nonempty(self):
        for rel in REQUIRED_FILES:
            with self.subTest(path=rel):
                p = REPO_ROOT / rel
                self.assertTrue(p.exists(), f"Missing required path: {rel}")
                self.assertTrue(p.stat().st_size > 0, f"Empty file: {rel}")

    def test_g2_agent_frontmatter_modes_permissions_shape(self):
        for name, mode in AGENT_MODES.items():
            with self.subTest(agent=name):
                fm = load_frontmatter(AGENTS_DIR / name)
                self.assertTrue(fm.get("description"), f"{name} needs description")
                self.assertEqual(fm.get("mode"), mode, f"{name} mode")
                self.assertNotIn("model", fm, f"{name} must omit durable model")
                perm = fm.get("permission")
                self.assertIsInstance(perm, dict, f"{name} permission must be a map")
                for tool, rules in perm.items():
                    self.assertIn(tool, VALID_TOOLS, f"{name} unknown tool {tool}")
                    if isinstance(rules, str):
                        self.assertIn(rules, VALID_ACTIONS)
                    else:
                        self.assertIsInstance(rules, dict, f"{name}.{tool} must be flat or map")
                        for pat, act in rules.items():
                            self.assertIn(act, VALID_ACTIONS, f"{name}.{tool}[{pat}]")

    def test_g3_representative_edit_decisions(self):
        orch = load_frontmatter(AGENTS_DIR / "orchestrator.md")["permission"].get("edit", {})
        plan = load_frontmatter(AGENTS_DIR / "planner.md")["permission"].get("edit", {})
        build = load_frontmatter(AGENTS_DIR / "builder.md")["permission"].get("edit", {})
        test = load_frontmatter(AGENTS_DIR / "tester.md")["permission"].get("edit", {})
        for rules in (orch, plan, build, test):
            self.assertEqual(decide(rules, "**-default-probe-xyz") if isinstance(rules, dict) else rules, "deny")
            if isinstance(rules, dict):
                self.assertEqual(list(rules)[0], "**", "broad deny must come first")
        spec = "specs/001-init-opencode-agent-architecture/spec.md"
        ev = "specs/001-init-opencode-agent-architecture/evidence.md"
        self.assertEqual(decide(orch, "docs/project-state.md"), "allow")
        self.assertEqual(decide(orch, ev), "allow")
        self.assertEqual(decide(orch, "README.md"), "deny")
        self.assertEqual(decide(orch, spec), "deny")
        self.assertEqual(decide(orch, ".opencode/agents/builder.md"), "deny")
        self.assertEqual(decide(plan, spec), "allow")
        self.assertEqual(decide(plan, ev), "deny")
        self.assertEqual(decide(plan, "AGENTS.md"), "deny")
        self.assertEqual(decide(build, "AGENTS.md"), "deny")
        self.assertEqual(decide(build, ".opencode/agents/builder.md"), "deny")
        self.assertEqual(decide(build, "docs/project-state.md"), "deny")
        self.assertEqual(decide(build, "tests/governance/test_governance.py"), "deny")
        self.assertEqual(decide(build, ".github/workflows/governance.yml"), "deny")
        self.assertEqual(decide(build, ev), "allow")
        self.assertEqual(decide(build, spec), "deny")
        allows = [k for k, v in test.items() if v == "allow"] if isinstance(test, dict) else []
        self.assertEqual(decide(test, ev), "allow")
        self.assertEqual(len(allows), 1, "Tester must allow only evidence.md")
        self.assertEqual(decide(test, "AGENTS.md"), "deny")

    def test_g4_representative_task_shell_decisions(self):
        orch_t = load_frontmatter(AGENTS_DIR / "orchestrator.md")["permission"].get("task", {})
        self.assertEqual(decide(orch_t, "planner"), "allow")
        self.assertEqual(decide(orch_t, "builder"), "allow")
        self.assertEqual(decide(orch_t, "tester"), "allow")
        self.assertEqual(decide(orch_t, "general"), "deny")
        for name in ("planner.md", "builder.md", "tester.md"):
            with self.subTest(agent=name):
                t = load_frontmatter(AGENTS_DIR / name)["permission"].get("task", {})
                self.assertEqual(decide(t, "planner"), "deny")
                self.assertEqual(decide(t, "builder"), "deny")
        orch_b = load_frontmatter(AGENTS_DIR / "orchestrator.md")["permission"].get("bash", {})
        build_b = load_frontmatter(AGENTS_DIR / "builder.md")["permission"].get("bash", {})
        test_b = load_frontmatter(AGENTS_DIR / "tester.md")["permission"].get("bash", {})
        plan_b = load_frontmatter(AGENTS_DIR / "planner.md")["permission"].get("bash", {})
        self.assertEqual(decide(orch_b, "git commit -m x"), "allow")
        self.assertEqual(decide(orch_b, "gh pr create --title x"), "allow")
        self.assertEqual(decide(orch_b, "rm -rf /tmp/x"), "deny")
        self.assertEqual(decide(build_b, TEST_CMD), "deny")
        self.assertEqual(decide(build_b, "git commit -m x"), "deny")
        self.assertEqual(decide(test_b, TEST_CMD), "allow")
        self.assertEqual(decide(test_b, "git commit -m x"), "deny")
        self.assertEqual(decide(plan_b, "git commit -m x"), "deny")
        self.assertEqual(decide(plan_b, TEST_CMD), "deny")

    def test_g5_six_skills_parse(self):
        names = ["issue-linearity", "tdd-enforcer", "token-efficient-context",
                 "playwright-visual-qa", "media-pipeline", "social-publishing"]
        found = [d for d in SKILLS_DIR.iterdir() if d.is_dir()] if SKILLS_DIR.exists() else []
        self.assertEqual(sorted(d.name for d in found), sorted(names))
        for name in names:
            with self.subTest(skill=name):
                fm = load_frontmatter(SKILLS_DIR / name / "SKILL.md")
                self.assertEqual(fm.get("name"), name)
                self.assertTrue(NAME_RE.match(fm.get("name", "")), "name format")
                self.assertLessEqual(len(fm.get("name", "")), 64)
                desc = fm.get("description", "")
                self.assertTrue(1 <= len(desc) <= 1024, "description length")
                body = FRONTMATTER_RE.sub("", (SKILLS_DIR / name / "SKILL.md").read_text()).strip()
                self.assertGreater(len(body), 50, "body must be substantive")

    def test_g6_project_state_six_headings(self):
        if not PROJECT_STATE.exists():
            raise AssertionError(f"Missing required path: {PROJECT_STATE}")
        content = PROJECT_STATE.read_text()
        headings = re.findall(r"^#\s+(.+?)\s*$", content, re.MULTILINE)
        self.assertEqual(headings, STATE_HEADINGS)
        for i, h in enumerate(STATE_HEADINGS):
            m = re.search(rf"^#\s+{re.escape(h)}\s*$", content, re.MULTILINE)
            self.assertIsNotNone(m, f"Missing heading {h}")
            end = m.end()
            nxt = re.search(r"^#\s+", content[end:], re.MULTILINE)
            section = content[end:end + nxt.start()] if nxt else content[end:]
            self.assertTrue(section.strip(), f"Heading {h} needs content")

    def test_g7_basic_ci_shape(self):
        data = load_workflow()
        self.assertIn("on", data)
        self.assertIn("pull_request", data["on"])
        self.assertIn("master", data["on"].get("push", {}).get("branches", []))
        self.assertEqual(data.get("permissions", {}).get("contents"), "read")
        found_checkout = found_python = found_test = False
        for job in data.get("jobs", {}).values():
            for step in job.get("steps", []):
                uses = step.get("uses", "")
                if "checkout" in uses:
                    found_checkout = True
                    with self.subTest(check="checkout"):
                        cfg = step.get("with", {})
                        val = step.get("persist-credentials", cfg.get("persist-credentials"))
                        self.assertIn("persist-credentials", {**cfg, **step})
                        self.assertIs(val, False)
                if "setup-python" in uses:
                    found_python = True
                if TEST_CMD in step.get("run", ""):
                    found_test = True
        self.assertTrue(found_checkout, "needs checkout step")
        self.assertTrue(found_python, "needs python setup")
        self.assertTrue(found_test, "needs governance test command")
        self.assertNotIn("secrets.", CI_WORKFLOW.read_text())
        req = REQUIREMENTS.read_text().strip() if REQUIREMENTS.exists() else ""
        self.assertEqual(req, "PyYAML==6.0.3")

    def test_g8_loader_rejects_malformed_and_duplicate_keys(self):
        import tempfile
        with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False) as f:
            f.write("---\ndescription: [unclosed\n---\nbody\n")
            bad = Path(f.name)
        try:
            with self.assertRaises(AssertionError):
                load_frontmatter(bad)
        finally:
            bad.unlink()
        with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False) as f:
            f.write("---\ndescription: a\ndescription: b\n---\nbody\n")
            dup = Path(f.name)
        try:
            with self.assertRaises(AssertionError) as ctx:
                load_frontmatter(dup)
            self.assertIn("Duplicate", str(ctx.exception))
        finally:
            dup.unlink()
        with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False) as f:
            f.write("---\ndescription: ok\nmode: subagent\n---\nbody\n")
            good = Path(f.name)
        try:
            fm = load_frontmatter(good)
            self.assertEqual(fm.get("mode"), "subagent")
        finally:
            good.unlink()

    def test_g9_permission_helper_rejects_all_allow(self):
        all_allow = {"**": "allow"}
        self.assertEqual(decide(all_allow, "AGENTS.md"), "allow")
        self.assertNotEqual(decide(all_allow, "AGENTS.md"), "deny",
                            "all-allow policy must not satisfy deny-default")
        narrow = {"**": "deny", "docs/project-state.md": "allow"}
        self.assertEqual(decide(narrow, "docs/project-state.md"), "allow")
        self.assertEqual(decide(narrow, "README.md"), "deny")
        ordered = {"**": "deny", "docs/*": "allow", "docs/secret.md": "deny"}
        self.assertEqual(decide(ordered, "docs/secret.md"), "deny")
        self.assertEqual(decide(ordered, "docs/other.md"), "allow")

    def test_g10_historical_issue_bundles_complete(self):
        """Verify all issue directories have complete SDD bundles."""
        from validators import validate_sdd_bundle
        errors = validate_sdd_bundle(REPO_ROOT / "specs")
        self.assertEqual(errors, [])


if __name__ == "__main__":
    unittest.main()
