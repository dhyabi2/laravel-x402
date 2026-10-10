<?php

declare(strict_types=1);

namespace HydraLabs\X402\Http\Controllers;

use HydraLabs\X402\Config\X402Config;
use HydraLabs\X402\Discovery\RouteCatalog;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\JsonResponse;

/**
 * Serves the machine-readable catalogue of paid routes.
 *
 * Shape follows the x402 discovery API (core spec v2 §8.3). There is no
 * `/.well-known/x402` in the specification; see docs/SPEC_NOTES.md §11.
 */
final class DiscoveryController
{
    public function __construct(
        private readonly RouteCatalog $catalog,
        private readonly X402Config $config,
        private readonly Repository $cache,
    ) {}

    public function __invoke(): JsonResponse
    {
        $ttl = $this->config->discoveryCacheTtl();

        $document = $ttl > 0
            ? $this->cache->remember('x402:discovery', $ttl, fn (): array => $this->catalog->toDiscoveryDocument())
            : $this->catalog->toDiscoveryDocument();

        return new JsonResponse($document, 200, [
            'Cache-Control' => sprintf('public, max-age=%d', max(0, $ttl)),
        ]);
    }
}
