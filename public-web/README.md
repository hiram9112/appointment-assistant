# Appointment Assistant Public Web

Standalone public website and demo frontend for Appointment Assistant.

The application is responsible for presentation and user interaction. Business rules, scheduling validation and authoritative Appointment state belong to the Laravel backend.

## Stack

- React
- TypeScript
- Vite
- ESLint
- pnpm

Dependency versions are defined by `package.json` and `pnpm-lock.yaml`.

## Setup

Install dependencies:

```bash
pnpm install
```

For reproducible installation from the existing lockfile:

```bash
pnpm install --frozen-lockfile
```

## Development

Start the Vite development server:

```bash
pnpm dev
```

## Validation

Run ESLint:

```bash
pnpm lint
```

Build the production application:

```bash
pnpm build
```

The build runs TypeScript validation before generating the production assets under `dist/`.

## Production Preview

Preview the generated production build locally:

```bash
pnpm preview
```
