# .claude/agents/

Subagent definitions for the Payroll & Accounting project. These are loaded automatically by Claude Code when present at `.claude/agents/*.md` in the project root.

## Roster

| Agent | Role | Tools | Read-Only |
|---|---|---|---|
| `project-manager` | Scope, planning, status, gates | Read, Grep, Glob, WebSearch | **Yes** |
| `backend-developer` | Laravel implementation | Read, Write, Edit, Grep, Glob, Bash | No |
| `frontend-designer` | React + UI implementation | Read, Write, Edit, Grep, Glob, Bash | No |
| `qa` | Testing, verification, audits | Read, Write, Edit, Grep, Glob, Bash | No (writes tests) |
| `git-expert` | Version control | Read, Bash, Grep, Glob | Code: yes / Git: no |

## Routing — Who Does What

| Task | Route to |
|---|---|
| "Is this in scope?" / "Where are we?" / "Are we ready for the gate?" | `project-manager` |
| Migrations, models, services, actions, FormRequests, policies, Pest tests | `backend-developer` |
| Pages, components, hooks, Zustand stores, Vitest tests, anything visual | `frontend-designer` |
| Verifying a feature, reproducing a bug, writing failing tests, audits | `qa` |
| Branching, committing, merging, conflicts, history, releases | `git-expert` |

## Workflow Pattern

For most features, the chain runs like this:

1. **`project-manager`** — confirms the work is in the current phase
2. **`backend-developer`** — builds the backend layers
3. **`frontend-designer`** — builds the UI on top
4. **`qa`** — verifies behavior against acceptance criteria
5. **`git-expert`** — commits and pushes with proper hygiene

For a bug fix:

1. **`qa`** — reproduces and writes a failing test
2. **`backend-developer`** or **`frontend-designer`** — fixes
3. **`qa`** — verifies and runs regression
4. **`git-expert`** — commits

## Document Hierarchy

Every agent reads from this canonical set. The agents themselves cite specific sections rather than restating rules:

- `CLAUDE.md` — entry-point router (humans + AI)
- `AGENTS.md` — operating playbook (general AI behavior)
- `PLAN.md` — 16-week phased delivery
- `THEME.md` — visual design specification
- `RULES.md` — UI rules + shadcn best practices
- `CODING_STANDARDS_LARAVEL.md` — Laravel backend
- `CODING_STANDARDS_REACT.md` — React frontend

If a subagent's instruction conflicts with one of these documents, the document wins. The subagent is enforcement, not authority.
