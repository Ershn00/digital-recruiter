<?php

use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

function safetyConnection(array $overrides = []): Connection
{
    $config = array_replace(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => '55432',
        'database' => 'digital_recruiter_seeder_test', 'username' => 'digital_recruiter_test', 'search_path' => 'public'], $overrides);
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getConfig')->andReturn($config);
    $connection->shouldReceive('getDriverName')->andReturn($config['driver']);
    $connection->shouldReceive('getDatabaseName')->andReturn($config['database']);

    return $connection;
}

test('database guard rejects unsafe resolved connections before querying', function (array $overrides) {
    $connection = safetyConnection($overrides);
    $connection->shouldNotReceive('selectOne');
    expect(fn () => (new TestDatabaseSafety)->assertConnection($connection))->toThrow(RuntimeException::class, 'Test database safety:');
})->with([
    [['driver' => 'sqlite', 'database' => '/tmp/existing.sqlite']],
    [['driver' => 'mysql']], [['database' => 'digital_recruiter']],
    [['host' => 'demo.example.com']], [['port' => '5432']], [['username' => 'postgres']],
    [['search_path' => 'other']], [['url' => 'pgsql://unrelated']],
    [['read' => ['host' => 'other']]], [['write' => ['database' => 'other']]],
]);

test('database guard requires PostgreSQL opt in before querying', function () {
    $previous = getenv('TEST_ALLOW_DATABASE_RESET');
    putenv('TEST_ALLOW_DATABASE_RESET');
    try {
        $connection = safetyConnection();
        $connection->shouldNotReceive('selectOne');
        expect(fn () => (new TestDatabaseSafety)->assertConnection($connection))->toThrow(RuntimeException::class);
    } finally {
        putenv($previous === false ? 'TEST_ALLOW_DATABASE_RESET' : 'TEST_ALLOW_DATABASE_RESET='.$previous);
    }
});

test('database guard verifies actual server identity and marker using a read only query', function (string $field, string $value) {
    $previous = getenv('TEST_ALLOW_DATABASE_RESET');
    putenv('TEST_ALLOW_DATABASE_RESET=disposable-postgres');
    try {
        $identity = (object) ['database_name' => 'digital_recruiter_seeder_test', 'role_name' => 'digital_recruiter_test',
            'schema_name' => 'public', 'safety_marker' => 'digital-recruiter:disposable-tests:v1'];
        $identity->$field = $value;
        $connection = safetyConnection();
        $connection->shouldReceive('selectOne')->once()->withArgs(fn ($sql, $bindings, $read) => str_starts_with($sql, 'SELECT') && $bindings === [] && $read === false)->andReturn($identity);
        if ($field === 'safety_marker' && $value === 'digital-recruiter:disposable-tests:v1') {
            (new TestDatabaseSafety)->assertConnection($connection);
            expect(true)->toBeTrue();
        } else {
            expect(fn () => (new TestDatabaseSafety)->assertConnection($connection))->toThrow(RuntimeException::class, 'server identity');
        }
    } finally {
        putenv($previous === false ? 'TEST_ALLOW_DATABASE_RESET' : 'TEST_ALLOW_DATABASE_RESET='.$previous);
    }
})->with([
    ['database_name', 'live'], ['role_name', 'postgres'], ['schema_name', 'other'],
    ['safety_marker', ''], ['safety_marker', 'digital-recruiter:disposable-tests:v1'],
]);

test('database guard rejects cached configuration before resolving any database', function () {
    app()->instance('config_loaded_from_cache', true);
    try {
        expect(fn () => (new TestDatabaseSafety)->assertSafe(app()))->toThrow(RuntimeException::class, 'cached Laravel');
    } finally {
        app()->instance('config_loaded_from_cache', false);
    }
});

test('database guard rejects a non testing application environment', function () {
    app()->instance('env', 'production');
    try {
        expect(fn () => (new TestDatabaseSafety)->assertSafe(app()))->toThrow(RuntimeException::class, 'APP_ENV');
    } finally {
        app()->instance('env', 'testing');
    }
});

test('cached configuration rejection happens before RefreshDatabase initialization', function () {
    $probe = new class('probe') extends TestCase
    {
        use RefreshDatabase;

        public bool $resetReached = false;

        public function refreshDatabase(): void
        {
            // Probe only: never run a real reset from this regression test.
            $this->resetReached = true;
        }

        public function probe(Application $app): void
        {
            $this->app = $app;
            $this->setUpTraits();
        }
    };
    app()->instance('config_loaded_from_cache', true);
    try {
        expect(fn () => $probe->probe(app()))->toThrow(RuntimeException::class, 'cached Laravel');
        expect($probe->resetReached)->toBeFalse();
    } finally {
        app()->instance('config_loaded_from_cache', false);
    }
    $probe->probe(app());
    expect($probe->resetReached)->toBeTrue();
});
