# HostGator deployment notes — tests and AI Notebook scope

This deployment supports authentication/account administration, student queries, analytics for retained tables, standalone subjects, tests, and AI Notebook. Removed school/class/folder/course/lecture/quiz/flashcard workflows are not implemented. See [`API-COMPATIBILITY.md`](API-COMPATIBILITY.md) for the API contract.

Local test commands, manual browser checks, staging/load gates, and currently pending external checks are tracked in [`PLAN2-VERIFICATION.md`](PLAN2-VERIFICATION.md).

## Unverified account prerequisites

The supplied target facts establish PHP (including PHP 8.2) and MySQL support, and no supported Node.js runtime. Exact selectable PHP release, PDO MySQL and required extensions, MySQL release, Composer/SSH, HTTPS/outbound API, cPanel upload/execution limits, cron, and account quotas have not been verified. The deployment target has not been validated against a live HostGator account. Review the remaining parity limits below before production use.

## Intended document roots

- Serve only `backend/public` from the API subdomain's document root (for example `api.example.com`). Extract the API archive **outside** any web root (for example `/home/ACCOUNT/nexus-api`) so `.env`, `vendor`, application source, logs, framework cache, and uploads are not reachable from the web.
- Serve the contents of the static-site archive (`web/dist`) from the static site's document root. It is built off-host; production does not run Node.
- The API origin is compiled into the static build (the packaging script takes it as a required parameter). Set `CORS_ALLOWED_ORIGINS` to the exact HTTPS static origin. Do not use wildcard origins.
- `backend/public/.htaccess` routes API deep paths to Laravel. `web/public/.htaccess` is copied into `web/dist` by Vite and provides the static SPA fallback, HTTPS redirect, security headers, and the `.mjs` MIME type the PDF.js worker needs; it does not forward API traffic.
- Set `PRIVATE_STORAGE_PATH` to an absolute, writable account path outside both document roots (for example `/home/ACCOUNT/private/nexus`). The PHP implementation refuses a configured path inside the API public root. Do not expose private storage with an Apache alias or symlink.

## Build and initial setup

1. On the build machine (PHP 8.2+, Composer, Node.js) run the packaging helper described under [Deployment helper](#deployment-helper-included-in-the-workspace). It runs `composer install --no-dev --optimize-autoloader` and regenerates `bootstrap/cache` itself, so the HostGator account does not need Composer or SSH just to receive `vendor/`. Retain the generated `composer.lock` and review dependency changes before packaging.
2. Provision cPanel MySQL; enter its exact database name/user/host/password only in the production environment file outside the public root. Ensure `utf8mb4` and InnoDB are supported.
3. Create the production `.env` on the server from `.env.example`. Do not reuse the local file. Set `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY`, HTTPS `APP_URL`, independent random secrets of at least 32 characters for `JWT_ACCESS_SECRET`, `JWT_REFRESH_SECRET`, and `STORAGE_SIGNING_SECRET`, the real database credentials, `PRIVATE_STORAGE_PATH`, and a strict CORS allowlist.
4. Configure `OPENROUTER_API_KEY`, `OPENROUTER_EMBEDDINGS_MODEL`, and `OPENROUTER_EMBEDDINGS_DIMENSIONS` for source indexing; set `OPENROUTER_EMBEDDINGS_API_URL` to `https://openrouter.ai/api/v1/embeddings`. A supported starting combination is `openai/text-embedding-3-small` with 1536 dimensions. Configure `OLLAMA_API_KEY`, `OLLAMA_MODEL`, and `OLLAMA_API_URL` for notebook chat and test assessment; the Ollama Cloud OpenAI-compatible endpoint is `https://ollama.com/v1/chat/completions`. Use a vision-capable Ollama model for scanned/photo answers. Do not use the native `/api/chat` URL with this client. These settings are backend-only; do not put keys in frontend build variables. Ollama running on a personal computer's `localhost` is not reachable by HostGator.
5. Initialize a **fresh, empty** database using exactly one method: import `database.sql` in phpMyAdmin, or run Laravel migrations. Select the intended database before importing; the SQL file neither selects nor creates a database. The SQL import drops and recreates its declared tables, including `users`, and does not create Laravel's `migrations` bookkeeping table. **Do not run `php artisan migrate` after importing the SQL**; Laravel will try to create the same tables. If using migrations, run them against a fresh database and allow Laravel to create its bookkeeping table. Back up before replacing any existing schema. Do not run seeders or schema synchronization. The base migration defines the core schema; the later chat-ticket migration adds `ai_notebook_chat_tickets`. Keep both migrations and `database.sql` in step.
6. After configuring the backend environment and database, create the initial admin with the interactive `php artisan nexus:admin-create` command. It takes no arguments or options: it prompts for the administrator email, then for the password twice (hidden; at least 12 characters), and it never accepts a password on the command line. It refuses to run if an administrator already exists, so it only creates the first account. It needs a real terminal (SSH or cPanel **Terminal**); a cron job cannot answer the prompts. Importing the SQL again drops/recreates users, so create the admin only after the final import.

   Without any terminal, generate a bcrypt hash on your own computer (`php -r "echo password_hash('A-LONG-PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"`) and run this once in phpMyAdmin against the empty database, using a lowercase email:

   ```sql
   SET @id = UUID();
   INSERT INTO `users` (`id`, `email`, `password`, `role`, `refreshCounter`, `must_reset_password`)
     VALUES (@id, 'admin@yourdomain.com', '<bcrypt hash>', 'admin', 0, 0);
   INSERT INTO `user_role_assignments` (`userId`, `granteeId`, `permission`) VALUES (@id, @id, 'admin');
   ```
7. Ensure Laravel's `storage/framework` and log directories are writable by PHP, but not publicly reachable. Configure cPanel backups for both database and private upload directories and test restore.

For an **existing** database, do not import `database.sql`. Back up first, then apply only the additive scripts whose changes are not already present: [`../database-updates/student-subject-access.sql`](../database-updates/student-subject-access.sql), [`../database-updates/student-queries-notebook-preferences.sql`](../database-updates/student-queries-notebook-preferences.sql), [`../database-updates/ai-notebook-question-limit.sql`](../database-updates/ai-notebook-question-limit.sql), [`../database-updates/ai-notebook-chat-queue.sql`](../database-updates/ai-notebook-chat-queue.sql), and [`../database-updates/test-submission-recovery.sql`](../database-updates/test-submission-recovery.sql). The chat-queue script adds private-ticket metadata; the submission-recovery script adds draft and idempotency columns. Inspect each script and compare the current schema before running it; do not run a script twice. The question-limit script adds the default 15-question setting and widens the usage-count constraint. The student-preferences script sets result-release delay to 0 hours to match the original immediate-results workflow. If the database predates the grading queue (no `test_attempts.grading_attempts` column), also import [`../database-updates/grading-queue-upgrade.sql`](../database-updates/grading-queue-upgrade.sql) once; it must not be run on a database created from the current `database.sql`. The clean-install schema has no folder workflow or `default_price` column. To remove legacy folder tables from an existing database, use [`../database-updates/remove-legacy-folder-workflow.sql`](../database-updates/remove-legacy-folder-workflow.sql) only after backing up and confirming that their data is no longer needed; it permanently deletes those tables.

The AI Notebook daily limit is configurable in Tests & Marks > Settings (default 15, allowed 1–1,000). The reset timezone defaults to `Asia/Karachi`; set `AI_NOTEBOOK_DAY_RESET_TIMEZONE` in the backend environment to override it. Provider credentials remain environment-only. Settings and grading-queue operational APIs require the `admin` permission.

After correcting OpenRouter settings, re-embed source chunks that were previously stored with lexical search only by running `php artisan ai-notebook:reindex-embeddings` from the backend directory. You may target one source with `--source=<source-uuid>`. The command does not change uploaded files or other database records.

## Verify AI features

1. In `backend`, run `php artisan config:clear` after changing provider environment variables. Confirm the Ollama URL is `https://ollama.com/v1/chat/completions`, the OpenRouter embedding URL is `https://openrouter.ai/api/v1/embeddings`, and the embedding model/dimension pair is `openai/text-embedding-3-small` / `1536`. `OLLAMA_CHAT_TIMEOUT_SECONDS` defaults to 60 seconds and `OLLAMA_CHAT_MAX_TOKENS` to 2048 for Notebook chat; these Notebook-only limits do not alter assessment or transcription requests. Grading uses the separate `OLLAMA_TIMEOUT_SECONDS` (default 300 seconds). For server-side PDF page rendering, make Imagick with PDF support or `pdftoppm` available to the PHP account and set `PDFTOPPM_BINARY` if the executable is not on PATH; without either capability, text-layer PDFs still work, but scanned PDFs require manual follow-up.
2. To verify OpenRouter and update sources that were previously stored as lexical-only, run `php artisan ai-notebook:reindex-embeddings`. This sends source text chunks to OpenRouter and may incur provider usage charges. To limit it to one source, get its UUID from the `ai_notebook_sources` table and pass `--source=<uuid>`.
3. In the Notebook, upload a small text/PDF source to a subject, wait for its status to show `ready`, and ask a question answered by that source. This verifies embedding retrieval and Ollama chat together; source upload fails visibly if embeddings are not configured.
4. To verify grading, create a test with an ideal answer and complete marking scheme. Submit a typed answer, a photo, and a multi-page PDF. Confirmation says the work is saved and the result will appear in My Tests; no unmeasured completion time is promised. Once the cron below has run, the student page shows the result, released immediately by default. Provider outages are retried by the worker with increasing delays; non-provider failures remain available for administrator review and retry. Student status responses do not expose provider details or an internal `FAILED` state.

### Background grading cron (every 15 minutes)

Test submissions are saved immediately and graded outside the student's web request to avoid shared-host request timeouts. Photos and PDF pages are always queued, and typed answers are queued too unless `GRADING_QUEUE_TYPED=false`. HostGator's shortest cron interval is 15 minutes, so `ai-tests:grade-pending` is built to fit it: each run keeps draining the queue for `GRADING_RUN_SECONDS` (default 780, about 13 minutes), stops starting new papers once that time is nearly used, and exits before the next tick. At most `GRADING_WORKERS` (default 2) runs grade at the same time; a run that starts while every slot is busy exits immediately.

In cPanel **Cron Jobs**, use the PHP CLI for the version selected in **MultiPHP Manager**. On cPanel servers this is normally `/opt/cpanel/ea-php82/root/usr/bin/php` for PHP 8.2; confirm the exact path in your account, because the plain `php` on shared hosting may be an older release. Create the log folder first.

Option A — call the grader directly (recommended; no dependence on the scheduler spawning a child process):

```bash
*/15 * * * * cd /home/CPANEL_USER/nexus-api && /opt/cpanel/ea-php82/root/usr/bin/php artisan ai-tests:grade-pending >> /home/CPANEL_USER/logs/nexus-grading.log 2>&1
```

Option B — run Laravel's scheduler, which is registered in `routes/console.php` as `ai-tests:grade-pending` with `withoutOverlapping()`:

```bash
*/15 * * * * cd /home/CPANEL_USER/nexus-api && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> /home/CPANEL_USER/logs/nexus-schedule.log 2>&1
```

For a second parallel worker with Option A, add one more line offset by about seven minutes (`GRADING_WORKERS` caps the total):

```bash
7,22,37,52 * * * * cd /home/CPANEL_USER/nexus-api && /opt/cpanel/ea-php82/root/usr/bin/php artisan ai-tests:grade-pending >> /home/CPANEL_USER/logs/nexus-grading.log 2>&1
```

Locally, `php artisan schedule:work` runs the same schedule every minute.

Behaviour to know:

- **Stale recovery.** At the start of each run, attempts stuck in `PROCESSING` for longer than `GRADING_STALE_MINUTES` (default 45; allowed 10-180) are treated as crashed and returned to the queue, or marked `FAILED` once they have used up their tries. Attempts still waiting in `SUBMITTED` are never failed.
- **Retries.** A transient provider error is requeued with a 5/15/30/60-minute backoff (then hourly); provider outages do not consume a fixed retry budget. Malformed tests or other non-provider failures are not retried automatically and need administrator review. The provider exception text is not exposed through the student status endpoint.
- **Capacity.** A paper with several pages can take several minutes to transcribe and grade, so one run per worker grades only a handful of papers. When a large class submits at once the queue takes several ticks to clear and students keep seeing "queued" in the meantime. Options: accept the delay; call the grader more often from an external pinger such as cron-job.org (this needs a token-protected URL, which this build does not include); or move to a VPS or plan with a one-minute cron. Administrators can use **Retry assessment** on an individual attempt as a manual fallback.
- **Checks.** Confirm the cron can read the `.env`, reach the database, write the private upload directory, use the PDF renderer, and open outbound HTTPS to `ollama.com`. The browser may render answer PDFs as JPEG pages before upload (up to 30 pages), but direct API submissions are also rendered server-side when the renderer is installed.

New student accounts are provisioned with the required initial password `abc@123` and forced to change it at first login. Tell each student privately; do not reuse it for admin accounts.

## Deployment helper included in the workspace

The packaging helper builds two separate archives from an explicit allow-list, so nothing is copied wholesale from `backend/`.

PowerShell (build machine needs PHP 8.2+ and Composer on `PATH`, plus Node.js):

```powershell
cd D:\claude\nexus_sbi_hostgator\scripts
.\prepare-hostgator-package.ps1 -ApiUrl https://api.yourdomain.com
```

Bash:

```bash
cd /path/to/nexus_sbi_hostgator/scripts
./prepare-hostgator-package.sh https://api.yourdomain.com
```

The API URL is required, must be an HTTPS origin without a path (no `/api`), and must not be a local or placeholder address; the build also fails if the compiled bundle still contains a local address or does not contain the URL you gave. Useful options: `-OutputDir` / second argument for the output folder (default `artifacts/`), `-ComposerPath` / `COMPOSER=` when Composer is not on `PATH`, `-SkipWebBuild` / `SKIP_WEB_BUILD=1` to reuse an existing `web/dist` built for the same URL, and `-IgnorePlatformReqs` / `IGNORE_PLATFORM_REQS=1` only if the build machine lacks PHP extensions that HostGator has.

Outputs in `artifacts/`:

- `nexus-api.zip` — the Laravel API with a production-only `vendor/` (`composer install --no-dev --optimize-autoloader`) and a freshly generated `bootstrap/cache`. Extract it outside the web root and point the API document root at its `public/` folder.
- `nexus-web.zip` — the static site, with the CSP `connect-src` narrowed to your API origin. Extract it into the static site's document root.
- `PACKAGE-CHECKLIST.txt` — the printed checklist, which lists every excluded item with `[OK]` or `[FAIL]`. If any excluded file would be included, or a required file is missing, no archives are created.

For an update, deploy **both** archives from the same build: replace the website files from `nexus-web.zip`, and update the Laravel application from `nexus-api.zip`. Keep the existing server-side `.env`, database, and private upload directory; the API archive intentionally does not contain them. The browser and API need matching versions for answer-page preview and grading behavior.

Never shipped: `backend/.env` and other `.env*` files (only `.env.example`), `storage/private/*` (real student papers), `storage/logs/*`, `storage/framework/testing` and cached framework data, `.phpunit.result.cache`, `route-list-folders.json`, `.freebuff`, `tests/`, `phpunit.xml`, dev packages, source maps, and `_redirects`. Production secrets are created on the server, never in the package. The live deployment still requires a real HostGator Business account to confirm PHP 8.2+, MySQL, cPanel paths, and account limits.

## Go-live runbook (cPanel steps, in order)

These steps need the real HostGator account, so they are done by you in cPanel; nothing here can be done from the build machine. Tick each one off.

1. **PHP.** cPanel > MultiPHP Manager: set the API domain to PHP 8.2 or newer. In MultiPHP INI Editor / Select PHP Version, enable the extensions in `backend/composer.json` (at least `openssl`, `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `xml`, `zip`). Set `max_file_uploads` to at least 31 so the original PDF and its rendered pages can be uploaded together. Keep `post_max_size` above 30 MiB; the original PDF and all rendered pages share a 30 MiB combined request limit. The site sends multiple files using PHP array field names (`files[]`), and the admin download list exposes the preserved original PDF while page images remain available in the page preview. Imagick and `pdftoppm` are optional because the browser converts PDFs to photos.
2. **Database.** cPanel > MySQL Databases: create the database and a user, add the user with all privileges, and note the prefixed names. In phpMyAdmin select the empty database and import `database.sql` once.
3. **Admin.** With a terminal: `php artisan nexus:admin-create`. Without one: run `php scripts/new-admin-sql.php admin@yourdomain.com` on your PC and paste the output into phpMyAdmin > SQL.
4. **Files.** Extract `nexus-api.zip` to `/home/ACCOUNT/nexus-api` (outside any web root). Point the API subdomain's document root at `/home/ACCOUNT/nexus-api/public`. Extract `nexus-web.zip` into the static site's document root. Create `/home/ACCOUNT/private/nexus` and `/home/ACCOUNT/logs`.
5. **Environment.** Create `/home/ACCOUNT/nexus-api/.env` from `.env.example` (production values from Phase 0), then run `php artisan config:clear`.
6. **Outbound HTTPS.** From cPanel Terminal or a one-time cron: `curl -sS -o /dev/null -w "%{http_code}\n" https://ollama.com` and the same for `https://openrouter.ai`. Any HTTP status (even 401/404) proves the connection works; a timeout or connection error means the account blocks outbound calls and grading cannot work until HostGator allows them.
7. **Cron.** Add the 15-minute grading cron from the section above, with output going to `~/logs/nexus-grading.log`.
8. **Backups.** Upload `scripts/backup-hostgator.sh`, create `~/.nexus-backup.cnf` (chmod 600) and add the nightly cron described at the top of that script. cPanel's own backup tool can also be used, but check what your plan includes before relying on it.
9. **Restore test (do this once, before real students use the system).** Run `backup-hostgator.sh` and then `backup-hostgator.sh verify`. Create a scratch database in cPanel, import the newest `db-*.sql.gz` (gunzip it first for phpMyAdmin) into it, confirm the tables and row counts match, then delete the scratch database. Extract the newest `private-*.tar.gz` into a temporary folder and open one stored file to confirm it is readable.
10. **Automated outside check.** From your PC: `.\scripts\verify-hostgator-deployment.ps1 -ApiUrl https://api.yourdomain.com -SiteUrl https://www.yourdomain.com`. It must end with `0 failed`. It confirms `/api/health` returns ok, the CORS origin matches exactly, `.env` and other private files are not downloadable, the bundle has the real API URL, and the PDF.js worker is served with a JavaScript content type.
11. **Functional test.** Repeat the localhost submit tests against production with a throw-away student: typed answer, photo, multi-page PDF, one deliberately failing paper and **Retry assessment**. Confirm the admin grading queue (Tests > grading queue) shows the cron heartbeat and that the papers reach `COMPLETED`. Delete the test student and test papers afterwards.

### Sizing for about 2,500 users a day

Shared hosting limits are set by the plan and are not guaranteed, so treat these as things to watch rather than promises. 2,500 daily users is comfortable for reading, notebook chat and login traffic provided the API responds quickly, but grading is the bottleneck: each paper needs one or more AI calls and the cron can only start every 15 minutes. If many students submit at once (for example a whole class at a deadline), expect results to take several cron ticks. Use the admin grading queue to see the backlog and the age of the oldest waiting paper. If it regularly shows papers waiting more than an hour, move grading off the shared plan (a small VPS running the same `ai-tests:grade-pending` command every minute) rather than tuning further. Also watch CPU and entry-process warnings in cPanel > Resource Usage during the first busy week.

## Hardening and monitoring (after go-live)

- **Initial password `abc@123`.** The server, not the browser, enforces the change: until a student changes the password every API route except the three password-reset routes returns `403 PASSWORD_RESET_REQUIRED`, refreshed sessions stay locked, and the initial password, short passwords and an unchanged password are rejected. This is covered by `backend/tests/Feature/FirstLoginPasswordEnforcementTest.php`; run `vendor\bin\phpunit --filter FirstLoginPasswordEnforcementTest` after any change to `AuthController` or the auth middleware. The shared value is still a known secret, so give it to each student privately, never use it for admin or team accounts, and consider forcing a reset for students who have not logged in after the first week.
- **Provider spending.** Grading and notebook chat use paid cloud APIs, and a bug, a retry loop or abuse can run up cost. In the OpenRouter dashboard set a credit limit on the API key you put in `.env` (and use a key used only for this app). For Ollama Cloud, check the usage page for your plan and set any available limit or alert; if it has none, review usage weekly. Use a separate key for production so a leaked or rotated key never affects local work. Transient grading-provider failures retry with increasing delays capped at one hour; retries have no fixed attempt cap, so monitor the admin grading queue and provider billing.
- **Stuck or failed attempts.** Open the admin grading queue regularly. A "worker stalled" warning means the cron stopped running (check the cron line, the PHP path, and `~/logs/nexus-grading.log`). **Retry all** puts failed papers back in the queue after the provider or rubric problem is fixed.
- **Backups.** Check `~/logs/nexus-backup.log` weekly for `FAIL`, and download a copy off the server regularly.

## Limits, logging, and rollback

Set PHP `upload_max_filesize`, `post_max_size`, memory/time limits, and cPanel limits only after comparing application requirements with account policy. AI Notebook ingestion supports PDF, DOCX, and TXT sources; test-submission uploads have a 30 MiB total limit. AI Notebook chat, indexing, and automatic grading require valid cloud model credentials in the backend environment. PHP/shared-hosting request-time limits may affect document processing.

Test submissions (typed, photo, and PDF) are queued for the 15-minute cPanel cron. No completion-time estimate is shown until staging measurements are available. Results are released immediately by default (`result_release_delay_hours` is 0 in `database.sql` and the migration), with an optional administrator-configured delay of up to 720 hours. Provider outages are retried with backoff; non-provider failures can be reviewed and retried by an administrator. Use HTTPS only. Keep PHP errors out of responses (`APP_DEBUG=false`) and review protected Laravel logs for redaction. Avoid logging tokens, credentials, or document bodies. Rollback by restoring the prior static build and compatible PHP release; database rollback requires a tested backup because schema downgrades may be destructive.

If submitted attempts remain `SUBMITTED`, the AI worker has not processed the queue yet. Confirm the direct `ai-tests:grade-pending` cPanel cron above is enabled, its PHP binary path and API directory are correct, and inspect its log. Without that cron (or another continuously running worker), queued submissions cannot start AI grading. The admin attempt view refreshes queued/running states automatically and shows question-specific student-page previews with each assessment.
