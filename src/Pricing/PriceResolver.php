<?php

declare(strict_types=1);

namespace HydraLabs\X402\Pricing;

use HydraLabs\X402\Routing\RoutePayment;
use Illuminate\Http\Request;

/**
 * Computes a route's price at request time.
 *
 * Return null to fall back to the statically configured price.
 */
interface PriceResolver
{
    public function resolve(Request $request, RoutePayment $route): string|int|float|null;
}
