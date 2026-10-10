<?php

declare(strict_types=1);

namespace Requestway\X402\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Core\Support\EvmAddress;

final class InstallCommand extends Command
{
    protected $signature = 'x402:install
        {--force : Overwrite an existing published config file}
        {--no-prompt : Publish files without asking for wallet details}';

    protected $description = 'Publish the x402 config and migration, and set up your wallet';

    public function handle(Filesystem $files): int
    {
        $this->components->info('Setting up x402 payments.');

        $this->callSilently('vendor:publish', [
            '--tag' => 'x402-config',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->callSilently('vendor:publish', [
            '--tag' => 'x402-migrations',
            '--force' => (bool) $this->option('force'),
        ]);

        $this->components->twoColumnDetail('Config', 'config/x402.php');
        $this->components->twoColumnDetail('Migration', 'database/migrations');

        if ($this->option('no-prompt') || ! $this->input->isInteractive()) {
            $this->nextSteps();

            return self::SUCCESS;
        }

        $wallet = $this->askForWallet();
        $network = $this->askForNetwork();
        $facilitator = $this->askForFacilitator($network);

        $this->writeEnvironment($files, [
            'X402_PAY_TO' => $wallet,
            'X402_NETWORK' => $network,
            'X402_FACILITATOR_URL' => $facilitator,
        ]);

        if (Network::isTestnet($network)) {
            $this->components->info(sprintf('Using %s. No real funds can move.', Network::label($network)));
        } else {
            $this->components->warn(sprintf(
                'Using %s — a mainnet. Payments to %s will be real.',
                Network::label($network),
                $wallet
            ));
        }

        $this->nextSteps();

        return self::SUCCESS;
    }

    private function askForWallet(): string
    {
        return text(
            label: 'Which wallet address should payments go to?',
            placeholder: '0x…',
            required: true,
            validate: fn (string $value): ?string => EvmAddress::isValid(trim($value))
                ? null
                : 'Enter a 0x-prefixed 40-character EVM address.',
            hint: 'Funds move straight from the payer to this address. This package never holds them.',
        );
    }

    private function askForNetwork(): string
    {
        $choice = select(
            label: 'Which network?',
            options: [
                Network::BASE_SEPOLIA => 'Base Sepolia — testnet, recommended to start',
                Network::BASE => 'Base — mainnet, real money',
                'other' => 'Something else (enter a CAIP-2 identifier)',
            ],
            default: Network::BASE_SEPOLIA,
        );

        if ($choice !== 'other') {
            return (string) $choice;
        }

        return Network::normalize(text(
            label: 'CAIP-2 network identifier',
            placeholder: 'eip155:42161',
            required: true,
            validate: function (string $value): ?string {
                try {
                    Network::normalize(trim($value));

                    return null;
                } catch (\Throwable $exception) {
                    return $exception->getMessage();
                }
            },
        ));
    }

    private function askForFacilitator(string $network): string
    {
        $default = Network::isTestnet($network)
            ? 'https://x402.org/facilitator'
            : 'https://api.cdp.coinbase.com/platform/v2/x402';

        $url = text(
            label: 'Facilitator URL',
            default: $default,
            required: true,
            hint: Network::isTestnet($network)
                ? 'x402.org is free and needs no account, for testnets only.'
                : 'Mainnet needs a production facilitator. x402.org will not settle mainnet payments.',
        );

        return rtrim(trim($url), '/');
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnvironment(Filesystem $files, array $values): void
    {
        $path = $this->laravel->environmentFilePath();

        if (! $files->exists($path)) {
            $this->components->warn('No .env file found. Add these manually:');

            foreach ($values as $key => $value) {
                $this->line(sprintf('  %s=%s', $key, $value));
            }

            return;
        }

        if (! confirm(label: 'Write these values to your .env file?', default: true)) {
            foreach ($values as $key => $value) {
                $this->line(sprintf('  %s=%s', $key, $value));
            }

            return;
        }

        $contents = $files->get($path);

        foreach ($values as $key => $value) {
            $line = sprintf('%s=%s', $key, $value);

            $contents = preg_match('/^'.preg_quote($key, '/').'=.*$/m', $contents) === 1
                ? (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        $files->put($path, $contents);

        foreach ($values as $key => $value) {
            $this->components->twoColumnDetail($key, $value);
        }
    }

    private function nextSteps(): void
    {
        $this->newLine();
        $this->components->bulletList([
            'Run: php artisan migrate',
            "Protect a route: Route::get('/report', …)->middleware('x402:0.01')",
            'Check your setup: php artisan x402:doctor',
            'List paid routes: php artisan x402:routes',
            'Try the full exchange: php artisan x402:test /report',
        ]);
    }
}
