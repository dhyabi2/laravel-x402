<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Networks\Network;
use HydraLabs\X402\Tests\TestCase;
use Illuminate\Support\Facades\File;

it('publishes the config and migration without prompting', function (): void {
    [$status, $output] = runCommand('x402:install', ['--no-prompt' => true]);

    expect($status)->toBe(0)
        ->and($output)->toContain('config/x402.php', 'database/migrations')
        ->and($output)->toContain('php artisan migrate', 'x402:doctor', 'x402:routes', 'x402:test');

    expect(File::exists(config_path('x402.php')))->toBeTrue();

    File::delete(config_path('x402.php'));
});

it('prompts for a wallet, network and facilitator, and writes them to .env', function (): void {
    File::put(base_path('.env'), "APP_ENV=testing\nX402_PAY_TO=0x0000000000000000000000000000000000000000\n");

    $this->artisan('x402:install')
        ->expectsQuestion('Which wallet address should payments go to?', TestCase::PAY_TO)
        ->expectsChoice('Which network?', Network::BASE_SEPOLIA, [
            Network::BASE_SEPOLIA => 'Base Sepolia — testnet, recommended to start',
            Network::BASE => 'Base — mainnet, real money',
            'other' => 'Something else (enter a CAIP-2 identifier)',
        ])
        ->expectsQuestion('Facilitator URL', 'https://x402.org/facilitator')
        ->expectsConfirmation('Write these values to your .env file?', 'yes')
        ->assertSuccessful();

    $env = File::get(base_path('.env'));

    expect($env)->toContain('X402_PAY_TO='.TestCase::PAY_TO)
        ->and($env)->toContain('X402_NETWORK=eip155:84532')
        ->and($env)->toContain('X402_FACILITATOR_URL=https://x402.org/facilitator')
        // The existing placeholder was replaced, not duplicated.
        ->and(substr_count($env, 'X402_PAY_TO='))->toBe(1);

    File::delete([base_path('.env'), config_path('x402.php')]);
});

it('prints the values instead of writing them when the operator declines', function (): void {
    File::put(base_path('.env'), "APP_ENV=testing\n");

    $this->artisan('x402:install')
        ->expectsQuestion('Which wallet address should payments go to?', TestCase::PAY_TO)
        ->expectsChoice('Which network?', Network::BASE, [
            Network::BASE_SEPOLIA => 'Base Sepolia — testnet, recommended to start',
            Network::BASE => 'Base — mainnet, real money',
            'other' => 'Something else (enter a CAIP-2 identifier)',
        ])
        ->expectsQuestion('Facilitator URL', 'https://api.cdp.coinbase.com/platform/v2/x402')
        ->expectsConfirmation('Write these values to your .env file?', 'no')
        ->expectsOutputToContain('X402_PAY_TO='.TestCase::PAY_TO)
        ->assertSuccessful();

    expect(File::get(base_path('.env')))->not->toContain('X402_PAY_TO');

    File::delete([base_path('.env'), config_path('x402.php')]);
});

it('warns when the operator picks a mainnet', function (): void {
    File::put(base_path('.env'), "APP_ENV=testing\n");

    $this->artisan('x402:install')
        ->expectsQuestion('Which wallet address should payments go to?', TestCase::PAY_TO)
        ->expectsChoice('Which network?', Network::BASE, [
            Network::BASE_SEPOLIA => 'Base Sepolia — testnet, recommended to start',
            Network::BASE => 'Base — mainnet, real money',
            'other' => 'Something else (enter a CAIP-2 identifier)',
        ])
        ->expectsQuestion('Facilitator URL', 'https://api.cdp.coinbase.com/platform/v2/x402')
        ->expectsConfirmation('Write these values to your .env file?', 'no')
        ->expectsOutputToContain('a mainnet')
        ->assertSuccessful();

    File::delete([base_path('.env'), config_path('x402.php')]);
});

it('accepts a CAIP-2 identifier for a network that is not offered', function (): void {
    File::put(base_path('.env'), "APP_ENV=testing\n");

    $this->artisan('x402:install')
        ->expectsQuestion('Which wallet address should payments go to?', TestCase::PAY_TO)
        ->expectsChoice('Which network?', 'other', [
            Network::BASE_SEPOLIA => 'Base Sepolia — testnet, recommended to start',
            Network::BASE => 'Base — mainnet, real money',
            'other' => 'Something else (enter a CAIP-2 identifier)',
        ])
        ->expectsQuestion('CAIP-2 network identifier', 'eip155:42161')
        ->expectsQuestion('Facilitator URL', 'https://facilitator.test')
        ->expectsConfirmation('Write these values to your .env file?', 'yes')
        ->assertSuccessful();

    expect(File::get(base_path('.env')))->toContain('X402_NETWORK=eip155:42161');

    File::delete([base_path('.env'), config_path('x402.php')]);
});

it('explains what to add by hand when there is no .env file', function (): void {
    File::delete(base_path('.env'));

    $this->artisan('x402:install')
        ->expectsQuestion('Which wallet address should payments go to?', TestCase::PAY_TO)
        ->expectsChoice('Which network?', Network::BASE_SEPOLIA, [
            Network::BASE_SEPOLIA => 'Base Sepolia — testnet, recommended to start',
            Network::BASE => 'Base — mainnet, real money',
            'other' => 'Something else (enter a CAIP-2 identifier)',
        ])
        ->expectsQuestion('Facilitator URL', 'https://x402.org/facilitator')
        ->expectsOutputToContain('No .env file found')
        ->expectsOutputToContain('X402_PAY_TO='.TestCase::PAY_TO)
        ->assertSuccessful();

    File::delete(config_path('x402.php'));
});
