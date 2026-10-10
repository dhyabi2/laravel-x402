<?php

declare(strict_types=1);

namespace Requestway\X402\Routing;

use Requestway\X402\Core\Money\UsdConverter;
use Requestway\X402\Enums\SettlementMode;

/**
 * The fully resolved payment configuration for one route, after config
 * defaults, the `paid()` macro, the #[RequiresPayment] attribute, middleware
 * parameters and any dynamic price resolver have been applied.
 */
final class RoutePayment
{
    /**
     * @param string $price USD price as a decimal string
     * @param list<string> $networks CAIP-2 identifiers, in advertised order
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $price,
        public readonly array $networks,
        public readonly string $payTo,
        public readonly string $scheme,
        public readonly int $maxTimeoutSeconds,
        public readonly SettlementMode $settlement,
        public readonly bool $freeForAuth = false,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $serviceName = null,
        public readonly ?string $iconUrl = null,
        public readonly array $tags = [],
        public readonly ?string $routeName = null,
        public readonly ?string $routeUri = null,
        public readonly ?string $method = null,
    ) {}

    public function isFree(): bool
    {
        return UsdConverter::parsePrice($this->price) === '0';
    }

    public function formattedPrice(): string
    {
        $price = UsdConverter::parsePrice($this->price);

        return '$'.(str_contains($price, '.') ? $price : $price.'.00');
    }

    /**
     * A stable label for logs, events and the x402:routes table.
     */
    public function label(): string
    {
        return $this->routeName ?? trim(($this->method ?? '').' '.($this->routeUri ?? '')) ?: 'unknown';
    }

    public function withPrice(string $price): self
    {
        return new self(
            price: $price,
            networks: $this->networks,
            payTo: $this->payTo,
            scheme: $this->scheme,
            maxTimeoutSeconds: $this->maxTimeoutSeconds,
            settlement: $this->settlement,
            freeForAuth: $this->freeForAuth,
            description: $this->description,
            mimeType: $this->mimeType,
            serviceName: $this->serviceName,
            iconUrl: $this->iconUrl,
            tags: $this->tags,
            routeName: $this->routeName,
            routeUri: $this->routeUri,
            method: $this->method,
        );
    }
}
