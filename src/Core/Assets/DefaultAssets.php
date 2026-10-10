<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Assets;

/**
 * Default USD-pegged assets per CAIP-2 network.
 *
 * Mirrors the canonical table in the x402 repository
 * (typescript/packages/mechanisms/evm/src/defaultAssets.ts). Every entry is
 * treated as USD-pegged, per the spec's USD-peg invariant. `name` and `version`
 * are the token's EIP-712 domain values and must match the deployed contract.
 *
 * Override or extend from config; nothing here is hard-coded into the flow.
 */
final class DefaultAssets
{
    /**
     * @return array<string, array{address: string, decimals: int, symbol: string, name: string, version: string}>
     */
    public static function table(): array
    {
        return [
            'eip155:1' => [
                'address' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USD Coin', 'version' => '2',
            ],
            'eip155:14' => [
                'address' => '0xe7cd86e13AC4309349F30B3435a9d337750fC82D',
                'decimals' => 6, 'symbol' => 'USDT0', 'name' => "USD\u{20AE}0", 'version' => '1',
            ],
            'eip155:50' => [
                'address' => '0xfA2958CB79b0491CC627c1557F441eF849Ca8eb1',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:51' => [
                'address' => '0xb5AB69F7bBada22B28e79C8FFAECe55eF1c771D4',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:137' => [
                'address' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USD Coin', 'version' => '2',
            ],
            'eip155:143' => [
                'address' => '0x754704Bc059F8C67012fEd69BC8A327a5aafb603',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:1328' => [
                'address' => '0x4fCF1784B31630811181f670Aea7A7bEF803eaED',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:1329' => [
                'address' => '0xe15fC38F6D8c56aF07bbCBe3BAf5708A2Bf42392',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:5042' => [
                'address' => '0x3600000000000000000000000000000000000000',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:5042002' => [
                'address' => '0x3600000000000000000000000000000000000000',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:8453' => [
                'address' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USD Coin', 'version' => '2',
            ],
            'eip155:10143' => [
                'address' => '0x534b2f3A21130d7a60830c2Df862319e593943A3',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:42161' => [
                'address' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USD Coin', 'version' => '2',
            ],
            'eip155:42220' => [
                'address' => '0xcebA9300f2b948710d2653dD7B07f33A8B32118C',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:43114' => [
                'address' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USD Coin', 'version' => '2',
            ],
            'eip155:84532' => [
                'address' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
            'eip155:421614' => [
                'address' => '0x75faf114eafb1BDbe2F0316DF893fd58CE46AA4d',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USD Coin', 'version' => '2',
            ],
            'eip155:11142220' => [
                'address' => '0x01C5C0122039549AD1493B8220cABEdD739BC44E',
                'decimals' => 6, 'symbol' => 'USDC', 'name' => 'USDC', 'version' => '2',
            ],
        ];
    }
}
