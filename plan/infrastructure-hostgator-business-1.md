---
goal: Prepare Nexus SBI for deployment on a HostGator Business hosting account
version: 2.0
date_created: 2026-11-03
last_updated: 2026-11-03
owner: User
status: 'In progress'
tags: [infrastructure, migration, hostgator, php, mysql, ai]
---

# Introduction

![Status: In progress](https://img.shields.io/badge/status-In%20progress-yellow)

This plan describes the HostGator Business migration in the isolated target directory `D:\claude\nexus_sbi_hostgator`; the source tree at `D:\claude\nexus_sbi` remains untouched. The target contains a Laravel 12 PHP/MySQL backend, static React build pipeline, cloud-model configuration, private file storage, and a HostGator package helper. The migration is still incomplete: the MySQL schema does not yet include every source-bootstrap table, several source workflows remain unported, automatic AI assessment grading is unavailable, full-suite tests have unrelated failures, and live HostGator account requirements have not been verified. Do not treat this plan as complete or deploy the target as a behavior-equivalent replacement until those gaps are resolved and validated.

## 1. Requirements & Constraints

- **REQ-001**: Make all project changes in `D:\claude\nexus_sbi_hostgator` only. Do not edit, rename, delete, or generate files in `D:\claude\nexus_sbi`.
- **REQ-002**: Copy the application source and configuration needed to build and run the project, while excluding existing user or runtime data, credentials, generated output, and installed dependencies.
- **REQ-003**: Preserve current application behavior, business rules, externally consumed API request/response contracts, authorization checks, and React workflows. Implement equivalent behavior in the PHP backend; do not assume that changing languages permits removing existing capabilities.
- **REQ-004**: Create an empty database schema on HostGator. Do not copy records or run sample-data seeds from the source project.
- **REQ-005**: Store application database records and uploaded files on HostGator. Keep private files outside the public web root and retain existing file authorization behavior.
- **REQ-006**: Use a cloud embedding API for source and query embeddings. Do not require a locally installed Ollama server or local embedding model.
- **REQ-007**: Keep cloud API keys and production secrets on the backend, outside frontend build variables and source control.
- **SEC-001**: Do not copy `.env`, private keys, `.server_keys`, user uploads, database dumps, or other credentials/data from the source project.
- **SEC-002**: Manage production schema through versioned MySQL migrations; never use automatic schema synchronization in production.
- **CON-001**: Hosting details supplied for the target are PHP (including PHP 8.2) and MySQL on HostGator Business shared hosting; Node.js is not in the supplied supported-runtime list. Before implementation, confirm the cPanel-selectable PHP version, required PHP extensions, Composer/SSH availability, database version, cron support, and account limits.
- **CON-002**: The source backend is NestJS/TypeScript with PostgreSQL and TypeORM. Porting it to PHP/MySQL is a substantial backend rewrite, including every required API route, domain rule, migration, PostgreSQL-specific query, and backend test; it cannot be achieved by changing database credentials or adding a PHP entry point.
- **CON-003**: The source root contains generated/development assets, including `.git`, `backend\node_modules`, `backend\dist`, `backend\storage`, `backend\.server_keys`, `web\node_modules`, and `web\dist`; these are not deployment source.
- **CON-004**: Do not choose an embedding provider or model until its cloud API supports the required embedding operation, authentication, batching, and stable output dimensions.
- **CON-005**: The existing React web application is built with Vite/TypeScript and requires Node.js only for development/build tooling. Build it outside HostGator and deploy static assets; do not require Node.js on the production HostGator account.
- **GUD-001**: Use the existing source as the behavior baseline and make the HostGator copy independently buildable.
- **GUD-002**: Do not use sample seeds to initialize the HostGator production database. Run schema migrations only; provision the first administrator through a separately documented, explicit production-safe operation.
- **PAT-001**: Use HostGator-provided environment configuration or a secret file outside the public web root for database credentials, cloud model credentials, signing keys, CORS origins, and persistent storage paths. Never publish secrets through the React build.
- **PAT-002**: Implement the PHP API with Laravel 12, PHP 8.2+, PDO MySQL, framework-managed migrations, request validation, authentication middleware, and explicit route/controller/service boundaries. Build Composer dependencies off-host if Composer or SSH is unavailable in cPanel; confirm the selected PHP extensions before implementation.

## 2. Implementation Steps

### Implementation Phase 1

- **GOAL-001**: Confirm the exact HostGator PHP/MySQL environment and its operational limits before implementing the PHP port.

Completion criteria: confirm the selectable PHP version (PHP 8.2 or newer), MySQL version/connection details, required PHP extensions, Composer or local-build/deploy support, SSH/cron availability, HTTPS/outbound API access, upload limits, and account resource limits. Treat PHP/MySQL as the chosen target; if required PHP extensions or a compatible MySQL version are unavailable, pause and revise the deployment plan before implementation.

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-001 | In cPanel, verify the selectable PHP version and confirm PHP 8.2+ is available. Record loaded extensions, specifically `pdo_mysql`, `openssl`, `mbstring`, `fileinfo`, `curl`, `json`, `xml`, `zip`, and image-processing support required by inspected features. | | |
| TASK-002 | Verify the provisioned MySQL version, cPanel database name/user conventions, local connection host, character set/collation support, max packet, and account database limits. Do not record credentials in the plan or repository. | | |
| TASK-003 | Check CPU/memory/process limits, PHP execution and upload/post limits, disk/inode quota, HTTPS/outbound HTTP access, cron support, SSH/Composer availability, and whether generated files can be deployed outside `public_html`. | | |
| TASK-004 | Confirm the fixed target architecture: PHP 8.2+ Laravel 12 API, PDO MySQL, MySQL-only production database, React/Vite static frontend built off-host, cloud embeddings, and private uploads on HostGator storage. If required PHP features, extensions, MySQL compatibility, outbound API access, or deployable Composer dependencies are unavailable, pause implementation and revise the plan rather than attempting to run NestJS or silently remove application features. | | |

### Implementation Phase 2

- **GOAL-002**: Create an isolated HostGator deployment copy without importing any source data or secrets.

Depends on: completion of **GOAL-001**.

Completion criteria: the target contains the tracked application source and deployment inputs needed for the selected route; the source tree is unchanged; excluded runtime/data files are absent; and the copied project has no populated production database or credentials.

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-005 | Copy the buildable source/configuration from `D:\claude\nexus_sbi` into `D:\claude\nexus_sbi_hostgator`. Exclude `.git`, all `.env` files, `backend\node_modules`, `backend\dist`, `backend\storage`, `backend\.server_keys`, `web\node_modules`, `web\dist`, database dumps, caches, and generated user/test data. Retain safe example configuration files after reviewing them for real secrets. | | |
| TASK-006 | Verify the source project has no file changes by comparing its file paths and hashes before and after the copy; verify excluded paths and secret files are absent from the target. | | |

### Implementation Phase 3

- **GOAL-003**: Design and implement the empty MySQL schema for the PHP backend, preserving source database semantics.

Depends on: **TASK-004** and **GOAL-002**. The target database is MySQL; do not retain PostgreSQL as a production branch for this shared-hosting target.

Completion criteria: a fresh HostGator MySQL database can be initialized with migrations only; PHP data access and queries work on MySQL; no source or sample records are imported; runtime schema synchronization remains disabled.

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-007 | Design the MySQL schema from the behavior and constraints represented by source entities and migrations. Define InnoDB tables, keys, indexes, foreign-key deletion rules, unique constraints, enum/value mappings, UTC date handling, JSON columns where supported, and transactional boundaries. Document deliberate PostgreSQL-to-MySQL semantic mappings before writing migrations. | | |
| TASK-008 | Implement ordered Laravel database migrations for the complete required schema, covering all source areas: users/roles/devices, folders/permissions/lectures/files/keys/licenses/audit, schools/years/classes/sections/students/subjects, testing system, tests/submissions/grading, notebook sources/chunks/chats/prompts/usage, student queries/cohorts, analytics/reporting, and billing/access plans. Do not copy records or invoke sample seeds. | | |
| TASK-009 | Configure PDO MySQL using cPanel database settings, utf8mb4, safe connection failure behavior, transactions, and the smallest practical connection footprint for shared hosting. Apply migrations explicitly with a deployment command; disable runtime schema mutation. Verify all migrations on a fresh empty MySQL database matching the confirmed server version. | | |

### Implementation Phase 4

- **GOAL-004**: Replace local-only embedding requests with a backend-authenticated cloud embedding API without changing notebook retrieval and grading behavior.

Depends on: **GOAL-002**. The specific cloud model/provider remains subject to **CON-004** and user confirmation if Ollama Cloud cannot satisfy the endpoint contract.

Completion criteria: source indexing and query embedding use the same configured cloud model, model identity is stored with each embedding, cloud failures are surfaced or use only the existing documented lexical fallback, and no frontend bundle contains the API key.

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-010 | Inspect `backend\src\ai-notebook\ai-notebook.service.ts` and `backend\src\configuration.ts` to identify embedding request/response assumptions, batch behavior, embedding dimension checks, and existing lexical fallback behavior. Verify the selected provider’s documented cloud embedding endpoint and model capability before implementation. | | |
| TASK-011 | Implement a PHP backend cloud-embedding client using the confirmed provider's documented HTTPS endpoint, authentication, model, batching, timeout, response validation, and output dimensions. Do not call a local Ollama server. Keep generation/chat configuration independent and server-side. | | |
| TASK-012 | Add tests for successful indexing, query embeddings, model/dimension mismatch, timeout, rejected credentials, provider errors, and the existing lexical fallback. Ensure logs never contain API keys or full sensitive document content. | | |

### Implementation Phase 5

- **GOAL-005**: Configure HostGator as the persistent storage location for uploaded files while retaining current access controls.

Depends on: **GOAL-002** and completion of the account storage-limit review in **TASK-003**.

Completion criteria: uploads and derived files use an explicit persistent directory under the HostGator account and outside the public web root; authorized downloads still work; storage exhaustion and unsupported upload sizes fail visibly and safely.

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-013 | Inventory source upload, file retrieval, folder cover, lecture key/encryption, test-submission, and document paths across `backend\src\storage`, `backend\src\ai-notebook`, `backend\src\tests`, `backend\src\folders`, and `backend\src\school`. Record the current authorization and persistence contract for each before porting it. | | |
| TASK-014 | Implement PHP private-file storage rooted outside `public_html`, with safe generated filenames, directory permissions, atomic writes where available, size validation, and authorized controller-mediated downloads. Keep application records and uploaded files on HostGator; do not expose private uploads through static aliases or public URLs. | | |
| TASK-015 | Compare existing maximum upload sizes, extraction/processing time, and file formats with verified HostGator limits. Make only necessary, explicit limit changes; return clear errors when a host limit is exceeded and test authorized upload, retrieval, and deletion behavior. | | |

### Implementation Phase 6

- **GOAL-006**: Reimplement the backend in PHP while retaining the existing React web client and its API-visible behavior.

Depends on: **GOAL-001**, **GOAL-002**, and **GOAL-003**.

Completion criteria: a PHP 8.2+ Laravel API runs through standard cPanel PHP/Apache hosting without a Node.js process; all required current web API contracts and business rules are preserved; the React client is built off-host and deployed as static files; routing, deep links, HTTPS, and upload limits are configured.

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-016 | Create the PHP API project in the target using Laravel 12, PHP 8.2+, Composer, PDO MySQL, and a public front controller. Configure Laravel's public document root as the web entry point; keep `.env`, source, `vendor`, logs, and private storage outside `public_html` where cPanel permits. Pin compatible dependency versions and document local/off-host Composer build steps. | | |
| TASK-017 | Inventory the NestJS route decorators, DTOs, guards, interceptors, services, and current web-client API calls. Produce an API compatibility matrix specifying method, path, authentication/role requirements, request validation, response shape/status, pagination, and side effects for every route required by the React application; use source controllers and `web\src` API calls as evidence. | | |
| TASK-018 | Port authentication and account foundations to PHP: password hashing/verification, login/refresh/logout, token/device lifecycle, role/permission checks, password reset flows, rate limits, audit behavior, and error responses. Preserve existing token lifetime/rotation and authorization semantics; add parity tests against the compatibility matrix. | | |
| TASK-019 | Port core academic/admin domains: users/team members, folders and permissions, school/year/class/section/student/subject management, enrollment/access grants, and student queries. Preserve validation, transaction, filtering, and permission behavior; implement focused service/controller tests for each route group. | | |
| TASK-020 | Port learning and assessment domains: lectures and protected files, course/grid/topic/question content, progress, quizzes, flashcards/reviews, tests, attempts/submissions, source files, grading settings/results, and delayed result release. Preserve calculations, state transitions, integrity checks, file authorization, and response contracts; add parity tests for critical flows. | | |
| TASK-021 | Port AI Notebook and analytics domains: source ingestion/extraction/chunking/indexing, cloud embeddings/retrieval, notebook chat/study aids, prompt settings, AI grading integrations, dashboard analytics, leaderboard, and report queries. Preserve source access restrictions, model identity/dimensions, response shapes, fallback/error behavior, and usage limits. | | |
| TASK-022 | Implement shared PHP security and transport behavior: request validation, authentication/authorization middleware, secure headers, CORS allowlist, CSRF policy appropriate to the bearer-token client, per-route/IP/account rate limits, safe errors, body/upload limits, logging redaction, and production secret validation. Do not expose private file routes or API secrets. | | |
| TASK-023 | Configure the React client to call the PHP API at the selected same-origin `/api` path or API subdomain. Build `web` using the existing Vite scripts off-host; deploy the generated static assets; add cPanel Apache `.htaccess` rewrites for SPA deep links while routing API requests to PHP without rewriting them to `index.html`. | | |
| TASK-024 | Add HostGator deployment and operations documentation in the target. Specify cPanel PHP version/extensions, Laravel public-root mapping, Composer vendor packaging, database creation/configuration, migration-only initialization, environment secret provisioning, cron needs, private storage path, HTTPS, upload limits, logs, backups, and rollback. Never include live secrets. | | |
| TASK-025 | Verify deployment files use only HostGator-supported PHP/MySQL/static-web capabilities; do not require a Node.js production process, PostgreSQL, Docker, Nginx, systemd, Redis, or a local Ollama service. | | |

### Implementation Phase 7

- **GOAL-007**: Verify the deployment copy and selected hosting branch without changing the source project or deploying user data.

Depends on: **GOAL-003**, **GOAL-004**, **GOAL-005**, and **GOAL-006**.

Completion criteria: target backend and frontend builds pass; relevant tests pass; migrations create an empty schema on the selected database; smoke checks cover existing auth, representative workflows, file access, and cloud embeddings; source hashes remain unchanged; no production deployment or sample-data seeding occurs without separate user authorization.

| Task | Description | Completed | Date |
|------|-------------|-----------|------|
| TASK-026 | Run PHP syntax checks, Composer dependency validation/security checks, Laravel route/config checks, and automated PHP unit/feature tests; run the existing React typecheck and production build. Keep all generated files and changes in the target. | | |
| TASK-027 | Apply Laravel migrations to a fresh empty HostGator-compatible MySQL database; verify tables, indexes, foreign keys, unique constraints, and migration status. Confirm no source records or sample-seed data are present. | | |
| TASK-028 | Run API contract and workflow tests for authentication, representative admin/student operations, assessment flows, notebook indexing/querying, analytics, permissions, and protected file upload/download. Use synthetic records only in a disposable verification database; remove them after testing. | | |
| TASK-029 | Verify PHP/Apache routing, React deep links, `/api` routing, CORS/HTTPS, cloud API connectivity, PHP upload/time limits, persistent storage permissions, cron/background-job behavior if required, and runtime error logging on the actual cPanel environment. | | |
| TASK-030 | Verify source-tree hashes remain unchanged and target contains no `.env` secrets, private keys, copied uploads, database dumps, source production records, or unintended sample-seed output. Do not deploy production data or make a live cutover without separate user authorization. | | |

## 3. Alternatives

- **ALT-001**: Keep the NestJS/PostgreSQL backend and move to HostGator VPS or another Node.js/PostgreSQL-capable host. This avoids the large PHP/MySQL rewrite but does not meet the stated goal of hosting on the HostGator Business shared plan.
- **ALT-002**: Port the backend to PHP 8.2+/Laravel and MySQL while keeping the React client static. This is the selected plan because it matches the supplied Business-plan capabilities; it carries substantial feature-parity and regression risk and requires full contract-driven implementation and testing.
- **ALT-003**: Rewrite the React web client as PHP-rendered pages. Not selected: the existing React client can remain a static build and continue using an API, avoiding an unrelated frontend rewrite.
- **ALT-004**: Use a cloud embedding provider only after verifying its embedding endpoint, model, authentication, batching, and dimensions; do not retain local Ollama embeddings on the shared host.

## 4. Dependencies

- **DEP-001**: HostGator cPanel facts from **TASK-001** through **TASK-003**, including PHP version/extensions, MySQL version, HTTPS/outbound API, Composer/SSH/cron, and resource limits.
- **DEP-002**: The fixed PHP 8.2+/Laravel 12 and MySQL architecture from **TASK-004** before implementation of **GOAL-003** and **GOAL-006**.
- **DEP-003**: A cloud embedding provider/model with documented endpoint, authentication, and vector output compatible with the retrieval implementation before **GOAL-004**.
- **DEP-004**: HostGator-issued database credentials, site/API hostnames, TLS settings, and production secrets. These are required only for a later deployment and must be entered through the hosting environment, not this plan or source control.
- **DEP-005**: Existing source code and migrations in `D:\claude\nexus_sbi`; this source is read-only throughout execution.

## 5. Files

- **FILE-001**: `D:\claude\nexus_sbi_hostgator\` — isolated copy of the buildable application source, excluding runtime data, credentials, generated outputs, and installed packages.
- **FILE-002**: `D:\claude\nexus_sbi_hostgator\backend\composer.json`, `composer.lock`, and Laravel application files — PHP API dependencies, routes, middleware, controllers, services, and configuration. Do not retain the NestJS runtime as a HostGator production requirement.
- **FILE-003**: `D:\claude\nexus_sbi_hostgator\backend\database\migrations\` and Laravel database configuration — complete empty MySQL schema and migration execution.
- **FILE-004**: `D:\claude\nexus_sbi_hostgator\backend\.env.example` and Laravel configuration — documented, secret-free PHP/MySQL/cloud-model settings.
- **FILE-005**: PHP AI Notebook services and tests — cloud embeddings, source indexing, retrieval, generation integration, and parity behavior.
- **FILE-006**: PHP upload/storage services and routes — persistent private HostGator files and authorization-preserving retrieval.
- **FILE-007**: PHP feature/unit tests and API compatibility fixtures — route, validation, security, and business-rule parity evidence.
- **FILE-008**: `D:\claude\nexus_sbi_hostgator\web\` and static-host `.htaccess` configuration — existing React build, API origin, and SPA deep-link routing.
- **FILE-009**: `D:\claude\nexus_sbi_hostgator\README.md` and backend deployment documentation — HostGator PHP/MySQL setup, migration, storage, model, and rollback instructions.
- **FILE-010**: `D:\claude\nexus_sbi_hostgator\plan\infrastructure-hostgator-business-1.md` — this plan; update it only when account capability decisions are confirmed or execution status changes.
- **FILE-011**: `D:\claude\nexus_sbi\` — read-only source tree; no files may be changed.

## 6. Testing

- **TEST-001**: Verify every file copied into the target is source/configuration required for build or deployment, and verify the source tree’s file hashes are unchanged after all work.
- **TEST-002**: Verify PHP syntax, Composer dependency resolution, Laravel route/config checks, PHP unit/feature tests, and the React typecheck/production build pass. No production HostGator process may require Node.js.
- **TEST-003**: On a fresh disposable MySQL database matching the confirmed HostGator version, verify all Laravel migrations complete, expected schema objects exist, no sample/business data is present, and runtime schema mutation is disabled.
- **TEST-004**: Verify cloud embedding calls use the configured server-side endpoint/model and key; test authentication failure, timeout, malformed vectors, incompatible dimensions, indexing, query retrieval, and the existing lexical fallback.
- **TEST-005**: Verify stored uploads resolve beneath the configured persistent directory outside the web root; test authorization on download, upload-size handling, and safe error reporting when disk capacity or permissions prevent writes.
- **TEST-006**: Verify the PHP API runs through the cPanel PHP/Apache request lifecycle and returns actionable server-side errors in protected logs without requiring a persistent Node.js listener.
- **TEST-007**: Verify the React production build serves `/admin/login` and other deep links through the SPA fallback while `.htaccess` routes API requests to PHP and enforces the correct HTTPS/CORS policy.
- **TEST-008**: Verify no production secrets, `.env` files, private keys, database dumps, copied uploads, or sample-seed output are present in the target deliverable.

## 7. Risks & Assumptions

- **RISK-001**: The supplied plan capability list does not include Node.js, so the existing NestJS API cannot run as-is on the stated shared-hosting target. Attempting to deploy it as a PHP application or relying on undocumented Node support is unsupported.
- **RISK-002**: Reimplementing the NestJS backend in PHP/MySQL can omit or subtly change routes, permissions, token rotation, transaction behavior, date/JSON semantics, query behavior, and grading logic. Treat the API compatibility matrix and parity suite as release blockers.
- **RISK-003**: Shared-hosting CPU, memory, process, request-timeout, upload, or outbound-network limits may prevent large document parsing, background indexing, or AI grading from completing reliably.
- **RISK-004**: HostGator disk quotas, inode limits, backups, or account policy may be insufficient for private uploads and generated files; verify available persistent capacity and backup/restore coverage before production use.
- **RISK-005**: The current source documents local Ollama embeddings and cloud Ollama chat. Availability and compatibility of a cloud embedding endpoint/model are unverified; do not substitute a different model without reindexing all sources and verifying vector compatibility.
- **RISK-006**: The source’s R2 integration and local-disk fallback have different persistence and download semantics. Validate HostGator disk storage as the selected source of truth while retaining access control and not exposing private files.
- **ASSUMPTION-001**: The source project is the requested behavior baseline; the target is currently empty and should contain an independent deployment copy.
- **ASSUMPTION-002**: “No data” means no source users, uploads, sample records, database dumps, private keys, or credentials are transferred. Schema migrations may create required database structure but must not import user/business data.
- **ASSUMPTION-003**: PHP 8.2 and MySQL are supported by the user's HostGator Business plan based on the information supplied; exact cPanel version, database release, extensions, and account limits will be confirmed before implementation.
- **ASSUMPTION-004**: Local implementation in the target copy is authorized. Live HostGator configuration, database provisioning, deployment, and cutover are not authorized until account settings are confirmed and the user explicitly requests those operations.

## 8. Related Specifications / Further Reading

- Source overview: `D:\claude\nexus_sbi\README.md`
- Backend setup and current database/AI requirements: `D:\claude\nexus_sbi\backend\README.md`
- Current TypeORM configuration: `D:\claude\nexus_sbi\backend\src\app.module.ts` and `D:\claude\nexus_sbi\backend\src\data-source.ts`
- Current environment template: `D:\claude\nexus_sbi\.env.example`
- Current AI Notebook implementation and tests: `D:\claude\nexus_sbi\backend\src\ai-notebook\ai-notebook.service.ts` and its adjacent `*.spec.ts`
- Current upload storage implementation: `D:\claude\nexus_sbi\backend\src\storage\storage.service.ts`
- HostGator Business plan programming-language and database capabilities: user-supplied HostGator web-hosting information; verify the exact PHP version, extensions, MySQL release, and account limits in cPanel before implementation.

## 9. Execution Status (2026-11-03)

This execution followed the user's approval to begin implementation despite the earlier ASSUMPTION-004. The supplied plan was read first. All writes were confined to `D:\claude\nexus_sbi_hostgator`; the source tree was only read. No baseline file hashes were captured, so TASK-006/TASK-030 hash comparison is not claimed.

| Area | Actual result |
|---|---|
| HostGator facts | PHP and MySQL support, and no supported Node.js runtime, are user-supplied. Exact cPanel PHP version/extensions, database version, Composer/SSH/cron, HTTPS egress, storage and account limits remain unknown. Do not deploy until verified. |
| Isolated copy | Copied the React/Vite source/configuration files (excluding dependencies and generated output). Did not copy NestJS source or source runtime/data; created a new Laravel API. No `.env`, private keys, uploads, database dumps, source records or seeds were copied. |
| Schema | Added ordered Laravel migrations for identity/session tables, audit rows, schools, school years, classes/sections, students, cohort memberships, licenses, subjects, folders, lecture listing fields, folder permissions, student queries, test-authoring columns/folder links/source-file metadata, test-attempt state and private attachment metadata up to 30 MiB/file, minimal tests/attempts columns used by leaderboard aggregation, course/content/progress/quiz/flashcard/lecture-progress tables used by analytics, and the folder-access plan/installment/payment columns used by student hard deletion. User list/create/search use the existing `users`, `devices`, and role-assignment schema; no migration was needed. The folder billing schema remains incomplete. Fee, notebook, complete test/grading, cover storage/serving, lecture files/keys, and most remaining entities are not ported. Complete parity for the source's 44 entities and 49 TypeORM migrations has not been achieved; several tables are only mapped to the subset of fields needed by implemented routes. No database or credentials exist here. |
| API | Added a Laravel 12/PHP 8.2 scaffold and working code for health, five auth endpoints, four team-member endpoints, signed private-file downloads, two school endpoints, five subject endpoints, nine folder endpoints, seven student endpoints, four student-query endpoints, leaderboard, three user endpoints, all eight admin analytics endpoints, test list/create, student test access/request/discovery, and partial question-preview, submit, and resubmit operations. The 94 source controller operations include health: 55 fully implemented total (54 application routes plus health), with 39 incomplete (36 unported and three partial). Team-member, school, subject, folder, student, student-query, users, analytics, test authoring, student access, and submission persistence were source-inspected and feature tests authored. Student scope includes account creation/reset, assignment, archive, and permanent deletion of available linked rows. User tests cover role-gated creation/enumeration, exact email lookup, case-insensitive bounded search, and active-device inclusion. Query ownership/reply permissions, leaderboard privacy/ranking, and analytics filters, response shapes, aggregation, and authorization have authored coverage. Student submission validates answer/file DTOs, question coverage, active folder entitlement and deadline, stores attempt/file metadata transactionally, and writes bytes under protected private storage; PDF page rendering/OCR is not implemented. Assessment/AI grading and the source `my-tests` notification are absent: submission returns the source-shaped accepted state but remains pending, while a valid resubmit fails closed with 503 without changing the attempt. Do not enable student assessments in production until real grading and recovery are implemented. Student file download integration is also incomplete. The preview parser/extraction path remains partial (OCR/full answer-key checks). Source-file upload/serving, review, admin attempts/grading and broader testing routes remain unported. Student test routes use active non-suspended folder permissions and only expose source answers after marks are released; test access notification over SSE is absent. The leaderboard uses minimal test/attempt columns. Analytics tables cover dashboard query dependencies only, not the full feature. Student query revision notifications over SSE are not ported. Optional fee/notebook/query cleanup is conditional on compatible schemas; runtime playback-session revocation is not ported. Multipart cover upload/clear is excluded. No PHP runtime is available to run tests. See `docs/API-COMPATIBILITY.md`. |
| Embeddings | Added a provider-neutral configurable OpenAI-compatible HTTPS client with response/model/dimension checks and tests. The provider/model is not selected or verified; it is not connected to notebook indexing/querying. The source embedding method has no lexical fallback. |
| Storage | Added private-path storage primitives, a user-bound short-lived HMAC download contract, nonce replay prevention, and a protected streaming route. Domain upload/index/download integrations and source key/metadata migration remain incomplete. |
| Frontend/deployment | Added deployment notes and Apache SPA fallback. `npm ci`, Vite typecheck, and production build passed; generated `node_modules`, `dist`, and TypeScript build metadata were removed afterward. |
| Validation | Node/npm are available; PHP and Composer are not. A static delimiter/string/comment scan passed across all 73 target PHP files; the Composer manifest JSON parsed; all 58 route declarations were checked (57 explicit controller-action pairs plus the health invokable controller). The React/Vite production build passed after the submission status copy change; generated dependencies/output were removed afterward. SQLite smoke checks passed for analytics expressions. Laravel/PHP syntax/runtime tests, migrations, live MySQL, and cPanel tests cannot be run without PHP/Composer and a database; the folder, student, student-query/leaderboard, users, analytics, test-authoring, test-access, and test-submission feature tests are authored but unexecuted. No production deployment or initial admin provisioning was attempted. |

This plan is therefore **partially implemented**. The PHP/Composer absence is a concrete blocker to checking migrations and executing domain feature tests. Folder cover file upload/clear, 36 unported operations, and three partial operations (question preview, student submit, student resubmit) remain. Most critically, the source assessment/grading engine, submission recovery/retry worker, optional student hard-delete dependencies, and most of the source schema are not implemented. Student assessments must remain disabled until a real grader is ported. The project does not meet GOAL-003 through GOAL-007 completion criteria, preserve all application features, or authorize a production cutover.
