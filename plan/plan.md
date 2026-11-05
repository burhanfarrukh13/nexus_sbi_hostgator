Fix plan for Nexus SBI (HostGator build)



I’ve split this into phases, in the order I’d do them. The first three phases carry the most risk, and the ones marked “verify” are things I haven’t confirmed.



Phase 0: Secrets and hygiene (before anything is zipped)

Rotate the OpenRouter and Ollama keys in backend/.env. They’re real keys, and the current packaging script would copy that file into the zip.

Create a separate production env file from .env.example, and don’t reuse the local one:

APP\_ENV=production

APP\_DEBUG=false

new APP\_KEY

new JWT and storage-signing secrets

real DB credentials

PRIVATE\_STORAGE\_PATH=/home/ACCOUNT/private/nexus

exact HTTPS CORS\_ALLOWED\_ORIGINS

Never ship these:

backend/.env

backend/storage/private/\* (real student papers from your testing)

storage/logs/\*

storage/framework/testing

.phpunit.result.cache

route-list-folders.json

.freebuff

tests/

also i have 2500 users in total per day. and have hostgator business plan. 

so do changes according to it


Phase 1: Make papers actually get checked (core fix)



Code changes



Make ai-tests:grade-pending drain the queue. Run it in a loop for about 10–12 minutes, then exit. Use the same 2-slot lock, and stop starting new papers once the time budget is nearly used. This fits HostGator’s 15-minute cron minimum.

Fix the stuck-queue bug. The command selects PDF attempts, but gradePendingAttempt only accepts attempts with an image file. Either accept PDFs there too (the server renderer already exists), or exclude them from the command’s query.

Change recoverStaleAttempts to reset only PROCESSING attempts that are stuck, using a longer window such as 45 minutes. Attempts still waiting in SUBMITTED should never be failed.

Register the command in routes/console.php with Schedule::command(...)->everyMinute()->withoutOverlapping().

Locally you run php artisan schedule:work.

On HostGator the cron runs schedule:run.

Add an index on test\_attempts (status, submitted\_at) to both database.sql and the migration.



Student experience

6\. Change the “queued” message to say results can take up to about 15–20 minutes. The page currently stops polling after 5 minutes and says “refresh later”.

7\. Keep the admin Retry assessment button as the manual “grade now” fallback.



Capacity

8\. A 15-minute tick will grade only a handful of papers per run, because each paper can take several minutes with page transcription. For a big class submitting at once, plan one of:



accept the delay

add a token-protected URL that a free pinger (cron-job.org) calls every minute

move to a VPS or a plan with faster cron

Phase 2: Frontend fixes

Build with the real API URL. The current dist has http://127.0.0.1:8000 baked in, and the package script hardcodes https://api.example.com. Make the script read the URL from a parameter and fail if it’s missing.

Remove the live-update stream. api.ts calls /api/student-data/events, which has no route in api.php. Every open tab would 404 and retry forever (verify where App.tsx uses it). Replace it with modest polling.

Slow the 3-second setInterval refresh (App.tsx line \~4172) to a sane interval, or only poll while a grading result is pending. This matters on shared hosting.

Add AddType text/javascript .mjs to web/public/.htaccess. Older Apache serves .mjs as a generic type, which can break the PDF.js worker, and the PDF-to-photo conversion depends on it (verify by checking the Content-Type after upload).

Add an HTTPS redirect and security headers to the static .htaccess: HSTS, nosniff, frame blocking, and a CSP.

Phase 3: Packaging and build

Build the backend with composer install --no-dev --optimize-autoloader. The current vendor includes dev packages.

Delete bootstrap/cache/\*.php and regenerate it from the no-dev install. The stale local cache can reference packages that won’t exist on the server.

Rewrite prepare-hostgator-package.ps1/.sh to:

use an explicit allow-list instead of Copy-Item backend/\*

accept the API URL

produce two separate outputs (API zip and static site zip)

print a checklist of excluded files

Phase 4: Docs and consistency

Update HOSTGATOR-DEPLOYMENT.md and the README:

The cron section should say 15-minute interval, schedule:run or the drain command, and use the ea-php82 path from cPanel’s MultiPHP.

Fix the stale-recovery time (the docs say 5 minutes, the code says 20).

The README says results are hidden for 24 hours by default, the deployment doc says immediate. Check the result\_release\_delay\_hours default in database.sql and make them agree.

Check that database.sql and the migration 2026\_11\_04\_000001 define the same tables and columns. Two sources of schema can drift.

Confirm the real signature of nexus:admin-create against what the docs say.

Phase 5: Tests



Add tests for:



the drain command, including the PDF-only case

stale-recovery behavior

the schedule registration

a full submit-to-completed run with a faked CloudChatClient



Then run vendor\\bin\\phpunit and npm run typecheck.



Phase 6: Localhost (to prove it works before cPanel)

Start MySQL in Laragon. The log shows the last login failed on a DB connection.

Import database.sql into nexus\_database, then create the admin.

Set result\_release\_delay\_hours to 0 in ai\_grading\_settings for testing.

Run the API with php artisan serve, the frontend with npm run dev, and the grader with php artisan schedule:work in a third window.

Test the complete flow:

Create a test with a marking scheme.

Submit a typed answer (should grade immediately).

Submit a photo, then a multi-page PDF.

Confirm each reaches COMPLETED with marks and feedback.

Add a deliberately bad key to confirm it ends as FAILED and Retry assessment works.

Phase 7: HostGator deploy and verification (needs cPanel)

In cPanel, set the PHP version to 8.2+ and enable the extensions in composer.json. Check whether Imagick or pdftoppm exists (optional, since the browser converts PDFs).

Create the MySQL DB and user, import database.sql once into the empty DB, and create the admin. If there’s no SSH, generate a bcrypt hash locally and insert it via phpMyAdmin, because the admin command is interactive and a cron can’t answer the password prompt.

Point the API subdomain document root at backend/public, and the static site at the built dist. Keep .env and private storage outside both.

Run php artisan config:clear. Test from a one-time cron or terminal that outbound HTTPS to ollama.com and openrouter.ai works.

Add the 15-minute cron, with output redirected to a log file.

Run the same submit tests as Phase 6, and check that /api/health returns ok, the CORS origin matches exactly, and .env is not downloadable from the web.

Set up cPanel backups for the DB and private storage, and run a test restore.

Phase 8: Optional hardening

Initial student password abc@123 is a shared known value. It’s fine if first-login change is enforced server-side, so confirm that.

Monitor Ollama and OpenRouter usage and set provider spending limits.

Add a simple admin view for stuck or failed attempts.

