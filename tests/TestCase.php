<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\TestDatabaseSafety;

abstract class TestCase extends BaseTestCase
{
    /**
     * Guard before RefreshDatabase, DatabaseMigrations, or DatabaseTruncation can run.
     * This also covers PHPUnit tests outside the Pest Feature directory.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        $connections = [];
        foreach (['connectionsToTransact', 'connectionsToTruncate'] as $method) {
            if (method_exists($this, $method)) {
                $connections = [...$connections, ...$this->{$method}()];
            }
        }
        if (method_exists($this, 'migrateFreshUsing')) {
            $connections[] = $this->migrateFreshUsing()['--database'] ?? null;
        }
        (new TestDatabaseSafety)->assertSafe($this->app, array_values($connections));

        return parent::setUpTraits();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
