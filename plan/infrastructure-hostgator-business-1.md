---
goal: Host the scoped Nexus SBI application on HostGator Business
version: 3.0
date_created: 2026-11-03
last_updated: 2026-11-04
owner: User
status: In progress
tags: [hostgator, php, laravel, mysql, tests, ai-notebook]
---

# HostGator Business implementation plan

## Revised scope

The user explicitly narrowed the HostGator copy to authentication/account administration, standalone subjects, tests, and AI Notebook. This approved scope replaces the former full-source parity requirement. All work is isolated to `D:\claude\nexus_sbi_hostgator`; `D:\claude\nexus_sbi` remains read-only. No application records, user uploads, credentials, or source database data are copied.

Retained MySQL entities: `users`, `user_role_assignments`, `audit_logs`, `student_queries`, `subjects`, `subject_user_access`, `tests`, `test_attempts`, `test_source_files`, `test_attempt_files`, `ai_notebook_sources`, `ai_notebook_chunks`, `ai_notebook_chat_sessions`, `ai_notebook_chat_messages`, `ai_notebook_prompts`, `ai_notebook_user_prompts`, `ai_notebook_daily_usage`, and `ai_grading_settings`.

The user-designated school/content/billing tables and their workflows are removed: classes, class subjects, courses, devices, flashcards/reviews, folders and folder access plans/installments/payments, grids, lectures/progress, licenses, migration history, mock exams, legacy questions, quiz attempts/answers, schools/school years, sections, subject teachers, topics/progress. Tests keep their question definitions as JSON within `tests`; they do not depend on the removed legacy `questions` table.

## Requirements and decisions

- **REQ-001 — Isolation:** Edit only the HostGator directory. Do not copy data or credentials from the source project.
- **REQ-002 — Runtime:** Use PHP 8.2+/Laravel and MySQL. Compile the React/Vite SPA off-host and deploy static files; Node.js is not a production requirement.
- **REQ-003 — Database:** Keep runtime records and uploaded files on the target hosting account. Use a cloud API for embeddings and chat; keys remain backend-only.
- **REQ-004 — Scope:** Support auth/account administration, student phone/CRN, per-student subject grants, student support queries, analytics for retained tables, subjects, tests, and AI Notebook. Do not expose removed school hierarchy, folder permissions, leaderboard, course, lecture, quiz, flashcard, or device-registration operations.
- **REQ-005 — Clean install:** Create schema only, with no imported user or business records. Create the initial admin separately after the final schema install.
- **DEC-001 — Schema initialization:** Choose either `database.sql` import or Laravel migrations on a fresh database. Do not run `artisan migrate` after SQL import because the SQL intentionally omits Laravel migration bookkeeping.
- **DEC-002 — Access model:** Student test and notebook access is restricted by `subject_user_access`; tests and notebook content use `subject_id`. No legacy folder or school-specific authorization is retained.
- **DEC-003 — Test assessment:** Readable answer text is assessed synchronously with Ollama and validated against the authored marking criteria. Scores are stored immediately and hidden from students until the configurable result-release time (24 hours by default); image-only answers require manual review, and administrators can retry or edit marks.
- **DEC-004 — HostGator verification:** The supplied PHP/MySQL capability is user-provided; exact cPanel PHP version/extensions, MySQL version, outbound HTTPS, limits, and deployment paths remain unverified.

## Implementation and verification

| Item | Status | Evidence / remaining work |
|---|---|---|
| Isolated PHP/MySQL application | Implemented | Laravel API, static React frontend, HostGator packaging/deployment documentation are in the target directory. No source-tree edits were made by this work; no before/after source hash baseline was captured. |
| Reduced database schema | Implemented | The clean SQL and consolidated Laravel migration define the 18 retained tables, including `subject_user_access` and `student_queries`; both omit the legacy folder table and `default_price` column. A full disposable clean-import parity run remains pending after these additions. |
| Student accounts and subject grants | Implemented | Admins and `user_manager` can create students with phone, CRN, and optional subject grants, then manage grants in **Users**. New students receive `abc@123` and must change it at first login. |
| Student support and admin analytics | Implemented | Students can open support queries; admins with `student_query` permission can reply. Admins and `testing_manager` can see analytics for the retained tables. |
| Notebook preferences | Implemented | Students can set their own response language and prompt for accessible subjects; settings do not alter the subject default. |
| AI providers | Implemented, requires credentials | Notebook chat and text-answer assessment use Ollama Cloud; source indexing uses OpenRouter embeddings. Test scores are validated against rubric caps before storage. Verify credentials and the selected OpenRouter model's dimensions before indexing. |
| Removed backend workflows | Implemented | Obsolete school/student-roster/folder/leaderboard controllers/services and routes were removed. Test and Notebook services use standalone subjects and `users` directly. |
| Frontend routes and contracts | Implemented | Notebook uses subject endpoints and `subjectId`; test authoring/listing uses standalone subjects. Removed pages are not present in the navigation. |
| Backend checks | Passed | PHPUnit: 34 tests, 262 assertions, including score validation, delayed student result visibility, admin mark edits, support-query privacy/replies, analytics, preferences, and provider clients. |
| Frontend checks | Passed | `npm run build` completed TypeScript checking and Vite production bundling with the test result-release and retry UI. |
| Existing Laragon schema update | Applied | In `nexus_database`, added `subject_user_access`, confirmed the legacy `folders` table had zero rows and no foreign-key dependents before dropping it, and added `ai_grading_settings.result_release_delay_hours` with default 24. Existing records remain. |
| Cloud AI configuration | Implemented, requires credentials | Cloud embedding/chat adapters are configured through backend settings. Verify selected provider, dimensions, access and network egress in the target environment. |
| Initial administrator | Available, not provisioned | Run `php artisan nexus:admin-create` only after final schema installation and backend environment configuration. |
| HostGator production readiness | Pending | Verify account PHP/extensions, MySQL, TLS/outbound APIs, private storage, upload/runtime quotas, Apache document roots, and operational backups with the actual HostGator account. |

## Local database procedure

1. Make a backup if the selected database contains anything that must be retained. The SQL import drops and recreates its declared application tables, including `users`.
2. Create/select a new empty database in Laragon/phpMyAdmin.
3. Import `database.sql` into that selected database, or choose Laravel migrations instead—never both on the same fresh database.
4. Configure `backend/.env` to the same database and validate the API connection.
5. After the final import/migration, create the initial admin with the interactive `php artisan nexus:admin-create` command.
6. Configure cloud embedding and chat settings in backend environment variables; never expose keys in `VITE_*` settings.
7. For an existing installation, use the additive [`../database-updates/student-subject-access.sql`](../database-updates/student-subject-access.sql) and [`../database-updates/student-queries-notebook-preferences.sql`](../database-updates/student-queries-notebook-preferences.sql) rather than importing `database.sql`. The optional [`../database-updates/remove-legacy-folder-workflow.sql`](../database-updates/remove-legacy-folder-workflow.sql) permanently removes legacy folder tables.

The SQL file does not create/select a database and does not create a `migrations` table. Re-importing it resets its declared tables and erases their contents.

## Remaining release gates

- [ ] Re-import the latest 17-table `database.sql` into a disposable MySQL database and compare its full schema with Laravel migrations; earlier 16-table parity results are superseded by `subject_user_access`.
- [x] Run current PHPUnit feature coverage (25 tests, 187 assertions) and frontend production build.
- [x] Apply additive student-access and legacy-folder cleanup to the existing Laragon database after verifying the folder table was empty and had no foreign-key dependents.
- [ ] Confirm the original source directory remains unchanged using an available baseline or trusted backup comparison; no baseline hash was captured in this execution.
- [ ] Verify HostGator account prerequisites and deploy to production only after the user separately approves it.

## Known limitations

- Automatic test grading and grading-worker recovery are unavailable; submission persistence is not proof of grading.
- AI Notebook requires valid cloud embedding and chat API credentials, matching model dimensions, and outbound HTTPS.
- Actual HostGator runtime and service limits have not been tested.
- SQL import is destructive to the declared application tables; preserve data with a backup and use a fresh database for clean installation.
