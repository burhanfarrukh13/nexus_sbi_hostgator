# Nexus SBI — HostGator build

This isolated copy targets PHP/Laravel and MySQL on HostGator Business. It retains authentication/account administration, standalone subjects, tests, and AI Notebook. The React/Vite frontend is built as static files; production does not require Node.js. PDF test submissions are rendered to page images in the browser and graded asynchronously by the configured vision-capable OpenAI-compatible model using a cPanel cron job. Changes belong only in `D:\claude\nexus_sbi_hostgator`; the original `D:\claude\nexus_sbi` remains untouched.

The intentionally removed features and current route contracts are listed in [`docs/API-COMPATIBILITY.md`](docs/API-COMPATIBILITY.md). Test submissions are saved immediately and graded by a background worker that a cPanel cron job starts every 15 minutes (photos and PDF pages always; typed answers too unless `GRADING_QUEUE_TYPED=false`). No completion-time estimate is shown until staging measurements are available. Results are released immediately by default (`result_release_delay_hours` is 0); administrators can configure a 0-720 hour delay. Notebook chat/grading and indexing require configured cloud APIs. Chat uses the OpenRouter-compatible endpoint `https://openrouter.ai/api/v1/chat/completions`; Notebook embeddings use `openai/text-embedding-3-small` with 1536 dimensions. The current backend reads chat settings from `OLLAMA_API_URL`, `OLLAMA_API_KEY`, and `OLLAMA_MODEL` for backward compatibility. Do not copy API keys into the frontend. See [`docs/PLAN2-VERIFICATION.md`](docs/PLAN2-VERIFICATION.md) for local and staging checks.

## Database

The schema is in `database.sql`; it creates only the authentication, audit, subject, test/attempt/file, AI Notebook, and grading-settings tables. It contains no production records, sample users, or initial admin. The import script drops and recreates its declared tables, including `users`; back up first because it erases existing accounts and application data in those tables.

For Laragon/phpMyAdmin:

1. Create/select an empty database such as `nexus_sbi_local`.
2. Select that database, then import `database.sql`.
3. Configure `backend/.env` for the same database.
4. Configure the provider credentials and model settings in `backend/.env`; after changing them run `php artisan config:clear`. Existing Notebook sources uploaded before embedding credentials were ready need one-time reindexing with `php artisan ai-notebook:reindex-embeddings`.
5. Do **not** run `php artisan migrate` after the SQL import: Laravel will see no migration-history table and try to create tables that already exist. On a fresh database, choose either the SQL import or `php artisan migrate`, not both.
6. Create the first admin with `php artisan nexus:admin-create`. It is interactive: it asks for the admin email, then the password twice (hidden, minimum 12 characters), takes no arguments, and refuses to run if an admin already exists. Run it after backend dependencies and the environment are configured, from a terminal (not a cron job).

For an existing database, apply `database-updates/ai-notebook-chat-queue.sql` once to add persistent Notebook chat tickets. Apply `database-updates/team-member-subject-access.sql` once to enable subject scopes for administrative team members. This update adds only the `team_member_subject_access` table; existing team accounts with no rows in it retain their current unrestricted subject access. Apply `database-updates/test-lifecycle.sql` once to add test archiving support before deploying the updated API. It adds `tests.archived_at`; existing tests remain active. If the daily question setting has not already been added, also apply `database-updates/ai-notebook-question-limit.sql`. If the database predates the grading queue (no `test_attempts.grading_attempts` column), apply `database-updates/grading-queue-upgrade.sql` as well. Back up first and apply each required additive update only once; do not re-import `database.sql` over production data.

To apply an additive update in phpMyAdmin: select the existing application database, open **Import**, choose the required file in `database-updates`, and run it once. Alternatively, open the SQL tab and execute its statement. For the test lifecycle update, verify `tests.archived_at` does not already exist before executing the `ALTER TABLE` statement. Do not execute any additive update more than once.

Administrators and test-authoring team members can edit a test title or choose **Delete onwards** to archive the test. Archived tests are unavailable for new/current student access and submissions, while students with submitted attempts retain their history and reports. **Permanently delete** removes the test, its attempts/reports, and associated private source/submission files; this cannot be undone. The archive field is included in `database.sql` and the Laravel migration for fresh installs.

Administrator access tokens and refresh tokens are stored in the current browser tab's session storage and are cleared when that tab is closed. Student sessions remain in local storage and continue to refresh across tabs.

`database.sql` does not select/create the database. If switching databases, import only into the intended empty target and use the explicit admin-creation command; never copy database contents from the original project.

## Local checks

Backend, from `backend` (PHP 8.2+, Composer, and PDO SQLite/MySQL required):

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
vendor\bin\phpunit
```

Frontend, from `web` (Node.js is needed only for development/build):

```powershell
npm ci
$env:VITE_API_BASE_URL = 'http://localhost:8000'
npm run typecheck
npm run build
```

For local development, serve `backend/public` as the API document root and `web/dist` as the static site; run the grader with `php artisan schedule:work`. Review [`docs/HOSTGATOR-DEPLOYMENT.md`](docs/HOSTGATOR-DEPLOYMENT.md) before deployment. HostGator's actual PHP extensions, MySQL version, HTTPS egress, storage limits, and account configuration have not been verified.

## Packaging for HostGator

```powershell
.\scripts\prepare-hostgator-package.ps1 -ApiUrl https://api.yourdomain.com
```

(`scripts/prepare-hostgator-package.sh <api-url>` does the same on Linux/macOS.) The API URL is required. The script builds `artifacts/nexus-api.zip` (Laravel API from an explicit allow-list, `composer install --no-dev --optimize-autoloader`, fresh `bootstrap/cache`) and `artifacts/nexus-web.zip` (static site built against that URL), and prints a checklist confirming that `.env`, private storage, logs, tests, and other local files are not included. Build machine: PHP 8.2+, Composer, Node.js. Create the production `.env` on the server; never ship the local one.
