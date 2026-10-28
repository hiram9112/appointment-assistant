---
paths:
    - "**"
---

# General

## Backend has no frontend build pipeline

The Laravel backend has no frontend build pipeline. Do not add or use Vite, npm, pnpm, React, or frontend assets under backend/. The standalone public frontend lives in the repository root at `public-web/`.

## Do not add speculative API versioning

Do not introduce API versioning unless explicitly required by the architecture, an external compatibility requirement, or the current slice plan.

## Create factories and seeders only when useful

Create model factories when they support tests or development fixtures. Create seeders only when required by the current slice or development workflow; do not create speculative seeders.

## No Laravel Cloud or speculative deployment infrastructure

Laravel Cloud is not part of the current architecture. Do not introduce or configure deployment infrastructure unless required by the current scope.
