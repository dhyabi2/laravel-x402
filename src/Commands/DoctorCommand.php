<?php

declare(strict_types=1);

namespace Requestway\X402\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\Schema;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorException;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Messages\SupportedKind;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Discovery\CatalogEntry;
use Requestway\X402\Discovery\RouteCatalog;
use Requestway\X402\Enums\Mode;
use Requestway\X402\Models\X402Payment;
use Throwable;

/**
 * Checks everything that has to be right for a payment to succeed, and says
 * what to do about anything that is not.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'x402:doctor {--skip-facilitator : Do not make a network call to the facilitator}';

    protected $description = 'Check your x402 configuration, wallet, facilitator, cache and migrations';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(X402Config $config, RouteCatalog $catalog, CacheFactory $cache): int
    {
        $this->newLine();
        $this->components->info('x402 diagnostics');

        $this->checkMode($config);
        $this->checkWallet($config);
        $networks = $this->checkNetworks($config);
        $this->checkAssets($config, $networks);
        $this->checkCache($config, $cache);
        $this->checkMigration($config);
        $this->checkRoutes($catalog);

        if (! $this->option('skip-facilitator')) {
            $this->checkFacilitator($config, $networks);
        }

        $this->newLine();

        if ($this->failures > 0) {
            $this->components->error(sprintf(
                '%d check(s) failed%s.',
                $this->failures,
                $this->warnings > 0 ? sprintf(', %d warning(s)', $this->warnings) : ''
            ));

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->components->warn(sprintf('Ready, with %d warning(s).', $this->warnings));

            return self::SUCCESS;
        }

        $this->components->info('Everything checks out.');

        return self::SUCCESS;
    }

    private function checkMode(X402Config $config): void
    {
        $mode = $config->mode();

        match ($mode) {
            Mode::Enforce => $this->reportPass('Mode', 'enforce'),
            Mode::Observe => $this->reportWarning('Mode', 'observe — requests are served and nothing is charged'),
            Mode::Off => $this->reportWarning('Mode', 'off — protected routes are not protected'),
        };

        $this->reportPass('Settlement', $config->settlementMode()->value);
    }

    private function checkWallet(X402Config $config): void
    {
        try {
            $this->reportPass('Wallet', $config->payTo());
        } catch (ConfigurationException $exception) {
            $this->reportFailure('Wallet', $exception->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function checkNetworks(X402Config $config): array
    {
        try {
            $networks = $config->networks();
        } catch (ConfigurationException $exception) {
            $this->reportFailure('Networks', $exception->getMessage());

            return [];
        }

        foreach ($networks as $network) {
            if (Network::isTestnet($network)) {
                $this->reportPass('Network', sprintf('%s (testnet)', Network::label($network)));
            } else {
                $this->reportWarning('Network', sprintf('%s — mainnet: payments are real', Network::label($network)));
            }
        }

        return $networks;
    }

    /**
     * @param list<string> $networks
     */
    private function checkAssets(X402Config $config, array $networks): void
    {
        foreach ($networks as $network) {
            if (! $config->assets()->has($network)) {
                $this->reportFailure('Asset', sprintf(
                    'No asset configured for %s. Add one under x402.assets.',
                    Network::label($network)
                ));

                continue;
            }

            $asset = $config->assets()->for($network);

            $this->reportPass('Asset', sprintf(
                '%s on %s (%d decimals)',
                $asset->symbol,
                Network::label($network),
                $asset->decimals
            ));
        }
    }

    private function checkCache(X402Config $config, CacheFactory $cache): void
    {
        $store = $config->replayStore();
        $name = $store ?? (string) config('cache.default', 'file');

        try {
            $repository = $cache->store($store);
            $key = 'x402:doctor:'.bin2hex(random_bytes(6));

            if (! $repository->add($key, true, 10)) {
                $this->reportFailure('Replay cache', sprintf('Store "%s" did not accept a write.', $name));

                return;
            }

            $repository->forget($key);
        } catch (Throwable $exception) {
            $this->reportFailure('Replay cache', sprintf('Store "%s" is unusable: %s', $name, $exception->getMessage()));

            return;
        }

        // Replay claims must be visible to every web node, so a per-process or
        // per-server store silently weakens the protection.
        if (in_array($name, ['array', 'file'], true)) {
            $this->reportWarning('Replay cache', sprintf(
                'Store "%s" is not shared between servers. Use redis, memcached, dynamodb or database in production.',
                $name
            ));

            return;
        }

        $this->reportPass('Replay cache', sprintf('%s, %ds retention', $name, $config->replayTtl()));
    }

    private function checkMigration(X402Config $config): void
    {
        if (! $config->databaseEnabled()) {
            $this->reportWarning('Database', 'Recording is disabled; payments are not stored.');

            return;
        }

        try {
            $connection = $config->databaseConnection();
            $exists = Schema::connection($connection)->hasTable($config->table());
        } catch (Throwable $exception) {
            $this->reportFailure('Database', sprintf('Could not reach the database: %s', $exception->getMessage()));

            return;
        }

        if (! $exists) {
            $this->reportFailure('Database', sprintf(
                'Table "%s" is missing. Run: php artisan vendor:publish --tag=x402-migrations && php artisan migrate',
                $config->table()
            ));

            return;
        }

        try {
            $this->reportPass('Database', sprintf(
                '%s, %d payment(s) recorded',
                $config->table(),
                X402Payment::query()->count()
            ));
        } catch (Throwable $exception) {
            $this->reportFailure('Database', $exception->getMessage());
        }
    }

    private function checkRoutes(RouteCatalog $catalog): void
    {
        $entries = $catalog->entries();

        if ($entries === []) {
            $this->reportWarning('Routes', 'No paid routes found.');

            return;
        }

        $broken = array_values(array_filter(
            $entries,
            static fn (CatalogEntry $entry): bool => $entry->isMisconfigured()
        ));

        if ($broken !== []) {
            foreach ($broken as $entry) {
                $this->reportFailure('Route', sprintf('%s %s: %s', $entry->method, $entry->uri, (string) $entry->error));
            }

            return;
        }

        $this->reportPass('Routes', sprintf('%d paid route(s)', count($entries)));
    }

    /**
     * @param list<string> $networks
     */
    private function checkFacilitator(X402Config $config, array $networks): void
    {
        try {
            $supported = $this->laravel->make(Facilitator::class)->supported();
        } catch (FacilitatorException $exception) {
            $this->reportFailure('Facilitator', sprintf('%s (%s)', $exception->getMessage(), $config->facilitatorUrl()));

            return;
        } catch (Throwable $exception) {
            $this->reportFailure('Facilitator', $exception->getMessage());

            return;
        }

        $this->reportPass('Facilitator', sprintf(
            '%s, %d kind(s) supported',
            $config->facilitatorUrl(),
            count($supported->kinds)
        ));

        $scheme = $config->scheme();

        foreach ($networks as $network) {
            if ($supported->supports($scheme, $network)) {
                $this->reportPass('Facilitator support', sprintf('%s on %s', $scheme, Network::label($network)));

                continue;
            }

            $this->reportFailure('Facilitator support', sprintf(
                'The facilitator does not support %s on %s. It supports: %s',
                $scheme,
                Network::label($network),
                $supported->kinds === [] ? 'nothing' : implode(', ', array_map(
                    static fn (SupportedKind $kind): string => sprintf('%s/%s', $kind->scheme, $kind->network),
                    $supported->kinds
                ))
            ));
        }
    }

    private function reportPass(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, sprintf('%s <fg=green;options=bold>OK</>', $detail));
    }

    private function reportWarning(string $label, string $detail): void
    {
        $this->warnings++;
        $this->components->twoColumnDetail($label, sprintf('%s <fg=yellow;options=bold>WARN</>', $detail));
    }

    private function reportFailure(string $label, string $detail): void
    {
        $this->failures++;
        $this->components->twoColumnDetail($label, sprintf('%s <fg=red;options=bold>FAIL</>', $detail));
    }
}
