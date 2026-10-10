<?php

declare(strict_types=1);

namespace Requestway\X402\Pricing;

use Closure;
use Illuminate\Http\Request;
use Requestway\X402\Routing\RoutePayment;

/**
 * Wraps the closure given to X402::priceUsing().
 */
final class CallbackPriceResolver implements PriceResolver
{
    public function __construct(private readonly Closure $callback) {}

    public function resolve(Request $request, RoutePayment $route): string|int|float|null
    {
        /** @var string|int|float|null $price */
        $price = ($this->callback)($request, $route);

        return $price;
    }
}
