# Nexus Web

Independent React/TypeScript Vite SPA for the Nexus learning and admin experience. This workspace is intentionally separate from the Flutter clients and backend.

## Requirements

- Node.js 20+ and npm
- A running Nexus Nest API

## Install, configure, and run

From this directory (`D:\claude\nexus_sbi\web`):

```powershell
npm install
$env:VITE_API_BASE_URL = 'http://localhost:3001'
npm run dev
```

`VITE_API_BASE_URL` is the API origin, **without** `/api`; requests are sent to `${VITE_API_BASE_URL}/api/...`. The backend started directly with `npm run start:safe` listens on port `3001`. Leave it empty to use the current origin behind a same-origin reverse proxy. Vite loads this build-time setting from the repository-root `.env`; only variables prefixed with `VITE_` are included in browser code. Configure the API's CORS allowlist for the Vite origin if the API is hosted elsewhere. Never place backend credentials in `VITE_` variables.

Available commands:

```powershell
npm run typecheck
npm run build
npm run dev -- --host 0.0.0.0
```

The SPA uses hash navigation, for example `/#dashboard`, `/#tests`, `/#notebook`, `/#academic`, `/#test-admin`, `/#team`, `/#students`, `/#users`, `/#passwords`, `/#queries`, and `/#analytics`. Navigation is role-sensitive for convenience only; the API remains the authorization boundary.

## Authentication and security behavior

- Sign-in calls `POST /api/auth/login`, then confirms the current identity with `GET /api/auth/me`.
- The access token is stored in browser local storage and sent as a Bearer token for API requests. Sign-out calls `POST /api/auth/signout` before clearing local state.
- A server-provided `mustResetPassword` gate directs the user through `POST /api/auth/change-password` before showing other pages.
- The app does not request or send a device fingerprint and contains no device-binding interface.
- Student/admin navigation is selected from the server user role. It is not a security check; protected API routes enforce roles and permissions.
- No credentials, access tokens, source `.env` files, or mock production data are included.

## API requests and session cache

The web client keeps student test lists and test details in memory and in account-scoped browser local storage (up to seven days, with a bounded 3 MiB cache). Reopening a test or reloading the portal can render cached content without repeating the test-data GET. This cache is only a local copy; the server remains authoritative, and cached test data is removed on sign-out.

Signed-in students keep one authenticated `GET /api/student-data/events` stream open. The server sends resource-specific change events and per-process revisions. On reconnect, the client compares the server revision with each saved test cache entry and reloads only changed data; unchanged test cache entries remain usable. This requires no Redis, but notifications/revisions are process-local and deployments with multiple API instances should route a student's event stream and requests to the same instance. Original answer, question, and solution page images are fetched only when expanded, deduplicated across concurrent views, and stored as private IndexedDB blobs (up to 50 MiB, 30 days) so reopening the page avoids re-downloading. These files are deleted on sign-out or when the server test revision changes. They are browser-local plaintext; do not rely on this cache for security or as the authoritative copy.

## Implemented API-backed areas

- **Student:** `/tests/me/attempts`, `/tests/:id/me`, `/tests/:id/me/submit` (multipart `answers` JSON plus `files`), `/tests/:id/me/request-access`, `/tests/:id/me/review`, `/leaderboard`, `/ai-notebook/folders`, folder sources, notebook sessions/chat, and `/student-queries` create/list.
- **Admin:** `/school/list`, `/school/classes/list`, `/school/sections/list`, `/folders`, `/team-members`, `/tests`, question preview upload, test creation, attempt review, per-student deadline/marks updates, `/students/list`, `/student-queries` list/reply, `/admin/analytics/overview`, AI notebook folder sources and default prompts.
- Multipart requests are sent as `FormData`; the browser sets the multipart boundary. API errors (including authorization and validation errors) are surfaced rather than converted to fake success states.

## Honest TODO / disabled workflows

- Academic hierarchy mutations (adding/editing classes, batches, and folders) are not enabled. The UI shows separate class → batch → folder browse levels, while mutation workflow/DTO needs to be confirmed for this export.
- “Apply source to all same-named folders” is visibly disabled. The confirmed source API is folder-scoped and has no verified global fan-out operation; the UI will not silently upload multiple copies.
- Test creation is a small one-question authoring form aligned to the current DTO and requires explicit class/batch/folder selection, a deadline, and matching one-question/total marks. Multi-question authoring and test-to-many-folder workflows are not guessed.
- Team member create/permission-edit controls, general user administration, admin-managed password resets, and detailed analytics charts remain explicitly read-only/disabled pending endpoint DTO/workflow confirmation. Own-password changes use the confirmed self-service route.
- Notebook source/prompt APIs are real, folder-scoped operations. The UI does not manufacture data when API responses are empty or unavailable.

## Validation

Run `npm run typecheck` and `npm run build` before exporting. The app has no database, Compose, backend, Flutter, or original Nexus repo dependencies.
