<?php

declare(strict_types=1);

namespace HydraLabs\X402\Tests\Support;

use HydraLabs\X402\Pricing\PriceResolver;
use HydraLabs\X402\Routing\RoutePayment;
use Illuminate\Http\Request;

final class FlatRatePriceResolver implements PriceResolver
{
    public function resolve(Request $request, RoutePayment $route): string|int|float|null
    {
        return $request->query('bulk') === 'yes' ? '1.00' : null;
    }
}
