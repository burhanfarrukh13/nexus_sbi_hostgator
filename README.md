# Nexus SBI — HostGator deployment copy

This directory is an isolated deployment branch for the supplied HostGator Business target. The original NestJS/PostgreSQL backend remains read-only and was not copied. The React/Vite source is copied for off-host static builds. A Laravel 12/PHP API is started here, but this is **not a full application port**; see [`docs/API-COMPATIBILITY.md`](docs/API-COMPATIBILITY.md) and [`docs/HOSTGATOR-DEPLOYMENT.md`](docs/HOSTGATOR-DEPLOYMENT.md) before use.

Runtime validation note: the build is intentionally structured for HostGator + MySQL, but a real PHP 8.2+/Composer runtime and actual HostGator or Laragon database credentials are still required before live deployment can be certified. The included `scripts/verify-hostgator-runtime.ps1` helper checks whether the local environment has the required PHP extensions before running Laravel or Composer commands.

## Current implementation

Implemented API slice:

- `GET /api/health`
- `POST /api/auth/login`, `POST /api/auth/refresh`, `POST /api/auth/signout`, `GET /api/auth/me`, and `POST /api/auth/change-password`
- Team member list/create/permission replacement/password reset at `GET/POST /api/team-members` and `PUT /api/team-members/{userId}/permissions|password`
- School listing and creation at `GET /api/school/list` and `POST /api/school`
- Subject list/create/update/class mapping/teacher assignment at `GET /api/subjects/list`, `POST /api/subjects`, `PUT /api/subjects/{id}`, and the two `POST /api/subjects/{id}/...` routes
- Student list/get/create/update/class assignment/archive/permanent deletion at `/api/students`
- Student query create/list/get/reply at `/api/student-queries`, with owner restrictions and admin query-handler permissions
- Authenticated privacy-preserving rankings at `GET /api/leaderboard`
- User list/create/exact-email lookup/partial-email search at `/api/users`, including role scoping and active-device search results
- All eight admin analytics routes at `/api/admin/analytics` (overview, filters, students, student detail, questions, topics, quizzes, and activity), with cohort/date/content filters and role authorization
- Test list/create authoring at `GET/POST /api/tests`, plus student access and attempt discovery at `GET /api/tests/me/attempts`, `GET /api/tests/{id}/me`, and `POST /api/tests/{id}/me/request-access`; student submission persists answers and private upload metadata, but grading is unavailable and valid resubmission fails closed with 503. Do not use student assessments in production. Question-import preview lacks OCR and full source answer-key analysis.
- Folder list/create/update/delete, permission grant/revocation/listing, and per-user authorized listing; multipart cover upload/clear routes are not ported
- Authenticated, user-bound signed private-file downloads at `GET /api/storage/file`
- MySQL identity/session, audit, school-year, student/cohort/license, subject/class/section/container, lecture-relation, folder-permission/student-delete dependency, test-authoring/list-relation, and student-attempt/file-metadata migrations; a one-time initial-admin CLI command; an OpenAI-compatible configurable cloud-embedding client; and private storage primitives

The inventory contains 94 source controller operations including `GET /api/health`: 55 operations are fully implemented (54 application routes plus health); preview, submission, and resubmission are partial, leaving 39 incomplete operations (36 unported plus three partial). These are source-derived slices with feature tests authored but not executed, not application parity. PHP/Composer are unavailable in this environment, so Laravel tests cannot run. Submission writes remain pending because the source assessment/grading engine and recovery worker are not ported; do not enable student assessments or cut over the React client to this partial API.

## Build and local validation

Requirements are PHP 8.2+, Composer 2, PDO MySQL (and PDO SQLite for tests), and the PHP extensions listed in `backend/composer.json`.

```powershell
cd backend
composer update
composer validate
Copy-Item .env.example .env
php artisan key:generate
php artisan test
```

`composer.lock` is intentionally absent because Composer and PHP are not installed in the implementation environment. Resolve dependencies on the build machine, inspect and commit the resulting lockfile, then deploy `vendor/` with the PHP application. Never copy `.env` from another environment.

Build the static site off-host:

```powershell
cd web
npm ci
$env:VITE_API_BASE_URL = "https://api.example.com"
npm run typecheck
npm run build
```

Deploy `web/dist` to the static site's document root. This is build-time configuration only; never put API keys or backend secrets in `VITE_*` variables.

HostGator packaging helper:

```powershell
cd scripts
.\prepare-hostgator-package.ps1
```

The helper prepares a deployment bundle from the HostGator API and static web build without touching the source project at `D:\claude\nexus_sbi`.

## Do not deploy yet

The HostGator PHP version/extensions, MySQL release, outbound HTTPS support, and account limits remain unverified. The identity, audit, school, subject, and folder-related migrations are not the complete source schema; class and section tables currently support the subject domain but have no source-equivalent management routes, and the folder-access-plan table is only a billing-history delete guard. There are no production credentials, database records, uploads, sample seeds, or database dumps in this copy.
