# Plan 2 verification and staging checklist

This checklist separates local verification from checks that require the real
HostGator account, MySQL, provider plans, or owner decisions. No live credentials
or production resources are used by the local suite.

## Local checks

Run from the repository root:

```powershell
Set-Location backend
.\vendor\bin\phpunit
Set-Location ..\web
npm run typecheck
npm run build
Set-Location ..
git diff --check
```

The backend tests use SQLite and mocked providers. They cover authentication
limits, status/ETag handling, notebook queue ownership/FIFO/expiry/idempotency,
daily-limit reservation/refunds, submission recovery, and grading retry
classification. They do **not** substitute for MySQL, provider golden-set, or
staging load verification.

## Manual browser checks (staging only)

- Sign in as a student and confirm there is no queue position, count, ordinal,
  ticket ID, or internal provider/grading status in the UI or API response.
- Send a text question and a photo question. While busy, confirm the normal
  “Thinking…” state remains, the original question/photo is retained, and
  retrying the same request does not create duplicate chat messages.
- Simulate loss of the first response after ticket creation, then restore the
  network. Confirm the same ticket completes and the provider is not called a
  second time after completion.
- Change tabs during grading. Confirm status checks pause while hidden and
  refresh on return; confirm the student sees a calm saved/pending state and
  the result becomes visible once ready.
- Start Notebook source indexing as an administrator. Confirm polling backs
  off to 5/10/15/30 seconds and is not active for student users.
- Save test answers, reload the browser, and verify local restoration. Check
  the server draft from a second device. Submit once, interrupt the response,
  and verify the idempotent retry returns the original attempt.
- Check photo compression, upload behavior, weak-network retries, and offline
  messaging on a low-end phone. Upload progress/resume still needs explicit
  verification; the UI must not imply that an unconfirmed upload is safe.
- Check `Retry-After` behavior for login and refresh limits across users behind
  one shared IP; verify the 429 response retains the compatibility JSON shape.

## Staging/load gates (not run locally)

Use an isolated staging copy with disposable accounts and no production data.
Record the date, database/provider versions, request mix, concurrency, and
results alongside this file before changing the corresponding plan checkboxes.

- MySQL: import `database.sql` into a fresh disposable database and separately
  apply the additive update scripts to a copy of an existing schema. Verify
  migration parity and exercise typed, photo, PDF, provider-failure, admin
  retry, draft, and idempotent-submit paths.
- Load: 25 mixed API requests/second for 10 minutes; 30 simultaneous chat
  requests with at most eight provider calls at once; 1,000 status polls; 100
  logins from one IP; 1,000 simultaneous submissions; and a two-hour simulated
  provider outage. Verify ordinary routes stay responsive and queued requests
  complete FIFO without student-visible queue metadata.
- Provider quality: run the approved anonymized grading and Notebook golden
  sets before/after model, prompt, or retrieval changes. Include English,
  Urdu, Roman Urdu, and photo questions.
- HostGator: verify cPanel process/CPU/memory/I/O quotas, PHP limits, outbound
  HTTPS, cron scheduling, private-storage permissions, backups, and a restore
  test. Run `php artisan nexus:preflight --network` on the account.
- Record observed grading times, source counts, provider limits/costs, and real
  browser request counts. Do not substitute plan estimates for these values.

## Explicitly pending

- HostGator account access, deployment, cPanel measurements, backup/restore,
  and real cron checks.
- MySQL integration and staging load tests.
- Provider spending/concurrency limits and golden-set review.
- Owner decisions on deadline grace/action, student-specific limit overrides,
  retention duration, idle-start behavior, email notifications, and the
  existing shared initial-password policy.
- No automatic answer-file deletion is enabled while the retention decision
  is open.
