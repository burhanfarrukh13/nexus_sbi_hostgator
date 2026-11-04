# HostGator deployment notes — account validation still required

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
4. Configure `EMBEDDINGS_API_URL`, key, model and dimensions only after selecting and verifying a provider that implements the adapter's OpenAI-compatible HTTPS request/response shape and matches the notebook's indexing/query dimension. Configure `AI_CHAT_API_URL`, `AI_CHAT_API_KEY`, and `AI_CHAT_MODEL` for notebook chat. These settings are backend-only; the notebook chat endpoint returns a clear service-unavailable response until chat credentials are configured.
5. Initialize an empty database using either `database.sql` or Laravel migrations. For phpMyAdmin, select the exact target database before importing `database.sql`; for Laragon CLI, use `mysql -u root -p nexus_sbi_local < database.sql`. The SQL file does not select or create a database, drops and recreates only its listed tables, and inserts Laravel migration-history rows so a subsequent `php artisan migrate --force` does not try to recreate them. It does not remove unrelated tables already in the database; use a new/empty database if an exact clean schema is required. Back up first. Do not run database seeders or schema synchronization.
6. Only after completing the API/schema port, use the interactive `php artisan nexus:admin-create` command once on a live terminal to create the first admin. It refuses to add a second initial admin, asks for a password without echo, and does not accept a password as a command-line argument.
7. Ensure Laravel's `storage/framework` and log directories are writable by PHP, but not publicly reachable. Configure cPanel backups for both database and private upload directories and test restore.

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

Set PHP `upload_max_filesize`, `post_max_size`, memory/time limits, and cPanel limits only after comparing source requirements with account policy. AI Notebook ingestion currently supports PDF, DOCX, and TXT sources; test-submission uploads have a 30 MiB total limit. The deficiency-report prompt can be stored and retrieved, but automatic AI assessment grading and resubmission are still unavailable. Some source-system capabilities, including full billing and several document/lecture workflows, remain outside this port. PHP/shared-hosting request-time limits may make document processing and SSE unsuitable.

Use HTTPS only. Keep PHP errors out of responses (`APP_DEBUG=false`) and review protected Laravel logs for redaction. Avoid logging tokens, credentials, or document bodies. Rollback by restoring the prior static build and compatible PHP release; database rollback requires a tested backup because schema downgrades may be destructive. No deployment, database, or production secrets are configured in this copy.
