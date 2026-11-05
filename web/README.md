# Nexus web

React/TypeScript Vite SPA for the HostGator PHP/MySQL build. The supported UI scope is student/admin authentication, standalone subjects, tests, and AI Notebook. Other school, roster, folder, reporting, and study-content areas are not part of this deployment. The API route contract is documented in [`../docs/API-COMPATIBILITY.md`](../docs/API-COMPATIBILITY.md).

## Requirements and local development

- Node.js 20+ and npm for development/build only
- Laravel API with a configured MySQL database

From `D:\claude\nexus_sbi_hostgator\web`:

```powershell
npm ci
$env:VITE_API_BASE_URL = 'http://localhost:8000'
npm run dev
```

`VITE_API_BASE_URL` is the API origin without `/api`; requests target `${VITE_API_BASE_URL}/api/...`. Leave it unset for same-origin hosting. Only `VITE_` variables are included in browser code; never place cloud or database credentials there.

Available commands:

```powershell
npm run typecheck
npm run build
npm run dev -- --host 0.0.0.0
```

Deploy `dist` as static files; HostGator does not need a Node.js process.

## Authentication and authorization

- Sign-in uses `POST /api/auth/login` and confirms the identity with `GET /api/auth/me`.
- Access tokens are stored in browser local storage and sent as bearer authorization for API calls.
- Required password changes use `POST /api/auth/change-password` before normal page navigation.
- Student/admin menus provide convenience, not security. Laravel enforces permissions on protected API routes.
- The client does not use device fingerprint registration.

## Core workflows

- **Students:** view available tests, submit answers, review released results, request access after an expired deadline, and use AI Notebook.
- **Administrators:** manage standalone subjects, notebook sources/prompts, tests, access requests, team members, and user accounts according to permissions.
- Notebook sources are stored privately and indexed through OpenRouter embeddings. Chat uses the configured Ollama Cloud model; students can set a personal prompt and response language per subject.
- Submissions are saved immediately and graded by the cPanel grading cron (every 15 minutes); no completion-time estimate is shown until staging measurements are available. Typed answers are queued too unless `GRADING_QUEUE_TYPED=false`. Photos and PDF pages are submitted as images. Marks are stored when grading finishes and released immediately by default; administrators can configure a 0-720 hour release delay. The Ollama model must support vision. Admins can retry non-provider failures and edit marks.

Run `npm run typecheck` and `npm run build` before packaging.
