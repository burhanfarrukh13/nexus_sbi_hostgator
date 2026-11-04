# HostGator deployment notes — tests and AI Notebook scope

This deployment supports authentication/account administration, student queries, analytics for retained tables, standalone subjects, tests, and AI Notebook. Removed school/class/folder/course/lecture/quiz/flashcard workflows are not implemented. See [`API-COMPATIBILITY.md`](API-COMPATIBILITY.md) for the API contract.

## Unverified account prerequisites

The supplied target facts establish PHP (including PHP 8.2) and MySQL support, and no supported Node.js runtime. Exact selectable PHP release, PDO MySQL and required extensions, MySQL release, Composer/SSH, HTTPS/outbound API, cPanel upload/execution limits, cron, and account quotas have not been verified. The deployment target has not been validated against a live HostGator account. Review the remaining parity limits below before production use.

## Intended document roots

- Serve only `backend/public` from the API subdomain's document root (for example `api.example.com`). Keep `backend/.env`, `backend/vendor`, application source, logs, framework cache, and uploads outside the document root when cPanel permits.
- Serve `web/dist` from the static site's document root. It is built off-host; production does not run Node.
- Set build-time `VITE_API_BASE_URL` to the API origin and configure `CORS_ALLOWED_ORIGINS` to the exact HTTPS static origin. Do not use wildcard origins.
- `backend/public/.htaccess` routes API deep paths to Laravel. `web/public/.htaccess` is copied into `web/dist` by Vite and provides static SPA fallback without forwarding API traffic.
- Set `PRIVATE_STORAGE_PATH` to an absolute, writable account path outside both document roots. The PHP implementation refuses a configured path inside the API public root. Do not expose private storage with an Apache alias or symlink.

## Build and initial setup

1. On a PHP 8.2+ build machine with Composer, resolve `backend/composer.json`, review dependencies, and retain the generated `composer.lock`. Package vendor dependencies for deployment if cPanel lacks Composer/SSH.
2. Provision cPanel MySQL; enter its exact database name/user/host/password only in an environment file outside the public root. Ensure `utf8mb4` and InnoDB are supported.
3. Generate an application key and independent random secrets of at least 32 characters for `JWT_ACCESS_SECRET`, `JWT_REFRESH_SECRET`, and `STORAGE_SIGNING_SECRET`. Set `APP_DEBUG=false`, HTTPS `APP_URL`, and a strict CORS allowlist.
4. Configure `OPENROUTER_API_KEY`, `OPENROUTER_EMBEDDINGS_MODEL`, and `OPENROUTER_EMBEDDINGS_DIMENSIONS` for source indexing; set `OPENROUTER_EMBEDDINGS_API_URL` to `https://openrouter.ai/api/v1/embeddings`. A supported starting combination is `openai/text-embedding-3-small` with 1536 dimensions. Configure `OLLAMA_API_KEY`, `OLLAMA_MODEL`, and `OLLAMA_API_URL` for notebook chat and test assessment; the Ollama Cloud OpenAI-compatible endpoint is `https://ollama.com/v1/chat/completions`. Use a vision-capable Ollama model for scanned/photo answers. Do not use the native `/api/chat` URL with this client. These settings are backend-only; do not put keys in frontend build variables. Ollama running on a personal computer's `localhost` is not reachable by HostGator.
5. Initialize a **fresh, empty** database using exactly one method: import `database.sql` in phpMyAdmin, or run Laravel migrations. Select the intended database before importing; the SQL file neither selects nor creates a database. The SQL import drops and recreates its declared tables, including `users`, and does not create Laravel's `migrations` bookkeeping table. **Do not run `php artisan migrate` after importing the SQL**; Laravel will try to create the same tables. If using migrations, run them against a fresh database and allow Laravel to create its bookkeeping table. Back up before replacing any existing schema. Do not run seeders or schema synchronization.
6. After configuring the backend environment and database, create the initial admin with the interactive `php artisan nexus:admin-create` command. It asks for a password without echo and does not accept a password as a command-line argument. Importing the SQL again drops/recreates users, so create the admin only after the final import.
7. Ensure Laravel's `storage/framework` and log directories are writable by PHP, but not publicly reachable. Configure cPanel backups for both database and private upload directories and test restore.

For an **existing** database, do not import `database.sql`. Back up first, then import [`../database-updates/student-subject-access.sql`](../database-updates/student-subject-access.sql) and [`../database-updates/student-queries-notebook-preferences.sql`](../database-updates/student-queries-notebook-preferences.sql) in phpMyAdmin to add the new features without replacing existing records. The latter also adds the configurable test result-release delay (default 24 hours). The clean-install schema has no folder workflow or `default_price` column. To remove legacy folder tables from an existing database, use [`../database-updates/remove-legacy-folder-workflow.sql`](../database-updates/remove-legacy-folder-workflow.sql) only after backing up and confirming that their data is no longer needed; it permanently deletes those tables.

After correcting OpenRouter settings, re-embed source chunks that were previously stored with lexical search only by running `php artisan ai-notebook:reindex-embeddings` from the backend directory. You may target one source with `--source=<source-uuid>`. The command does not change uploaded files or other database records.

## Verify AI features

1. In `backend`, run `php artisan config:clear` after changing provider environment variables. Confirm the Ollama URL is `https://ollama.com/v1/chat/completions`, the OpenRouter embedding URL is `https://openrouter.ai/api/v1/embeddings`, and the embedding model/dimension pair is `openai/text-embedding-3-small` / `1536`.
2. To verify OpenRouter and update sources that were previously stored as lexical-only, run `php artisan ai-notebook:reindex-embeddings`. This sends source text chunks to OpenRouter and may incur provider usage charges. To limit it to one source, get its UUID from the `ai_notebook_sources` table and pass `--source=<uuid>`.
3. In the Notebook, upload a small text/PDF source to a subject, wait for its status to show `ready`, and ask a question answered by that source. This verifies embedding retrieval and Ollama chat together; source upload fails visibly if embeddings are not configured.
4. To verify grading, set the result-release delay to `0` in the admin **Tests & Marks → Settings** tab. Create a test with an ideal answer and complete marking scheme. Submit typed text to confirm synchronous grading. For a photo or scanned PDF, the confirmation says grading is queued; configure the cron below and the student page will check for completion. If grading fails, open that test's admin attempt review, inspect the recorded error, and use **Retry assessment** after fixing the provider or rubric. Restore the desired result-release delay afterward.

### Queued image grading cron

Image and scanned-paper grading runs outside the student web request to avoid shared-host request timeouts. In cPanel **Cron Jobs**, add a job to run every minute, using the PHP CLI executable and backend path shown for your account:

```bash
cd /home/CPANEL_USER/path/to/backend && /usr/local/bin/php artisan ai-tests:grade-pending --limit=1 >> /home/CPANEL_USER/logs/test-grading.log 2>&1
```

Replace `CPANEL_USER`, backend/log paths, and the PHP executable with your account's actual paths. The command processes at most one queued image submission per run; a failed provider call is recorded on the attempt for administrator review and retry. Confirm the cron can access the configured `.env`, database, private upload directory, and outbound Ollama HTTPS endpoint. The browser renders answer PDFs as JPEG pages before upload (up to 30 pages); no server-side PDF renderer is required.

New student accounts are provisioned with the required initial password `abc@123` and forced to change it at first login. Tell each student privately; do not reuse it for admin accounts.

## Deployment helper included in the workspace

The HostGator copy includes a packaging helper so the app is prepared as a deployment bundle without modifying the original source project.

PowerShell:

```powershell
cd D:\claude\nexus_sbi_hostgator\scripts
.\prepare-hostgator-package.ps1
```

Bash:

```bash
cd /path/to/nexus_sbi_hostgator/scripts
./prepare-hostgator-package.sh
```

This helper packages the Laravel API plus the static front-end `web/dist` build into a zip bundle. The live deployment still requires a real HostGator Business account to confirm PHP 8.2+, MySQL, cPanel paths, and account limits.

## Limits, logging, and rollback

Set PHP `upload_max_filesize`, `post_max_size`, memory/time limits, and cPanel limits only after comparing application requirements with account policy. AI Notebook ingestion supports PDF, DOCX, and TXT sources; test-submission uploads have a 30 MiB total limit. AI Notebook chat, indexing, and automatic grading require valid cloud model credentials in the backend environment. PHP/shared-hosting request-time limits may affect document processing.

Typed/selectable-text answers are graded synchronously; image submissions are queued for the cPanel cron. Marks are hidden from students until the administrator-configured release delay passes (24 hours by default). Admins can retry failed provider calls from the attempt-review screen. Use HTTPS only. Keep PHP errors out of responses (`APP_DEBUG=false`) and review protected Laravel logs for redaction. Avoid logging tokens, credentials, or document bodies. Rollback by restoring the prior static build and compatible PHP release; database rollback requires a tested backup because schema downgrades may be destructive.
