# Nexus SBI — HostGator build

This isolated copy targets PHP/Laravel and MySQL on HostGator Business. It retains authentication/account administration, standalone subjects, tests, and AI Notebook. The React/Vite frontend is built as static files; production does not require Node.js. PDF test submissions are rendered to page images in the browser and graded asynchronously by the configured vision-capable Ollama model using a cPanel cron job. Changes belong only in `D:\claude\nexus_sbi_hostgator`; the original `D:\claude\nexus_sbi` remains untouched.

The intentionally removed features and current route contracts are listed in [`docs/API-COMPATIBILITY.md`](docs/API-COMPATIBILITY.md). Typed/selectable-text test answers are assessed synchronously through Ollama; photos and scanned PDF pages are graded by a background cPanel cron job. Marks are hidden until the configured delay (24 hours by default). Notebook chat/grading and indexing require configured cloud APIs. Set the backend Ollama endpoint to `https://ollama.com/v1/chat/completions`; set OpenRouter embeddings to `openai/text-embedding-3-small` with 1536 dimensions. Do not copy API keys into the frontend.

## Database

The schema is in `database.sql`; it creates only the authentication, audit, subject, test/attempt/file, AI Notebook, and grading-settings tables. It contains no production records, sample users, or initial admin. The import script drops and recreates its declared tables, including `users`; back up first because it erases existing accounts and application data in those tables.

For Laragon/phpMyAdmin:

1. Create/select an empty database such as `nexus_sbi_local`.
2. Select that database, then import `database.sql`.
3. Configure `backend/.env` for the same database.
4. Configure the provider credentials and model settings in `backend/.env`; after changing them run `php artisan config:clear`. Existing Notebook sources uploaded before embedding credentials were ready need one-time reindexing with `php artisan ai-notebook:reindex-embeddings`.
5. Do **not** run `php artisan migrate` after the SQL import: Laravel will see no migration-history table and try to create tables that already exist. On a fresh database, choose either the SQL import or `php artisan migrate`, not both.
6. Create the first admin using the documented `php artisan nexus:admin-create` command after backend dependencies and environment are configured.

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

Serve `backend/public` as the API document root and `web/dist` as the static site. Review [`docs/HOSTGATOR-DEPLOYMENT.md`](docs/HOSTGATOR-DEPLOYMENT.md) before deployment. HostGator's actual PHP extensions, MySQL version, HTTPS egress, storage limits, and account configuration have not been verified.
