# AI interview demo

This slice adds fictional applications, candidate text interviews and recruiter-triggered evaluations to the existing position workflow. It does not make hiring decisions or implement a shortlist.

## Local setup

Use a local PostgreSQL demo database and the project's existing PHP/Node dependencies. Do not use a deployed checkout or production database. No existing seed data needs to be changed or reseeded.

1. Set the following in your uncommitted `.env` (replace placeholders locally):

    ```dotenv
    APP_ENV=local
    APP_URL=http://127.0.0.1:8000
    DB_CONNECTION=pgsql
    DB_HOST=127.0.0.1
    DB_PORT=5432
    DB_DATABASE=your_local_demo_database
    DB_USERNAME=your_local_database_user
    DB_PASSWORD=your_local_database_password
    OPENAI_API_KEY=your_api_key
    OPENAI_MODEL=gpt-4.1-mini
    ```

    The model must support Responses API strict JSON schema output and be available to your API project. The key is used only server-side. No `VITE_*` credential is needed. Existing `APP_KEY`, session and cache configuration still apply.

2. In the local checkout, after verifying the configured database is the intended local demo database:

    ```bash
    php artisan config:clear
    php artisan migrate
    npm run build
    php artisan serve --host=127.0.0.1 --port=8000
    ```

    Do not run `migrate:fresh`. The migrations create the recruitment tables and add candidate archiving with restrictive foreign keys protecting interview evidence. No queue worker or extra service is required for this flow. Development alternative: run `npm run dev` in a second terminal after migrations, instead of building assets.

3. Sign in with an existing verified recruiter account. If none exists locally, register and use the local mail log verification link (`MAIL_MAILER=log`). Do not rerun the default seeder against an existing database.

## Meeting walkthrough

1. Open `/positions/create`. Create **Senior Laravel Developer**, choose **Türkçe**, and set status **Active**.
2. Add three essential criteria, for example:
    - Laravel service design and authorization.
    - PostgreSQL query performance and transactions.
    - Automated tests and queue failure handling.
3. Add a Turkish screening question linked to each criterion, for example:
    - “Geliştirdiğiniz bir Laravel servisinde yetkilendirmeyi nasıl tasarladınız?”
    - “Yavaş bir PostgreSQL sorgusunu nasıl teşhis edip iyileştirdiniz?”
    - “Kuyruk hatalarını ve yeniden denemeleri nasıl test ettiniz?”
4. Save. On the position detail page, enter a fictional candidate name such as **Kurgusal Deniz Yılmaz** and click **Add candidate and create interview link**.
5. Use **Copy Interview Link** on the confirmation page and save it securely before leaving. If automatic copying is blocked, select and copy the read-only field manually. Open it in another tab or private browser window. It is shown once and expires after 24 hours; only its SHA-256 hash is stored. The original token cannot be reconstructed from its hash. If a link is lost or expired, open the existing candidate’s recruiter interview detail and select **Generate New Interview Link**. This replaces the link for that same interview and expires 24 hours after regeneration; the previous link stops working. Completed interviews cannot regenerate links.
6. Click **Mülakata Başla** (or **Start Interview** for English) to start real AI questions. The candidate sees one current question at a time; persisted follow-ups have their own sequential question numbers. Answer as the fictional candidate in Turkish. Each saved topic gets one main question and at most one clarification. The session finishes automatically after every planned screening topic and its permitted follow-up. The final answer is saved in the same database transaction as completion; early completion is not allowed.
7. Open the recruiter interview detail from the confirmation page or the position's candidate list. Refresh the transcript, then click **Generate evaluation / retry**. Read each criterion's state and quoted message links. No automatic approval/rejection occurs.

## Recovery and deliberate scope

- Synchronous API calls have a 5-second connection timeout and 40-second total timeout. No automatic paid retries. Missing API configuration, HTTP errors, refusals, malformed/truncated output and unverifiable evidence produce retryable UI errors without exposing provider responses or secrets.
- Answers commit before model calls. Submission UUIDs plus the question being answered prevent duplicate or stale-tab answers. Resume generates a missing question without resubmitting the answer. Refresh restores the persisted transcript. Draft text and pending submission UUIDs are retained in tab-local session storage across refreshes when the browser allows storage. Saved answers are acknowledged using their persisted submission UUID. If storage is blocked, the page warns the candidate to keep the tab open. Unconfirmed submissions remain read-only for safe retries. Recovery controls appear only after failures or when state is uncertain.
- Database row locks reserve a 90-second processing lease; requests do not hold a database transaction open across API calls. An abandoned worker can be retried after 90 seconds. A superseded worker cannot save output. Automatic completion freezes the transcript; evaluation is an explicit recruiter action afterward.
- The active position is the recruiter-approved screening plan. Its title, description, language, requirements, topics and version are copied at interview creation; later position edits do not change an interview.
- Evaluations require exactly one entry per snapshotted requirement. Supported/gap assessments require evidence, and each quote must occur verbatim in a candidate message belonging to this interview. No-answer criteria must use `insufficient_evidence`. Strengths/gaps are derived from those validated assessments. Quote verification establishes provenance, not truth or semantic correctness; recruiter review remains necessary.
- Candidate answers are untrusted data, never model instructions. Prompts restrict questions/evaluations to work criteria and prohibit protected-characteristic inference. This is a fictional-data demo, not an assurance that model output can never violate instructions.
- Candidate access is a bearer capability scoped to one interview, with expiration, CSRF protection and request throttling. Keep links private and use HTTPS when accessing outside localhost. Configure access logs to redact candidate token paths before public use; HTTP path tokens may otherwise appear in infrastructure/browser history. The app does not persist plaintext tokens in application tables or session flash.
- The application tables reuse the existing PostgreSQL JSONB conventions. No production migration or deployment is part of this implementation.

## Verification

Run the focused suite against **in-memory SQLite only** with an absent test-specific configuration-cache path:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
APP_CONFIG_CACHE=/tmp/digital-recruiter-demo-absent-config.php \
php tests/inspect-database.php

APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
APP_CONFIG_CACHE=/tmp/digital-recruiter-demo-absent-config.php \
vendor/bin/pest tests/Feature/CandidateManagementTest.php tests/Feature/InterviewDemoTest.php tests/Feature/JobPositionTest.php tests/Feature/DemoSeederTest.php

npm run types:check
npm run check
vendor/bin/phpstan analyse --memory-limit=512M
npm run build
```

HTTP calls are faked in Pest; tests never spend API credits. For PostgreSQL verification, provision the explicitly isolated disposable server and use the safeguards in [demo-seeding.md](demo-seeding.md), then include `tests/Feature/InterviewDemoTest.php` in its PostgreSQL test invocation. Never point reset-based tests at the application/demo database.

API request format: [official OpenAI Structured Outputs documentation](https://developers.openai.com/api/docs/guides/structured-outputs).

## Changed files

- Persistence: `database/migrations/2026_11_07_000001_create_recruitment_interviews_tables.php`; `app/Models/{CandidateApplication,CandidateEvaluation,InterviewMessage,InterviewSession,JobPosition}.php`.
- Domain flow: `app/Domain/Interviews/Actions/{StartInterview,AdvanceInterview,GenerateEvaluation,RegenerateInterviewLink}.php`; `app/Domain/Interviews/Services/OpenAiInterview.php`.
- HTTP: `app/Http/Controllers/{InterviewController,CandidateInterviewController,JobPositionController}.php`; `routes/web.php`.
- Language/configuration: `app/Domain/Positions/Enums/InterviewLanguage.php`; `config/services.php`; `.env.example`.
- React: `resources/js/app.tsx`; `resources/js/pages/positions/show.tsx`; `resources/js/pages/candidate/interview.tsx`; `resources/js/pages/interviews/{created,show}.tsx`; `resources/js/types/{positions,interviews}.ts`.
- Tests: `tests/Feature/{InterviewDemoTest,JobPositionTest}.php`.
- Runbook: `docs/ai-interview-demo.md`.

Implementation verification: the focused suite (including database safety tests) passed **87 tests / 532 assertions**, with **2 PostgreSQL-only tests skipped** under in-memory SQLite. PHPStan level 7, frontend formatting/lint, TypeScript and the frontend build passed. No API key was configured, so a paid live call was not attempted. The local Docker daemon was unavailable, so PostgreSQL execution remains unverified. No migrations were run against the application database and no deployment occurred.

## Navigation and link safety

The confirmation includes the candidate and position, a prominent copy action, the interview-detail link, and a link to add another candidate on the same position. The interview detail has position/candidate breadcrumbs and stable navigation to the position’s candidate list. Reloading the confirmation POST URL redirects to that candidate list without regenerating a token or creating another session. Browser Back may restore the existing confirmation while its Inertia history entry remains available; this is not a token-recovery feature. Save the private link before leaving. Unfinished interviews now support authorized link rotation from their existing recruiter detail. No plaintext token storage or duplicate-session recovery is used.

## Secure link regeneration

Candidate rows use server-generated URLs for their related interview ID, with an explicit **View Interview** action. The recruiter detail displays candidate identity, current associated position, status, expiry, transcript and evaluation, plus **Generate New Interview Link** for unfinished interviews (including expired links). No additional candidate or interview is created.

Regeneration uses the existing authenticated, verified, CSRF-protected web routes. A database row lock and an optimistic link fingerprint allow only one request based on a given link version to win. A stale request does not invalidate the winning link. Only the token hash, expiry and normal update timestamp change; progress, messages, snapshots, processing leases and evaluations remain intact. Completed interviews are checked again inside the lock. Candidate writes revalidate the resolved token inside their own lock to close the concurrent revocation race. No AI prompt, model or evaluation changes are involved.

Copy the replacement link on its one-time confirmation, which explicitly states that the previous link is invalid and shows the new expiry. It is never put in session flash, application logs or analytics. Confirmation responses are private/no-store with a no-referrer policy. Reloading a regeneration confirmation URL returns to the authenticated interview detail, without recovering or rotating a token. If a regeneration response is lost, refresh the detail before deliberately generating another replacement; do not create another candidate. Concurrent-regeneration tests exercise stale competing versions in SQLite; a real parallel PostgreSQL lock test was not run in this environment.

## Candidate management

Apply `2026_11_07_000002_add_candidate_archiving_and_protect_interview_evidence.php` to the intended **local** demo database before using these controls. This migration was tested only against isolated test databases here; it has not been applied to the application database. It adds nullable `candidate_applications.archived_at` and replaces cascading candidate/interview/evidence foreign keys with restrictive foreign keys. It does not delete or rename existing candidates.

Each candidate row has an actions dropdown. **Edit Candidate** changes only the display name. **Archive Candidate** hides it from the default list, revokes its token and expires access while retaining snapshots, messages, progress and evaluations. Use **Archived candidates** to view historical rows and **Restore Candidate** to return one to the active list. Restoring never reactivates an old token: open the existing interview detail and generate a new link if the interview is unfinished. Completed candidates can be restored for recruiter review, but cannot receive a new link.

**Delete Candidate** requires an explicit confirmation checkbox and is limited server-side to fictional demo candidates with no started interview, processing lease, progress, messages or evaluation. The action explicitly deletes an eligible empty interview before deleting the candidate; it never cascade-deletes evidence. Restrictive foreign keys also block unexpected dependent records, rolling back the entire deletion. Candidates with interview history must be archived. Existing duplicates are not automatically deleted. All mutations use the authenticated, verified, CSRF-protected web routes and recheck ownership after locking the records.
