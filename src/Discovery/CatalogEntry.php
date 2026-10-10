<?php

declare(strict_types=1);

namespace HydraLabs\X402\Discovery;

use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Routing\RoutePayment;
use Illuminate\Contracts\Routing\UrlGenerator;

/**
 * One paid route, as reported by discovery and x402:routes.
 */
final class CatalogEntry
{
    /**
     * @param list<PaymentRequirements> $accepts
     * @param list<string> $bypass
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $method,
        public readonly ?string $name,
        public readonly string $action,
        public readonly ?RoutePayment $payment,
        public readonly array $accepts,
        public readonly array $bypass = [],
        public readonly ?string $error = null,
    ) {}

    public function resourceUrl(UrlGenerator $url): string
    {
        return $url->to($this->uri);
    }

    public function price(): string
    {
        return $this->payment?->formattedPrice() ?? '-';
    }

    /**
     * @return list<string>
     */
    public function networks(): array
    {
        return array_values(array_map(
            static fn (PaymentRequirements $requirements): string => $requirements->network,
            $this->accepts
        ));
    }

    public function isMisconfigured(): bool
    {
        return $this->error !== null;
    }
}
