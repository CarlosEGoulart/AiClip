# Laravel API — Agent Addendum

This file supplements the repository authority at `../../AGENTS.md`. It provides Laravel-specific guidance for the `apps/api/` directory. It does not replace or override root governance.

## Authority

Root `../../AGENTS.md` is the repository authority. All agent lifecycle rules, role definitions, branch naming, commit format, PR rules, TDD requirements, and testing policies are defined there. This addendum provides framework-specific context only.

## Backend Testing Convention

Backend tests use **Pest** unless an active specification explicitly requires otherwise. The test command is:

```sh
php artisan test --compact
```

PHPUnit configuration exists for compatibility but Pest is the primary runner.

## Code Style

Run Pint before finalizing PHP changes:

```sh
vendor/bin/pint --dirty --format agent
```

## Laravel Best Practices

- Use `php artisan make:` commands to create new files (migrations, controllers, models)
- Use Eloquent API Resources for API responses
- Use named routes and the `route()` function for URL generation
- Use PHP 8 constructor property promotion
- Use explicit return type declarations
- Always use curly braces for control structures

## SDD Artifacts

Specification documents (spec.md, plan.md, test-plan.md, evidence.md) are permitted project artifacts required by the active development process. Their presence in `specs/` directories does not conflict with Laravel framework guidance.

## Agent Boundaries

Builder and Tester lifecycle boundaries are defined by root `../../AGENTS.md`. This addendum does not modify those boundaries. Builder implements; Tester reviews; Orchestrator coordinates.

## Skills

Laravel-specific skills are available in `.agents/skills/` and `.claude/skills/`. Load relevant skills when working in the Laravel domain. The primary development harness is OpenCode.
