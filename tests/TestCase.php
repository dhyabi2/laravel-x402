<?php

declare(strict_types=1);

namespace Requestway\X402\Tests;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\TestCase as Orchestra;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\X402ServiceProvider;

abstract class TestCase extends Orchestra
{
    public const PAY_TO = '0x209693Bc6afc0C5328bA36FaF03C514EF312287C';

    public const PAYER = '0x857b06519E91e3A54538791bDbb0E22373e36b66';

    public const FACILITATOR = 'https://facilitator.test';

    /**
     * Config applied every time the application is created, so a test can
     * change something the service provider only reads at boot — a route path,
     * a toggle — and then call refreshApplication().
     *
     * @var array<string, mixed>
     */
    protected array $configOverrides = [];

    protected function getPackageProviders($app): array
    {
        return [X402ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        tap($app->make(Repository::class), function (Repository $config): void {
            $config->set('database.default', 'testing');
            $config->set('cache.default', 'array');
            $config->set('queue.default', 'sync');

            $config->set('x402.pay_to', self::PAY_TO);
            $config->set('x402.network', Network::BASE_SEPOLIA);
            $config->set('x402.facilitator.url', self::FACILITATOR);
            $config->set('x402.facilitator.retries', 0);
            $config->set('x402.database.enabled', true);
            $config->set('x402.discovery.cache_ttl', 0);

            foreach ($this->configOverrides as $key => $value) {
                $config->set($key, $value);
            }
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $migration = include __DIR__.'/../database/migrations/create_x402_payments_table.php.stub';
        $migration->up();
    }
}
