<?php

declare(strict_types=1);

namespace Requestway\X402\Tests\Support;

use Illuminate\Http\Request;
use Requestway\X402\Pricing\PriceResolver;
use Requestway\X402\Routing\RoutePayment;

final class FlatRatePriceResolver implements PriceResolver
{
    public function resolve(Request $request, RoutePayment $route): string|int|float|null
    {
        return $request->query('bulk') === 'yes' ? '1.00' : null;
    }
}
