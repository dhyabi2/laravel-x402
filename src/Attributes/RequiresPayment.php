<?php

declare(strict_types=1);

namespace Requestway\X402\Attributes;

use Attribute;

/**
 * Declares a controller or action as paid.
 *
 * Applied by the `x402` middleware when it is present without a price, or
 * app-wide by pushing Requestway\X402\Http\Middleware\HonorPaymentAttributes
 * into a middleware group.
 *
 *     #[RequiresPayment(price: '0.01', description: 'Market snapshot')]
 *     public function show(): JsonResponse
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class RequiresPayment
{
    /**
     * @param string|int|float|null $price USD price, or the name of a configured tier
     * @param list<string> $networks additional CAIP-2 networks to accept
     * @param list<string> $tags discovery tags
     */
    public function __construct(
        public readonly string|int|float|null $price = null,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $network = null,
        public readonly array $networks = [],
        public readonly ?string $payTo = null,
        public readonly ?bool $freeForAuth = null,
        public readonly ?string $settlement = null,
        public readonly ?int $maxTimeoutSeconds = null,
        public readonly array $tags = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toOptions(): array
    {
        return array_filter([
            'price' => $this->price,
            'description' => $this->description,
            'mime_type' => $this->mimeType,
            'network' => $this->network,
            'networks' => $this->networks === [] ? null : $this->networks,
            'pay_to' => $this->payTo,
            'free_for_auth' => $this->freeForAuth,
            'settlement' => $this->settlement,
            'max_timeout_seconds' => $this->maxTimeoutSeconds,
            'tags' => $this->tags === [] ? null : $this->tags,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
