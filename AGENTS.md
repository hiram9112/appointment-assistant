# AGENTS.md

## Purpose

This file defines the operating rules for coding agents working in this repository.

Product behavior, architecture and implementation planning are defined elsewhere. The Agent implements the scope explicitly requested by the Developer.

---

## 1. Roles

### Developer

The Developer:

- selects the slice or task to implement;
- approves design decisions;
- performs actions marked `[Developer]`;
- reviews the resulting changes;
- controls Git commits and repository history.

### Agent

The Agent:

- implements only the requested slice or task;
- reads only the existing code relevant to that work and any areas that may reasonably be affected by the change;
- follows the approved slice plan;
- adds or modifies the tests required by that plan;
- runs the automated validation required for the affected application after implementation;
- reports what was changed and verified.

The Agent does not redesign the requested implementation unless a real conflict or blocker is discovered.

---

## 2. Scope

Implement only the slice or task explicitly requested by the Developer.

If a slice is divided into tasks and the Developer requests one task, implement only that task.

Do not implement later tasks or future slices in advance.

Do not add speculative functionality, abstractions or infrastructure for future requirements.

Supporting changes are allowed only when they are necessary for the requested implementation.

If implementation reveals that the approved plan cannot be followed safely or conflicts with the existing system, stop that part of the work and report the issue instead of silently changing the design.

---

## 3. Context

For each implementation task:

1. read this `AGENTS.md`;
2. read the active slice plan under `docs/plans/v1/slices/`;
3. inspect only the existing code relevant to the requested work and possible regressions.

Do not read the entire repository before every task.

### Nested instructions

This file defines repository-wide rules.

When working in a directory that contains a more specific `AGENTS.md`, read and follow it in addition to this file.

For backend work, also read the applicable committed rules under `backend/.ai/rules/` as directed by `backend/AGENTS.md`.

Project-specific architecture, slice plans and committed project rules take precedence over generic framework guidance when they conflict.

### Product

Use the active slice `Covers` field to identify the relevant `FR-xx` and `BR-xx` sections in `docs/product.md`.

Read those sections and only additional Product context required to understand them.

Do not read the complete `product.md` by default.

### Architecture

The slice plan contains the design already agreed for the implementation.

Consult the relevant parts of `docs/architecture.md` or relevant ADRs when the slice plan requires it or when implementation exposes an architectural question or conflict.

Do not read the complete architecture documentation by default.

### V1 roadmap

`docs/plans/v1/v1.md` is the general V1 roadmap.

It does not need to be read completely for each implementation task.

Consult it only when the active slice plan or a dependency requires it.

### Reference Business

Consult `docs/reference-business.md` only when the current implementation requires reference data for seeds, fixtures, tests, demo scenarios or acceptance validation.

The reference Business must not introduce Business-specific concepts into the generic domain.

---

## 4. Implementation

Follow the active slice plan.

Prefer the smallest implementation that satisfies the requested task.

Reuse existing project code and conventions when appropriate.

Do not expand scope because a future requirement might benefit from it.

If a real ambiguity, contradiction or missing design decision appears during implementation, report it instead of choosing a significant design direction silently.

### Repository boundaries

`backend/` contains the Laravel application core.

`public-web/` contains the standalone React and TypeScript public frontend.

Do not move responsibilities across these application boundaries or introduce tooling from one application into the other unless the approved slice plan explicitly requires it.

---

## 5. Tests and verification

Create or update the tests specified by the active slice plan.

After each requested implementation task, run the complete automated validation relevant to the affected application.

Run repository-wide mandatory checks when required by the active slice plan or completion criteria.

If the project defines additional mandatory checks for that task, run them as well.

Do not weaken or remove valid tests merely to make an implementation pass.

Do not claim that something works if it was not verified.

If a required check cannot be executed, report it.

---

## 6. Developer actions

Anything marked `[Developer]` is performed by the Developer.

The Agent must not perform it.

If implementation cannot continue without a `[Developer]` action, report what is required and stop at the maximum verifiable point.

---

## 7. Git

Git operations are controlled by the Developer.

The Agent must never commit, stage, push, merge, rebase, switch branches or otherwise modify Git state.

Read-only Git inspection is allowed when useful, including:

- `git status`;
- `git diff`;
- `git log`;
- `git show`.

The Developer reviews and commits all changes.

**Exception — Merged branch cleanup:** When explicitly requested by the developer, the agent may switch to `main`, update it using fast-forward only, and delete a merged branch using `git branch -d`, including its remote branch. Before deletion, verify that the working tree is clean (including untracked files) and that the branch is fully merged into `main`. Abort if any check fails. Never use `git branch -D` or force deletion.

---

## 8. Completion report

When the requested task is finished, report:

1. what was implemented;
2. files created or changed;
3. tests created or changed;
4. test and verification commands executed;
5. their results;
6. any `[Developer]` action still required;
7. anything that could not be verified;
8. any blocker or risk relevant to the active slice.

Do not create a Git commit.
