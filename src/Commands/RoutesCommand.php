<?php

declare(strict_types=1);

namespace HydraLabs\X402\Commands;

use HydraLabs\X402\Config\X402Config;
use HydraLabs\X402\Core\Networks\Network;
use HydraLabs\X402\Discovery\CatalogEntry;
use HydraLabs\X402\Discovery\RouteCatalog;
use Illuminate\Console\Command;

final class RoutesCommand extends Command
{
    protected $signature = 'x402:routes {--json : Output the catalogue as JSON}';

    protected $description = 'List every paid route, its price and its bypass rules';

    public function handle(RouteCatalog $catalog, X402Config $config): int
    {
        $entries = $catalog->entries();

        if ($this->option('json')) {
            $this->line((string) json_encode($catalog->toDiscoveryDocument(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($entries === []) {
            $this->components->warn('No paid routes found.');
            $this->line("  Protect one with: Route::get('/report', …)->middleware('x402:0.01')");

            return self::SUCCESS;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Mode</>', $this->modeLabel($config));
        $this->components->twoColumnDetail('<fg=gray>Settlement</>', $config->settlementMode()->value);
        $this->components->twoColumnDetail(
            '<fg=gray>Wallet</>',
            $config->hasPayTo() ? (string) $config->get('pay_to') : '<fg=red>not configured</>'
        );

        $tiers = $config->tiers();

        if ($tiers !== []) {
            $this->components->twoColumnDetail('<fg=gray>Tiers</>', implode('  ', array_map(
                static fn (string $name, string|int|float $price): string => sprintf('%s=$%s', $name, $price),
                array_keys($tiers),
                array_values($tiers)
            )));
        }

        $this->newLine();

        $this->table(
            ['Method', 'URI', 'Price', 'Networks', 'Bypass', 'Description'],
            array_map(fn (CatalogEntry $entry): array => [
                $entry->method,
                $entry->uri,
                $entry->isMisconfigured() ? '<fg=red>error</>' : $entry->price(),
                $entry->isMisconfigured()
                    ? '-'
                    : implode(', ', array_map(
                        static fn (string $network): string => Network::label($network),
                        $entry->networks()
                    )),
                $entry->bypass === [] ? '-' : implode(', ', $entry->bypass),
                $this->truncate($entry->payment !== null ? $entry->payment->description : $entry->error),
            ], $entries),
        );

        $misconfigured = array_values(array_filter(
            $entries,
            static fn (CatalogEntry $entry): bool => $entry->isMisconfigured()
        ));

        foreach ($misconfigured as $entry) {
            $this->components->error(sprintf('%s %s: %s', $entry->method, $entry->uri, (string) $entry->error));
        }

        return $misconfigured === [] ? self::SUCCESS : self::FAILURE;
    }

    private function modeLabel(X402Config $config): string
    {
        return match ($config->mode()->value) {
            'enforce' => '<fg=green>enforce</>',
            'observe' => '<fg=yellow>observe (nothing is charged)</>',
            default => '<fg=red>off</>',
        };
    }

    private function truncate(?string $value, int $length = 40): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return strlen($value) <= $length ? $value : substr($value, 0, $length - 1).'…';
    }
}
