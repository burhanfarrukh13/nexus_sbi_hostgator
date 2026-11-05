# Plan 2 — Capacity, test-grading and deploy-readiness for ~2,500 users/day on HostGator Business

Status: draft, written 2026-11-05 after a code review and a full local test run. Updated the same day with the owner's student-first requirements (section 9), which override earlier items where they conflict.
Scope: `D:\claude\nexus_sbi_hostgator` only (Laravel API in `backend/`, React static site in `web/`).

Implementation status: partial. Local implementation and SQLite/mock-provider verification pass, but this plan is **not complete**. See [`../docs/PLAN2-VERIFICATION.md`](../docs/PLAN2-VERIFICATION.md) for executable local checks and the separate staging/owner-decision gates. No HostGator deployment, real MySQL run, provider golden-set run, or load result is claimed.

> All load figures below are **estimates** built on stated assumptions (2,500 unique users/day, about 60 API calls per user session, 15% of users in the busiest hour, peak minute = 2x the hourly average). They must be replaced with measured numbers in Phase 0.

---

## 1. Where we are

| Item | Result |
|---|---|
| Backend tests | 123 tests, 1,066 assertions, all pass locally (PHP 8.2.35, PHPUnit 11.5.57) |
| Frontend | `npm run typecheck` and `npm run build` pass; no automated frontend tests (manual checklist documented) |
| Test environment | SQLite in memory, cache = array, AI provider mocked |
| Git | Intentionally dirty worktree preserved; changes remain uncommitted |
| HostGator facts | HostGator documents a limit of 25 simultaneous processes per cPanel account. CPU/RAM limits for the plan are not published: verify in cPanel > Resource Usage |

### Estimated traffic

| Scenario | Users in peak hour | Requests/min (peak minute) | Requests/sec | Busy PHP processes (about 80 ms/request) |
|---|---|---|---|---|
| Normal day (15% in peak hour) | 375 | about 750 | about 12 | about 1 |
| Busy day (30% in peak hour) | 750 | about 1,500 | about 25 | about 2 |

Plain browsing is not the problem. The risks are the five items in section 2.

## 2. Problems to fix (ordered by risk)

1. **AI Notebook chat is synchronous and unbounded.** `AiNotebookController::chat` holds a PHP process for the whole AI call (timeout 300 s in `CloudChatClient`). A daily cap is enforced, but it must be configurable and count only successful answers (default 15 questions/student/day).
2. **Notebook retrieval loads every chunk of a subject on every question** (`retrieveChunks`): about 29 KB of embedding JSON per chunk, decoded in PHP. Estimated 0.25 s CPU at 300 chunks, 0.8 s at 1,000, 2.5 s at 3,000, plus memory-limit risk above about 3,000 chunks.
3. **Grading is much slower than the "15 to 20 minutes" shown to students.** One AI call per page plus one grading call per paper. At an assumed 30 s per call and 2 workers: about 210 typed papers/hour, about 56 three-page photo papers/hour, about 23 ten-page PDFs/hour. 500 three-page photo papers would take about 9 hours. The second cron line is needed for 2 workers.
4. **Login and refresh are rate limited per IP** (`throttle:10,1` and `throttle:20,1`). Campus Wi-Fi and mobile carriers share IPs, so a whole class can be blocked, and refreshes bunch up 60 minutes after class starts.
5. **Result polling** (`web/src/App.tsx`, `GRADING_FAST_POLLS`/`GRADING_MAX_POLLS`): every waiting student polls every 10 s for 2 minutes, then every 30 s for about 23 minutes. With 1,000 waiting students that is about 45 requests/sec, roughly 1-2 CPU cores.

Smaller items: shared initial password `abc@123`; leaderboard recalculated on every call; `LOWER(email) = ?` in login prevents index use (harmless at this size); bcrypt cost 12 is about 0.25 s CPU per login/password change.

## 3. Targets (definition of "good enough")

- Normal browsing: p95 API response under 500 ms at 25 requests/sec.
- Never more than 8 chat requests running at the same time, but students are never turned away: extra questions wait in a short queue with a friendly "you're next" message (see section 9).
- No legitimate student blocked by the login limit when 100 users log in from one IP within 5 minutes.
- Grading: the message shown to students matches measured reality (Phase 0), and a typed-answer test of 500 papers clears in under 3 hours.
- No change in AI answer quality or grading accuracy (see section 6 quality guard).

---

## Phase 0 — Measure before changing (half a day)

- [ ] In cPanel > Resource Usage, note CPU, memory, entry-process and I/O limits for the account; record them here.
- [ ] Confirm PHP 8.2 selectable, `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size`.
- [ ] Run the outbound check: `curl -sS -o /dev/null -w "%{http_code}\n" https://ollama.com` and the same for `https://openrouter.ai`.
- [ ] Time one real paper through the real model: typed, 3-page photo, 10-page PDF. Record seconds per AI call and seconds per paper. Replace the 30 s assumption in section 2.
- [ ] Count chunks of the largest real subject: `SELECT subject_id, COUNT(*) FROM ai_notebook_chunks c JOIN ai_notebook_sources s ON s.id = c.source_id GROUP BY subject_id;`
- [ ] Measure real request counts per user session from the browser network tab (replace the "60 calls" assumption).
- [ ] Decide the Ollama Cloud and OpenRouter plan limits (concurrency, requests/min) and write them here.

## Phase 1 — Protect the account from overload (1-2 days) **must do before launch**

### 1.1 Chat concurrency cap and timeout
- Files: `backend/app/Http/Controllers/AiNotebookController.php`, `backend/app/Services/CloudChatClient.php`, `backend/app/Services/PrivateFileStorage.php` (has a flock slot helper to copy), `backend/.env.example`.
- [x] Add a chat slot limiter (flock slots like `withGradingSlot`, or `Cache::lock`), default `NOTEBOOK_MAX_CONCURRENT=8`. When no slot is free, the question **waits in a short queue** (see section 9.2) instead of failing; the student sees a waiting message, never an error.
- [x] Add a separate chat timeout `OLLAMA_CHAT_TIMEOUT_SECONDS=60` for notebook chat. Keep 300 s only for background grading.
- [x] Set the daily limit to **15 questions per student per day** (owner decision), read from the new admin Settings tab (section 10) instead of the hard-coded `DAILY_REQUEST_LIMIT = 20`. A 6/minute per-user guard is in place; the separate photo-per-hour guard remains pending.
- [x] Enforce the configured daily limit with a friendly response; reserve atomically to prevent concurrent overshoot and refund failed provider calls.
- [x] Frontend: while a question waits, show the same simple "Thinking..." state as for any answer (never a queue position or "you are Nth in line"), keep the question on screen, retry automatically and never discard what the student typed.
- Acceptance: with 30 simultaneous chat requests, at most 8 run; the rest wait their turn and are answered in order; other routes stay responsive.

### 1.2 Login and refresh limits that do not block shared IPs
- Files: `backend/routes/api.php`, `backend/bootstrap/app.php` (or an `AppServiceProvider`), `backend/tests/Feature/AuthCompatibilityTest.php`.
- [x] Define named rate limiters: login = 5/min per email+IP **and** 120/min per IP; refresh = 10/min per user id (from the refresh body) **and** 300/min per IP.
- [x] Return the existing JSON error shape plus `Retry-After`.
- [x] Tests: 20 different emails from one IP are all allowed; 6 bad attempts for one email from one IP are blocked.
- [ ] Optional: `BCRYPT_ROUNDS=11` (check login time on HostGator first).

### 1.3 Frontend: single-flight, jittered token refresh
- File: `web/src/api.ts`.
- [x] One refresh at a time per tab (single-flight promise); refresh 2-5 minutes before expiry with random jitter; share across tabs with `BroadcastChannel` or a cooperative `localStorage` lock.
- [x] On 429 from refresh, wait `Retry-After` and retry; do not log the user out.

### 1.4 Result polling
- File: `web/src/App.tsx` (around the `GRADING_*` constants and the polling effect).
- [x] Poll every 20 s for the first 2 minutes, then 60 s; add up to 20% random jitter; stop polling while the tab is hidden (`document.visibilityState`) and poll once when it becomes visible.
- [x] Use a lightweight status request (see 4.2) instead of reloading the full test.
- [ ] Update the student message to the measured grading time from Phase 0.

## Phase 2 — Make notebook retrieval cheap (1-2 days)

- Files: `AiNotebookController::retrieveChunks`, `CloudEmbeddingClient`, `ReindexNotebookEmbeddings`, `database.sql` and the migration (keep both in step; `SchemaParityTest` checks this).
- [ ] Store embeddings in compact binary form (float32 `pack('g*')`, about 6 KB for 1,536 dimensions instead of about 29 KB JSON) in a new column; keep reading the old JSON column until reindexed.
- [x] Select only `id, source_id, ordinal, embedding` and source title first, rank, then fetch `content` for the top 5 only when vector retrieval is available. The lexical fallback still loads content for scoring.
- [x] Cache the question embedding for 10 minutes (key = hash of normalized question + model and dimensions).
- [x] Cap chunks per subject at 3,000 with a clear indexing-limit response.
- [ ] Optional: smaller embedding dimensions (for example 512) if OpenRouter passes `dimensions` through; re-run the quality check in section 6 before switching.
- [ ] Add a test that retrieval returns the same top 5 chunks before and after the format change.
- Acceptance: a 3,000-chunk subject answers with under 0.5 s of PHP time and under 64 MB peak memory.

## Phase 3 — Grading throughput and honesty (1-2 days)

- Files: `TestAssessmentService`, `GradePendingTestAttempts`, `docs/HOSTGATOR-DEPLOYMENT.md`, `web/src/App.tsx`.
- [ ] Cache page transcriptions per attempt (new table or JSON column). A retry after a provider error then skips pages that were already transcribed. Same output, fewer AI calls.
- [ ] Apply the per-run time limit inside a paper too: if a paper would run past `GRADING_RUN_SECONDS + 120`, put it back in the queue after finishing the current page.
- [ ] Set `GRADING_WORKERS=2` **and** add the second cron line (offset 7 minutes). Check `ps`/cPanel that long-running CLI jobs are not killed by HostGator.
- [ ] Prefer typed answers in test authoring guidance (1 AI call per paper); explain that photo/PDF papers take longer.
- [x] Admin grading queue: expose an estimated time to clear only after successful grading durations have been measured; it is based on queued papers and configured workers and is admin-only.
- [ ] Decision point: if the measured time to clear a typical class is over 3 hours, move grading to a small VPS running `php artisan ai-tests:grade-pending` every minute (same code, same database).
- [x] Remove the unmeasured "15 to 20 minutes" promise from the student-facing message and deployment/readme docs; replace it with neutral saved/queued wording. Add a measured estimate only after Phase 0.

## Phase 4 — Limit API requests without lowering quality (see section 5 for details)

Tasks are listed in section 5 (client-side 4.1-4.4, server-side 4.5-4.8).

## Phase 5 — Testing (1-2 days)

- [ ] Run the whole suite on **real MySQL** (Laragon): import `database.sql`, run the app against it, repeat the submit flows (typed, photo, PDF, failing paper, admin retry, student resubmit). The current suite uses SQLite and a mocked AI.
- [x] Add feature tests for chat slots, login/refresh limits, status endpoint and 304 responses.
- [x] Add a small frontend test setup (Vitest) for polling and refresh helpers, or at least document a manual checklist. The manual browser checklist is in `docs/PLAN2-VERIFICATION.md`; a Vitest harness is not added.
- [ ] Load test with k6 (or similar) against a staging copy: 25 requests/sec of mixed browsing for 10 minutes; 30 concurrent chats; 1,000 simulated polls; login bursts of 100 users from one IP. Record results here.
- [ ] Run the grading golden set (section 6) before and after any change touching grading.
- [x] `vendor\bin\phpunit` and `npm run typecheck` pass locally; production build is also required. Deployment packaging remains pending.

## Phase 6 — Deploy checklist

- [ ] Commit all changes; tag the release.
- [ ] `.\scripts\prepare-hostgator-package.ps1 -ApiUrl https://api.yourdomain.com` (checklist must show no `[FAIL]`).
- [ ] Follow the go-live runbook in `docs/HOSTGATOR-DEPLOYMENT.md` steps 1-11 (PHP version and extensions, database import, admin, files, `.env`, outbound HTTPS, cron, backups, restore test, `verify-hostgator-deployment.ps1` ending in `0 failed`, functional test with a throw-away student).
- [ ] Run `php artisan nexus:preflight --network` on the server.
- [ ] Set spending limits/alerts on the OpenRouter key and check the Ollama usage page.
- [ ] Replace the shared initial password `abc@123` with a random per-student password or one-time link (decide below).

## Phase 7 — After launch

- Watch cPanel Resource Usage daily for the first week (entry-process and CPU faults).
- Review the admin grading queue and `~/logs/nexus-grading.log` daily.
- **Move to a VPS (or higher plan) if any of these happen:** entry-process faults more than a few times a day; grading backlog regularly older than 1 hour; chat 429s above 5% of requests; CPU throttling in Resource Usage.
- Re-run the Phase 0 measurements after the first real test day and update this plan.

---

## 5. How to limit API requests without lowering quality

Principle: **reduce how often we call, never what we send or what the AI is asked.** Every item below removes duplicate, unnecessary or badly timed requests. None of them shortens answers, lowers image quality, changes prompts, changes the model or loosens the grading rubric.

### Client side (`web/src`)

**4.1 Do not ask again if nothing changed**
- [ ] Cache read-only lists in memory for 60 s (subjects, test list, notebook sessions, subject prompt) with "show cached, refresh quietly" behaviour. Invalidate on any write (create/delete/submit).
- [x] De-duplicate in-flight requests: if the same GET is already running, reuse its promise (React strict mode and quick route changes often send it twice).
- [ ] Do not reload the whole test or list after an action when the response already contains the new state (for example the chat reply already contains the answer and `sessionId`).

**4.2 Cheaper polling**
- [x] New small endpoint `GET /api/tests/{id}/me/status` returning only safe status fields instead of the full test with questions and attempt files.
- [x] Send `ETag` / honour `If-None-Match` so an unchanged status returns `304` with no body.
- [ ] Server returns `retryAfterSeconds` based on queue depth; the client uses it (never faster than 20 s). Quality is unchanged: the result appears at the same moment, we just look less often when it cannot be ready.
- [x] Pause when the tab is hidden; refresh once when visible again.

**4.3 Typing and search**
- [ ] Debounce user/email search to 300-400 ms and cancel the previous request (`AbortController`).
- [ ] Disable the send button while a chat request is running; prevent double-click double submits on test submission (the server already rejects a second submit, but the extra upload is still wasted).

**4.4 Refresh and admin polling**
- [x] Single-flight, jittered token refresh (Phase 1.3).
- [x] Notebook source indexing poll (`App.tsx`, every 5 s while a source is `processing`): back off 5 s, 10 s, 15 s, then 30 s; admin only.

### Server side (`backend`)

**4.5 Per-route budgets (named rate limiters)**

| Route group | Suggested limit | Why |
|---|---|---|
| `POST /auth/login` | 5/min per email+IP, 120/min per IP | stops guessing, allows a class behind one IP |
| `POST /auth/refresh` | 10/min per user, 300/min per IP | same |
| `POST /ai-notebook/chat` | 15 questions/day per student (editable in Admin > Settings); about 6/min per user abuse guard; 8 concurrent total with a hidden waiting queue | protects the 25-process cap and provider cost without turning students away |
| `POST /tests/{id}/me/submit` | never rejected for load; safe retries via an idempotency key (section 9.1) | answers must always be accepted |
| `GET /tests/{id}/me` and `/status` | 6/min per user per test | polling safety net |
| Other authenticated GETs | 120/min per user | generous; only catches runaway loops |

All listed limiters are implemented, including the 120/min authenticated API budget, and return the existing JSON error shape with `Retry-After`. Login, refresh, shared-IP behavior, and the general authenticated budget have feature tests. Normal use should not reach these numbers.

**4.6 HTTP and server-side caching**
- [x] `Cache-Control: private, max-age=30` plus `ETag` on the selected subject, Notebook-source and attempt-list GET routes.
- [x] `Cache::remember` for 60 s on `GET /leaderboard` (key per subject set) and on `GET /admin/analytics/overview`.
- [ ] Keep the per-request user lookup in `AuthenticateApiToken` uncached (it is one primary-key query and keeps password-change and revoke instant). If CPU needs it later, cache for at most 10 s and document the delay.
- [ ] Use the file cache store on HostGator (already the default); confirm `storage/framework/cache` is writable. If throttle writes become a bottleneck, evaluate a database cache table.

**4.7 Avoid repeated AI calls (same quality, fewer calls)**
- [ ] **Chat answer cache:** if the same question (normalized: trimmed, lowercased, single spaces) is asked on the same subject with no session history, no photo, same study-aid mode, same prompt hash and same response language, and the subject's sources have not changed, return the stored answer (TTL 24 h). The key must include a "sources version" (max source `created_at`/count) so new material is never hidden. Students asking the same textbook question get the same reviewed-quality answer instantly.
- [x] **Query-embedding cache** (Phase 2): 10 minutes.
- [ ] **Grading retry cache** (Phase 3): reuse stored page transcriptions on retry.
- [ ] Do **not** cache or share anything that depends on one student's answers; never reuse a grade between students.
- [x] Rate-limit-aware provider calls: in `CloudChatClient`, retry provider 429/5xx with exponential backoff (1 s, 3 s, 8 s, with jitter) and honour the provider's `Retry-After`. Fewer wasted calls, same result.

**4.8 Visibility**
- [ ] Log (without personal data) requests per route per minute, 429 counts and slot-busy counts; show them next to the existing admin grading queue numbers.
- [ ] Weekly review: which limiter fires most? Raise the limit if real students are hit, lower it if abuse appears.

### Things that would compromise quality, so we avoid them
- Cutting `max_tokens`/answer length, lowering image resolution in `ImageCompactor`, or sending fewer pages to grading just to save calls.
- Sending several pages in one transcription call or dropping the page-by-page transcription. This can be tried only if it passes the golden set below.
- Switching to a smaller model or higher temperature to speed things up.
- Caching AI answers without the sources-version and prompt in the key.
- Setting the daily question limit below the owner's 15 per student without agreeing it with teachers (the limit is editable in Admin > Settings, section 10).

---

## 6. Quality guard (run for every AI-related change)

- [ ] Build a **golden set**: 20-30 real (anonymised) papers covering typed, photo and PDF, with the marks a teacher agrees are correct, plus 30 notebook questions with known good source passages.
- [ ] Before a change, record: grading marks per paper, notebook top-5 chunks and answers. After the change, compare.
- [ ] Pass rule: marks within the current normal variation (for example +/- 1 mark per question), and retrieval top-5 overlap at least 4 of 5 for 90% of questions.
- [ ] Changes that only affect timing (limits, caches with correct keys, polling) need no golden run, but still need the automated tests.

## 7. Open decisions for the owner

1. Grading wording: show a measured time, or move photo/PDF grading to a VPS? (decide after Phase 0)
2. Initial student password: keep `abc@123` (known risk: anyone with a student's email can log in first) or random per-student passwords / one-time set-password links?
3. Chat limits: decided: 15 questions per student per day, editable in Admin > Settings (sections 9.2 and 10). Still to confirm: the Ollama plan carries up to 2,500 students x 15 questions (Phase 0), and the abuse-guard value.
4. Maximum chunks per subject and whether to shrink embedding size.
5. Whether to keep Ollama Cloud for both chat and grading, or use a different model for chat to reduce latency.

## 8. Suggested order and effort

| Order | Phase | Effort | Blocks launch? |
|---|---|---|---|
| 1 | Phase 0 measurements | 0.5 day | yes |
| 2 | Phase 1 (chat cap, login limiter, refresh, polling) | 1-2 days | yes |
| 3 | Phase 4 items 4.1-4.6 (caching and budgets) | 1-2 days | recommended |
| 4 | Phase 5 testing on MySQL + load test | 1-2 days | yes |
| 5 | Phase 2 (retrieval) and 4.7 (answer cache) | 1-2 days | before heavy notebook use |
| 6 | Phase 3 (grading throughput) | 1-2 days | before photo/PDF tests with large classes |
| 7 | Phase 6 deploy, Phase 7 monitoring | 1 day + ongoing | - |

Note: sections are numbered 1-8; Phase 4 tasks use ids 4.1-4.8 and live in section 5.

---

## 9. Student-first design (owner goals, added 2026-11-05)

These requirements come from the owner and **override** any earlier item that conflicts with them (the conflicting items in sections 3, 4.5, 7 and Phase 1.1 were already updated).

> Goal: give students the best possible service. Never refuse their work, never lose it, never make them resubmit, and let them use the study assistant freely within a fair daily limit of 15 questions. The server may make them wait a little, but it never turns them away and never shows them a queue position. Every limit is editable by the admin in a Settings tab (section 10).

### 9.1 Test submissions: always accept, always save, never ask to resubmit, grade when the AI is free

**What the code does today** (checked in `TestSubmissionService`, `TestAssessmentService`, `App.tsx`):
- Submissions are saved first and queued for background grading; provider failures retry with increasing delays and stale processing attempts are recovered.
- The existing deadline rejection remains unchanged pending the owner's grace/late-work decision.
- A submission idempotency key returns the stored attempt after a retry with the same key. Browser answers are locally restored, server drafts are upserted, and transient submission errors retry while files remain in memory. Uploaded files are not persisted across browser reloads.
- PDF parsing/page checks are deferred to the worker. Oversized or unreadable papers can still require teacher review.
- Students receive calm saved/pending status; provider errors are not surfaced as `FAILED`, and no completion-time promise is made before real measurements.

**Tasks**
- [x] **Autosave answers (no quality impact).** Save typed answers to localStorage keyed by user + test and restore on reload. Upsert a server-side draft after 10 seconds of inactivity and at most once a minute when changed; send a keepalive pagehide request and allow restoration from another device. Browser image/files are not included in the draft.
- [x] **Safe retries with an idempotency key.** The browser generates a submission id per attempt; duplicate same-key requests return the same attempt. Transient network/5xx failures retry with backoff and a visible safe-on-device message. Files remain in memory while the page is open; persisting files across reloads in IndexedDB remains pending.
- [x] **Keep submit light.** The request validates and stores files/answers, queues grading, and defers PDF text extraction and page checking to the worker. Oversize/image-page edge cases still need staging confirmation and teacher review policy.
- [ ] **Deadline policy (owner decision).** Recommended: accept any submission that was started before the deadline and arrives within a grace window (for example 5 minutes), and mark anything later as "late" for the teacher instead of rejecting it. Use server time for the deadline and show a countdown from server time.
- [x] **Students never see provider `FAILED`.** Provider problems retry automatically with growing delays (5, 15, 30, 60 minutes, then hourly) without an attempt limit. Student status masks provider details; the admin API surfaces failures/alerts and manual retry remains available.
- [x] **Fair queue.** Keep "untried first, then oldest". Two separate typed/photo lanes are not implemented.
- [x] **Calm status for students.** Student status says answers are saved and can be viewed in My Tests later; no queue position/count or unmeasured ETA is exposed. The admin-only queue API includes measured ETA only after successful grading samples exist. An admin UI banner and optional student ETA setting remain pending.
- [ ] **Start sooner when idle (needs verification on HostGator).** With a 15-minute cron, a paper submitted while the queue is idle can wait up to 15 minutes before the first worker starts. Option A: a free external pinger (for example cron-job.org) calls a token-protected URL every minute that starts a bounded grading run; first check that HostGator allows PHP `proc_open`/`exec` to start `php artisan` in the background, otherwise the URL itself must run grading for at most about 50 seconds. Option B: a small VPS runs the grader every minute. Option C: accept the 15-minute start delay.
- [ ] **Alerts for the admin.** The admin queue API exposes backlog/provider-retry/failed-attempt alerts; the visual banner and private-storage capacity warning remain pending.
- [ ] **Storage and backups.** Accepting every submission means more files. HostGator creates its own weekly backups only for accounts under 20 GB and 100,000 inodes, and a warning starts above 250,000 inodes. Decide a retention rule (for example delete answer images 90 days after the result is final, keep marks and feedback) and watch inode counts.

**Acceptance tests**
- During a simulated overload (limits lowered in a test), a submit still returns success and the paper is saved.
- Sending the same submit twice (same submission id) returns success both times and creates one attempt.
- Refreshing the browser or closing the tab mid-test restores every typed answer.
- A 2-hour provider outage: every paper is graded afterwards with no student action and no student ever sees "failed".
- A paper submitted 30 seconds after the deadline follows the agreed deadline policy.

Local tests cover saved drafts, same-key idempotency, provider retry/backoff, and safe student status. The browser crash/file restoration, 2-hour outage, deadline decision, and high-concurrency acceptance tests remain staging/owner gates.

### 9.2 AI Notebook chat: 15 questions per student per day, nobody turned away below that limit

**Limits (owner decision).** Each student gets **15 questions per day**, changeable at any time in Admin > Settings. Below that limit a student is never shown an error or a queue position; on a busy moment their question simply takes a few seconds longer. Two physical limits sit behind this: HostGator allows 25 simultaneous processes per account, and the AI provider has its own plan limits and cost. Worst case at 15/day is 2,500 students x 15 = 37,500 questions/day.

**Gate before launch (Phase 0)**
- [ ] Check the Ollama Cloud plan: requests per minute, concurrency and monthly cost for about 375 questions/hour normally and about 2,700/hour on an exam-eve day.
- [ ] Test the chat model on the golden question set (section 6) in **English, Urdu and Roman Urdu** (the app offers all three response languages) and with photo questions. If it is good enough, proceed. If it is not, change the model before launch.
- [ ] If the plan cannot carry 37,500 questions/day in the worst case, lower the limit in Settings or change plan; decide with real usage data after the first weeks.

**Tasks**
- [x] Replace the hard-coded `DAILY_REQUEST_LIMIT = 20` in `AiNotebookController` with the admin setting (default **15**), read from the singleton settings row (section 10). The admin can change it at any time.
- [x] **Do not charge a question that failed.** Reserve the slot atomically before provider work; refund on provider, answer parsing, or persistence failure so concurrent calls cannot exceed the limit and failed calls do not remain charged.
- [x] **Fix the day boundary.** Derive usage dates in `AI_NOTEBOOK_DAY_RESET_TIMEZONE` (default `Asia/Karachi`) so the limit resets at local midnight, regardless of the app/server UTC setting.
- [x] **Friendly limit messages.** On exhaustion, tell the student their daily Notebook limit was reached and when it resets. Never expose queue position, number of requests ahead, or ordinal phrases such as "3rd in line" in student messages or responses.
- [x] Add a hidden abuse guard of 6 questions/minute per user.
- [ ] Add an editable per-student photo-upload-per-hour guard in Settings.
- [x] **"Take a number" queue instead of errors.** Waiting must not hold a PHP process, so:
  1. The browser sends the question with a `clientRequestId`.
  2. If a chat slot is free and nobody older is waiting, the server answers right away (synchronously, as today).
  3. Otherwise the server saves a ticket and replies at once with `202 {retryAfterSeconds}` (about 10-20 ms of work). The reply carries **no position number**.
  4. The browser keeps showing the normal "Thinking..." state (after about 20 seconds: "Still working on it, thank you for your patience") and silently re-sends the same request every 3-6 seconds. When it is this ticket's turn and a slot is free, that request runs the question. The student never sees that a queue exists.
  5. Tickets expire after about 2 minutes without a retry; the question text always stays on screen.
  Cost: 50 waiting students re-checking every 3 seconds is about 17 cheap requests/sec.
- [x] Keep the 8-slot cap (`NOTEBOOK_MAX_CONCURRENT`) and the 60 s chat timeout so a hung provider can never use up all 25 processes.
- [x] Provider errors: preserve the ticket and question, refund its daily reservation, and return a queue-style 202 so the browser continues silent retries at a bounded backoff cadence without exposing technical errors.
- [ ] Keep answer quality: no shorter answers, same retrieval of the 5 best chunks, same prompts. Speed comes from cheaper retrieval (Phase 2), the same-question answer cache (4.7) and a faster model only if the golden set shows equal quality.
- [ ] Cost safety without limiting students: provider spending alert, daily question count and token count on the admin page, weekly review.

**Acceptance tests**
- 30 simultaneous questions: at most 8 run at once, the rest keep showing "Thinking..." and are answered in order; no queue position, ordinal, or count of requests ahead appears in any student response; other pages stay fast.
- A student can ask exactly 15 questions in a day (or the number set in Settings); the 16th shows the friendly limit message; changing the setting takes effect within about a minute.
- A failed or timed-out answer does not use up one of the 15 questions.
- The daily counter resets at midnight Pakistan time.
- A provider outage shows a friendly retry message and loses no question.

Local tests cover the eight slot cap, FIFO tickets, owner-only ticket lookup, expiry/refund, private photo persistence, idempotent answer retrieval, daily quota and Karachi reset. A true 30-request concurrent load test, provider outage recovery, and other-route responsiveness remain staging gates.

### 9.3 Other things that make life easier for students
- [x] Login: limits that work for classes behind one IP (Phase 1.2) and the existing wrong-password/first-password-change messages. The shared initial password remains an owner decision.
- [x] Never log a student out while writing: refresh the token quietly, keep a local draft, and retry after temporary refresh throttling (Phase 1.3).
- [ ] Friendly error texts everywhere: always say that the answers are safe, never show technical messages or status words such as `FAILED`, `PROCESSING` or HTTP codes.
- [ ] Slow phones and weak networks: image compression/retries exist, but upload progress and resume across reloads remain pending.
- [ ] If the server is down, the static site still opens: the network error is friendly and typed answers are locally restored; the exact "your work is saved" offline state still needs browser verification.
- [ ] "Your result is ready" badge in the app (one light status check per minute while the app is open).
- [x] A short "how it works" note on the test page: the submission response confirms that answers are saved immediately, results appear later, and the student can close the page.

### 9.4 Student-first work items, effort and order

| Id | Work | Effort | Blocks launch? |
|---|---|---|---|
| S1 | Autosave (browser + server draft) and idempotent, retrying submit | 1-2 days | yes |
| S2 | Light submit (defer PDF parsing to the worker), accept late/over-limit files with a flag | 1 day | yes |
| S3 | Students never see `FAILED`; unlimited provider-error retries with backoff; calm status with no queue numbers; admin alerts | 1-2 days | yes |
| S4 | Chat hidden "take a number" queue with 8 slots and 60 s timeout (no position shown) | 2 days | yes |
| S5 | Daily limit 15 from settings, failed questions not charged, midnight Pakistan reset, remaining-questions display, abuse guard, golden-set check of the chat model (English, Urdu, Roman Urdu) | 1 day | yes |
| S9 | Admin Settings tab and settings service (section 10) | 2 days | yes (S5 depends on it) |
| S6 | Deadline policy decision and implementation | 0.5 day | decision before launch |
| S7 | Idle-start trigger (pinger or VPS) | 0.5-1 day + HostGator check | recommended |
| S8 | Storage retention rule and alerts | 0.5 day | before the second test day |

Recommended order: Phase 0 (measure) -> S1, S2, S3 (so no answer is ever lost) -> S9 (settings service) -> S4, S5 (chat) -> Phase 1.2/1.3 (login and refresh) -> S6, S7, S8 -> Phase 5 testing on MySQL and the load test. The load test must now include: 1,000 simultaneous submits, 30+ simultaneous chats, a 2-hour simulated provider outage, and a refresh in the middle of a test.

### 9.5 New open decisions
1. Deadline policy: grace minutes, and whether late work is graded normally, graded with a flag, or held for the teacher?
2. Chat: is the Ollama plan enough for 15 questions/day for every student (worst case 37,500/day), and what abuse-guard value is acceptable? Should a teacher be able to give one student a higher personal limit?
3. Retention: how long are student answer images kept after the result is final?
4. Idle start: external pinger, VPS, or accept the 15-minute start delay?
5. Does a result notification by email matter to students (extra cost and setup)?

## 10. Admin application settings

- **Settings** is a dedicated item in the admin navigation, visible only to users with the `admin` permission. Teacher assistants and testing managers do not receive settings data or write access.
- Extend the existing singleton `ai_grading_settings` row; do not expose provider URLs, API keys, or other credentials in settings responses. Provider credentials remain in backend environment configuration only.
- `notebook_daily_question_limit` is an integer from 1 through 1,000, default 15. The admin can edit it alongside the deficiency-report prompt and result-release delay. Updates take effect on the next Notebook request.
- `AI_NOTEBOOK_DAY_RESET_TIMEZONE` is a backend environment setting, default `Asia/Karachi`; it is not a student-controlled option.
- The Settings page is the home for persisted, admin-editable application behavior. Future runtime limits and display policies added by this plan should be registered there; deployment credentials and host-level controls remain environment/cPanel settings.
- Admin-only grading-queue routes and operational details stay behind the `admin` permission. Student-facing chat results and limit errors must never contain a queue position or order estimate.
- Existing installations apply `database-updates/ai-notebook-question-limit.sql` once after backing up; clean installs receive the setting from `database.sql` or the Laravel migration.
