# HostGator API scope

The HostGator build supports authentication/account administration, student support queries, analytics for retained tables, standalone subjects, tests, AI Notebook, and a privacy-conscious student leaderboard. It does not expose the original school, class, section, folder, course, lecture, quiz, flashcard, or device-registration workflows. Do not point clients that require those routes at this API.

All authenticated routes use the bearer access token returned by login. Admin permissions are checked by Laravel middleware and service-level checks. `admin` is the super-admin permission; `teacher_assistant` manages subjects, notebook sources/prompts, and test authoring; `testing_manager` manages test settings and access requests; `user_manager` and `password_manager` manage their respective account functions.

## Authentication and accounts

| Route | Purpose |
|---|---|
| `POST /api/auth/login` | Authenticate and return access/refresh tokens. |
| `POST /api/auth/refresh` | Rotate refresh credentials. |
| `POST /api/auth/signout` | Revoke the current session. |
| `GET /api/auth/me` | Return the authenticated identity. |
| `POST /api/auth/change-password` | Change the current user's password. |
| `GET/POST /api/team-members` | List or create admin team members; super-admin only. |
| `PUT /api/team-members/{userId}/permissions` | Replace a team member's supported permissions. |
| `PUT /api/team-members/{userId}/password` | Reset an eligible account password. |
| `GET/POST /api/users` | List/search or create user accounts with role-based scope. Student creation accepts `fullName`, `email`, `phone`, `crn`, and optional `subjectIds`; the server assigns the requested initial password and never returns it. |
| `GET /api/users/search?email=...` | Search accounts by email fragment. |
| `PUT /api/users/{userId}/subjects` | Replace a student's subject grants (`subjectIds`); admin or `user_manager`. |

Admins and `user_manager` users can use **Students** to create student accounts and **Users** to manage subject grants. Student creation collects full name, email, phone, CRN, and optional subject grants. CRN is stored in the existing `users.studentId` field. Every newly created student receives the exact initial password `abc@123` and must change it at first login; the API blocks other authenticated routes until that change is complete. Changed passwords must be at least 12 characters.

Existing databases must add `subject_user_access` by importing [`../database-updates/student-subject-access.sql`](../database-updates/student-subject-access.sql) after selecting the target database. New students can be granted subjects during creation; existing students receive access only after an administrator assigns subjects in **Users**. If an old database still has folder workflow tables, remove them with [`../database-updates/remove-legacy-folder-workflow.sql`](../database-updates/remove-legacy-folder-workflow.sql) only after backing up and confirming that their data is no longer needed. Do not import the destructive `database.sql` into a database that contains records.

Supported admin permissions: `admin`, `teacher_assistant`, `user_manager`, `password_manager`, `testing_manager`, and `student_query`.

## Student support queries

| Route | Purpose |
|---|---|
| `GET /api/student-queries` | Students see only their own queries; administrators with `student_query` permission see the inbox. |
| `POST /api/student-queries` | Create a student query with `title` and `message`. |
| `POST /api/student-queries/{id}/reply` | Reply to a query; administrator with `student_query` permission or super-admin only. |

Query content is stored in the `student_queries` table and audit events are written for creation and replies.

## Subjects and AI Notebook

Subjects are standalone records used to group tests and notebook content; they do not depend on schools, classes, sections, or folders.

| Route | Purpose |
|---|---|
| `GET /api/subjects/list` | List standalone subjects. |
| `POST /api/subjects` | Create a subject (`code`, `name`); requires `teacher_assistant` or `admin`. |
| `PUT /api/subjects/{id}` | Update a subject's code/name. |
| `DELETE /api/subjects/{id}` | Delete only if no tests or notebook activity references it. |
| `GET /api/ai-notebook/subjects` | List subjects for notebook selection. |
| `GET /api/ai-notebook/subjects/{subjectId}/sources` | List sources for the subject. |
| `POST /api/ai-notebook/sources` | Multipart upload with `file`, `subjectId`, and optional `title`; teacher assistant/admin. |
| `DELETE /api/ai-notebook/sources/{id}` | Remove a source and its private indexed content; teacher assistant/admin. |
| `GET /api/ai-notebook/subjects/{subjectId}/prompt` | Read the subject's default and current-user prompt. |
| `PUT /api/ai-notebook/subjects/{subjectId}/prompt/default` | Save the subject prompt and response language; teacher assistant/admin. |
| `PUT /api/ai-notebook/subjects/{subjectId}/prompt/personal` | Save the authenticated student's own prompt and response language for an accessible subject. |
| `GET /api/ai-notebook/sessions?subjectId=...` | List the caller's conversations, optionally filtered by subject. |
| `GET /api/ai-notebook/sessions/{id}` | Load one caller-owned conversation. |
| `DELETE /api/ai-notebook/sessions/{id}` | Delete one caller-owned conversation. |
| `POST /api/ai-notebook/chat` | Ask a subject-scoped question; request includes `subjectId`, `question`, and optional `sessionId`/`studyAidMode`. |

Notebook indexing requires OpenRouter embeddings; chat uses the configured Ollama Cloud model. Students can change their response language and personal prompt without changing the subject default. Configure `OPENROUTER_API_KEY`, `OPENROUTER_EMBEDDINGS_MODEL`, and `OPENROUTER_EMBEDDINGS_DIMENSIONS`, plus `OLLAMA_API_KEY` and `OLLAMA_MODEL`, in the backend environment. The supported starting configuration is `openai/text-embedding-3-small` with 1536 dimensions and Ollama's OpenAI-compatible `/v1/chat/completions` endpoint. Keys must not be added to the React build. If provider requests fail, the API returns a failure rather than a fabricated answer. Sources created before embeddings were configured can be upgraded with `php artisan ai-notebook:reindex-embeddings`.

## Analytics

| Route | Purpose |
|---|---|
| `GET /api/admin/analytics/overview` | Return student, subject, test, submission, graded-attempt, average-mark, notebook-source, and support-query counts; admin or `testing_manager`. |
| `GET /api/leaderboard` | Return anonymous rankings for students who share at least one subject with the caller; only submitted, graded results that are released to students are included. |

## Tests

| Route | Purpose |
|---|---|
| `GET /api/tests` | List tests; teacher assistant/admin. |
| `POST /api/tests` | Create a test using `subjectId`, title, deadline, total marks, and question/marking data; teacher assistant/admin. |
| `POST /api/tests/questions/preview` | Parse uploaded question source files for authoring review. |
| `GET /api/tests/me/attempts` | List the authenticated student's tests and attempt state. |
| `GET /api/tests/{id}/me` | Load a test with student-safe question fields. |
| `POST /api/tests/{id}/me/request-access` | Request a deadline extension after expiry. |
| `POST /api/tests/{id}/me/submit` | Store submitted answers/files, then assess readable text answers with the configured Ollama model. |
| `POST /api/tests/{id}/me/attempts/{attemptId}/resubmit` | Retry assessment of a saved failed attempt. |
| `GET /api/tests/access-requests` | List pending requests; testing manager/admin. |
| `PUT /api/tests/{testId}/deadline/{studentId}` | Grant a later deadline for a pending request; testing manager/admin. |
| `GET /api/tests/{testId}/attempts` | List attempts, submitted answers, AI feedback, and review state; teacher assistant, testing manager, or admin. |
| `POST /api/tests/{testId}/attempts/{attemptId}/retry` | Retry an assessment or inspect a previously ungraded attempt; testing manager/admin. |
| `GET /api/tests/{testId}/attempts/files/{fileId}` | Download a private student answer file for manual review; teacher assistant, testing manager, or admin. |
| `PUT /api/tests/{testId}/marks/{studentId}` | Edit marks or resolve a student review request; teacher assistant, testing manager, or admin. |
| `GET/PUT /api/tests/ai-grading-settings` | Read/update the AI feedback prompt and student result-release delay (0–720 hours); testing manager/admin. |

Tests and attempts reference `users` directly. Tests are grouped by `subjects`; no folder permissions or school-roster tables are used. Text answers are assessed synchronously with Ollama and marks/feedback are stored immediately. Students see a submitted/under-marking state until `result_available_at`; the delay defaults to 24 hours and administrators can change it, with 0 releasing results immediately. Image-only or otherwise unreadable answers remain available for manual review. Provider failures are recorded for administrator retry, and administrators can edit marks.

`test_attempts` intentionally remains the one-row-per-student/test lifecycle record. Its status, submission, result, and optional review fields describe that same attempt; splitting them into one-to-one tables would add joins without normalizing repeated data. Uploaded files already live in `test_attempt_files`. Answers and assessment payloads remain JSON because the API stores and returns them as submission documents; a separate per-question answer table is only warranted if question-level reporting or querying becomes a requirement.

## Private storage and health

- `GET /api/health` provides a database-backed health check.
- `GET /api/storage/file` serves an authorized private file using the signed user-bound download contract.
- Uploaded source and answer files belong in private storage outside `backend/public`.

## Removed API areas

The route table intentionally omits the former `/school`, `/students`, and `/folders` endpoints, along with APIs for classes, class subjects, courses, devices, flashcards/reviews, folder access plans/installments/payments, grids, lectures/progress, licenses, migrations as an application endpoint, mock exams, quiz attempts/answers, school years, sections, subject teachers, topics, and topic progress. The new schema also omits those entities. Existing clients must be updated to use standalone `subjectId` values for test and notebook operations.
