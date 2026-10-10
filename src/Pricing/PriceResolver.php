<?php

declare(strict_types=1);

namespace Requestway\X402\Pricing;

use Illuminate\Http\Request;
use Requestway\X402\Routing\RoutePayment;

/**
 * Computes a route's price at request time.
 *
 * Return null to fall back to the statically configured price.
 */
interface PriceResolver
{
    public function resolve(Request $request, RoutePayment $route): string|int|float|null;
}
