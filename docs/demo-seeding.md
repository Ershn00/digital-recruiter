# Demo data

`DemoSeeder` is explicitly invoked only; `DatabaseSeeder` does not call it. No migrations or additional roles are required. It creates three verified accounts, four active positions, and one position archived through `ArchivePosition` (version 2). All positions are full time with English interviews. Requirements and `screening_topics` use stable UUIDs, zero-based ordering, and linked requirement IDs.

## Identity and repeat runs

This seeder reserves user IDs **-910001 through -910003** and position IDs **-910001 through -910005**. PostgreSQL's existing bigint primary keys and foreign keys accept these signed values; ordinary generated IDs remain positive and their sequences are untouched. Reserve this range exclusively for the seeder. No IDs are used as authentication credentials.

An existing user must match both reserved ID and exact email. Other email matches (including case variants) or reserved-ID collisions abort the entire transaction. Existing positions must match reserved ID and owner. Existing demo records are left entirely unchanged, including passwords, names, verification state, position edits, archive state, and versions. Other positions, including same-title positions, are untouched. Test User 3 has no positions on a fresh seed; reruns preserve positions subsequently created by that user.

The schema has no immutable seeder provenance field: manually inserting the exact reserved ID/email pair (or position ID/owner pair) is indistinguishable from a seeded record. Review and reserve these ranges before first use. The seeder never modifies such existing records. Never reuse the namespace for other data. Deleting a demo record allows it to be recreated on the next run. Run one seeding process at a time; concurrent inserts may cause a unique-key error and transaction rollback, rather than duplicates.

## Read-only checks before the first seed

Using your approved PostgreSQL client and verified demo connection, run only these reads. Do not print connection passwords or password hashes. This review does not authorize connecting to AWS from a development task.

```sql
BEGIN READ ONLY;
SELECT current_database(), current_user, inet_server_addr(), inet_server_port(), current_schema();
SELECT id, name, email
FROM users
WHERE id BETWEEN -910003 AND -910001
   OR lower(email) IN ('test1@example.com', 'test2@example.com', 'test3@example.com')
ORDER BY id;
SELECT id, created_by, title, status
FROM job_positions
WHERE id BETWEEN -910005 AND -910001
ORDER BY id;
COMMIT;
```

Before the **first** seed, both record queries must return zero rows. If they return anything, stop and investigate provenance; do not rename, delete, or adopt those records to make the seeder succeed. On later runs, compare the results with the known seeded IDs/owners. The checks do not lock absent rows: run only one seeder and avoid concurrent account creation for the reserved emails.

## Credentials and safeguard

All four variables must be supplied in the **process environment** on every invocation, even on reruns:

- `DEMO_SEEDING_ENABLED=true` (exact value).
- `DEMO_USER_1_PASSWORD`
- `DEMO_USER_2_PASSWORD`
- `DEMO_USER_3_PASSWORD`

Each password must have 12–72 bytes, lowercase and uppercase letters, a number, and a symbol. Generate unique random passwords in a password manager or secrets manager. Passwords use the existing User hashed cast; the seeder never prints them or resets existing passwords. Validation is local and does not send credentials to a password breach service. Model events are suppressed to avoid notifications. Exported process variables work even when Laravel configuration is cached. Without configuration caching, Laravel dotenv normally populates `getenv()` too, so `.env` entries can be sufficient; with caching, dotenv loading is skipped. Use explicit process exports for predictable behavior and keep demo secrets and the opt-in out of persistent application configuration. Do not commit secrets, enable shell tracing, or paste passwords into command arguments.

## Local invocation

Confirm the configured database is a disposable local/demo database with the existing migrations applied. From the repository root, run this in Bash. Hidden prompts avoid shell history and echo; the subshell removes the variables when it exits:

```bash
bash <<'BASH'
set -e
set +x
read -r -s -p 'Demo user 1 password: ' DEMO_USER_1_PASSWORD </dev/tty; printf '\n'
read -r -s -p 'Demo user 2 password: ' DEMO_USER_2_PASSWORD </dev/tty; printf '\n'
read -r -s -p 'Demo user 3 password: ' DEMO_USER_3_PASSWORD </dev/tty; printf '\n'
export DEMO_USER_1_PASSWORD DEMO_USER_2_PASSWORD DEMO_USER_3_PASSWORD
export DEMO_SEEDING_ENABLED=true
php artisan db:seed --class=DemoSeeder
BASH
```

## AWS demo invocation

Connect through your existing approved SSH/SSM workflow and change to the deployed application's root. Verify the configured database endpoint/database name identifies the **AWS demo database**. Use the same hidden-prompt block above, replacing its final PHP command with:

```bash
APP_ENV=production php artisan db:seed --class=DemoSeeder --force
```

`--force` bypasses Laravel's production confirmation; the seeder still requires its separate opt-in. With cached configuration, the deployed environment/database configuration applies. Do not point this command at a customer/live production database. No deployment or remote execution is part of this change.

## Verification: database reset safety

Run tests from a development checkout or isolated CI workspace, **never from the AWS/deployed application directory**. `RefreshDatabase` can reset a database. Environment overrides alone do not establish safety when Laravel configuration is cached. Do not run `config:clear` against a deployment to prepare tests.

`Tests\TestCase::setUpTraits()` invokes `TestDatabaseSafety` **before** Laravel initializes `RefreshDatabase`, `DatabaseMigrations`, `DatabaseTruncation`, or database transactions, including tests outside Pest's Feature directory. The guard fails closed if configuration is cached, the effective environment is not `testing`, a URL/read-write override is present, or a connection is not explicitly permitted. Extra transaction connections are checked too. All database tests must extend this base class; do not bypass its trait setup, override migration targets, or add unguarded reset commands.

Allowed targets are:

- SQLite `:memory:` only; file databases are rejected.
- PostgreSQL at `127.0.0.1:55432`, database `digital_recruiter_seeder_test`, role `digital_recruiter_test`, schema `public`, with `TEST_ALLOW_DATABASE_RESET=disposable-postgres`. A read-only server query must independently confirm the actual database, role, schema, and database comment `digital-recruiter:disposable-tests:v1` before any migration/reset.

The marker is an explicit declaration that **all contents may be reset**. Never add it to an existing application database. A local tunnel or proxy is not isolation: do not forward port 55432 to AWS or any existing/shared server. Use a freshly provisioned disposable local PostgreSQL instance with no production data, credentials, mounts, or connectivity. Tests intentionally reject arbitrary database names, ports, URLs, and roles instead of offering an unsafe fallback.

### Available SQLite verification

In a development checkout, use a fresh private directory for an absent test-specific config-cache path. This leaves deployment/cache files untouched. `set -e` stops the block on failed inspection or tests.

```bash
bash <<'BASH'
set -e
set +x
demo_test_runtime=$(mktemp -d /tmp/digital-recruiter-tests.XXXXXX)
export APP_CONFIG_CACHE="$demo_test_runtime/config.php"
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=
php tests/inspect-database.php
vendor/bin/pest tests/Feature/DemoSeederTest.php tests/Feature/JobPositionTest.php tests/Feature/TestDatabaseSafetyTest.php
BASH
vendor/bin/pint --test database/seeders/DemoSeeder.php tests
vendor/bin/phpstan analyse --no-progress
```

`inspect-database.php` boots Laravel and prints only the effective environment, config-cache state/path, connection name, driver, host, port, database, role, and whether a URL is configured. It runs the same safety check without initializing any reset traits. Require `configuration_cached: false` and the final `PASS` message. It never prints credentials. If the cache file exists or the loaded-cache flag is true, it aborts; do not continue by changing only `DB_*`. This script is for test preflight, not production/demo connection inspection.

### PostgreSQL provisioning (not executed by this change)

If an isolated PostgreSQL instance is unavailable, stop here and record PostgreSQL verification as outstanding. Do not substitute an existing database.

The following is an optional provisioning recipe for a **new** ephemeral local container, from a development machine with Docker available. The fixed name/port must be free; if either is occupied, stop and investigate instead of reusing the occupant. The container uses temporary storage and a generated test-only password. Execute the subsequent blocks in the same Bash session with tracing disabled.

```bash
set -e
set +x
export POSTGRES_PASSWORD="$(openssl rand -hex 24)"
docker run --detach --rm --name digital-recruiter-seeder-verification \
  --tmpfs /var/lib/postgresql/data \
  --publish 127.0.0.1:55432:5432 \
  --env POSTGRES_DB=digital_recruiter_seeder_test \
  --env POSTGRES_USER=digital_recruiter_test \
  --env POSTGRES_PASSWORD postgres:17
```

Wait for this new container to report readiness with `docker exec digital-recruiter-seeder-verification pg_isready -U digital_recruiter_test -d digital_recruiter_seeder_test`. If not ready, wait and repeat that read-only readiness check. Do not run tests until it succeeds. The dedicated role is created inside this disposable server only; never supply AWS credentials. Declare this **new** database disposable:

```bash
docker exec -i digital-recruiter-seeder-verification \
  psql -X -U digital_recruiter_test -d digital_recruiter_seeder_test -v ON_ERROR_STOP=1 <<'SQL'
COMMENT ON DATABASE digital_recruiter_seeder_test IS 'digital-recruiter:disposable-tests:v1';
SQL
```

This database comment is test infrastructure metadata, not an application schema migration. It must never be applied to a deployed or existing application database.

### Inspect and verify PostgreSQL

In the same Bash session, configure every expected connection field, use an absent private config-cache path, and run read-only inspection **before** Pest. The dedicated PHPUnit configuration fixes the same fields independently of the normal application environment. No password is embedded in command arguments or repository files.

```bash
demo_test_runtime=$(mktemp -d /tmp/digital-recruiter-tests.XXXXXX)
export APP_CONFIG_CACHE="$demo_test_runtime/config.php"
export APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432
export DB_DATABASE=digital_recruiter_seeder_test DB_USERNAME=digital_recruiter_test DB_URL=
export DB_PASSWORD="$POSTGRES_PASSWORD"
export TEST_ALLOW_DATABASE_RESET=disposable-postgres
php tests/inspect-database.php
vendor/bin/pest --configuration=phpunit.postgres.xml \
  tests/Feature/DemoSeederTest.php tests/Feature/JobPositionTest.php tests/Feature/TestDatabaseSafetyTest.php
unset DB_PASSWORD POSTGRES_PASSWORD TEST_ALLOW_DATABASE_RESET
```

Require the inspector's `PASS`, `configuration_cached: false`, and the exact local host/port/database/role above. Inspection checks the server's actual database/role/schema and marker; setting `DB_*` or merely naming a database with a `_test` suffix is not sufficient. The guard repeats before every test's reset lifecycle. Do not run this suite with `--parallel`: per-worker databases are deliberately not authorized.

On PostgreSQL, the suite checks bigint/JSONB column types, JSON boolean values, array ordering and requirement links, foreign-key enforcement with savepoint rollback, ownership, archive version/state, password preservation, and repeat execution without changes or duplicates. SQLite skips the two PostgreSQL-only tests. Successful SQLite checks do not establish PostgreSQL runtime compatibility.

Keep the container disposable after verification. Its removal discards its temporary contents; do not reuse it for application data. Remaining deployment checks are actual schema parity and read-only reserved-ID/email checks against the intended demo database. No verification step here authorizes a reset of that database.
