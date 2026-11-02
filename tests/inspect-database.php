<?php

// Read-only preflight; deliberately does not initialize any database-reset traits.
use Illuminate\Contracts\Console\Kernel;
use Tests\Support\TestDatabaseSafety;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = $app->make('config');
$name = $config->get('database.default');
$connection = $app->make('db')->connection($name);

echo json_encode([
    'environment' => $app->environment(),
    'configuration_cached' => $app->configurationIsCached() || $app->make('config_loaded_from_cache'),
    'configuration_cache_path' => $app->getCachedConfigPath(),
    'connection' => $name,
    'driver' => $connection->getDriverName(),
    'host' => $connection->getConfig('host'),
    'port' => $connection->getConfig('port'),
    'database' => $connection->getDatabaseName(),
    'username' => $connection->getConfig('username'),
    'url_configured' => ! empty($connection->getConfig('url')),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

(new TestDatabaseSafety)->assertSafe($app);
echo "PASS: database identity approved for isolated tests; no reset performed.\n";
