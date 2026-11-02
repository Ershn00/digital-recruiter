<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use RuntimeException;

final class TestDatabaseSafety
{
    /** @param list<string|null> $connections */
    public function assertSafe(Application $app, array $connections = []): void
    {
        if ($app->configurationIsCached()
            || ($app->bound('config_loaded_from_cache') && $app->make('config_loaded_from_cache'))) {
            throw new RuntimeException('Test database safety: cached Laravel configuration is forbidden.');
        }
        if ($app->environment() !== 'testing') {
            throw new RuntimeException('Test database safety: APP_ENV must resolve to testing.');
        }

        $default = $app->make('config')->get('database.default');
        foreach (array_unique([$default, ...$connections]) as $name) {
            $this->assertConnection($app->make('db')->connection($name));
        }
    }

    public function assertConnection(Connection $connection): void
    {
        // Inspect the resolved connection, including any parsed URL, before opening PDO.
        $config = $connection->getConfig();
        if (! empty($config['url']) || ! empty($config['read']) || ! empty($config['write'])) {
            throw new RuntimeException('Test database safety: URLs and read/write connection overrides are forbidden.');
        }
        if ($connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:') {
            return;
        }
        if ($connection->getDriverName() !== 'pgsql'
            || ($config['host'] ?? null) !== '127.0.0.1'
            || (string) ($config['port'] ?? '') !== '55432'
            || $connection->getDatabaseName() !== 'digital_recruiter_seeder_test'
            || ($config['username'] ?? null) !== 'digital_recruiter_test'
            || ($config['search_path'] ?? null) !== 'public'
            || getenv('TEST_ALLOW_DATABASE_RESET') !== 'disposable-postgres') {
            throw new RuntimeException('Test database safety: only in-memory SQLite or the explicitly approved local disposable PostgreSQL database is allowed.');
        }

        // The database itself must attest its identity. No DDL or writes precede this check.
        $identity = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database_name, current_user AS role_name,
                   current_schema() AS schema_name,
                   shobj_description(oid, 'pg_database') AS safety_marker
            FROM pg_database WHERE datname = current_database()
            SQL, [], false);
        $identity = is_object($identity) ? get_object_vars($identity) : [];
        if (($identity['database_name'] ?? null) !== 'digital_recruiter_seeder_test'
            || ($identity['role_name'] ?? null) !== 'digital_recruiter_test'
            || ($identity['schema_name'] ?? null) !== 'public'
            || ($identity['safety_marker'] ?? null) !== 'digital-recruiter:disposable-tests:v1') {
            throw new RuntimeException('Test database safety: PostgreSQL server identity or disposable-database marker does not match.');
        }
    }
}
