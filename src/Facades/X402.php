<?php

declare(strict_types=1);

namespace Requestway\X402\Facades;

use Closure;
use Illuminate\Support\Facades\Facade;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Facilitators\FakeFacilitator;
use Requestway\X402\Routing\RoutePayment;
use Requestway\X402\X402Manager;

/**
 * @method static X402Config config()
 * @method static Facilitator facilitator()
 * @method static FakeFacilitator fake()
 * @method static bool isFaked()
 * @method static X402Manager priceUsing(Closure|string|\Requestway\X402\Pricing\PriceResolver $resolver)
 * @method static X402Manager bypassUsing(Closure $callback)
 * @method static Facilitator useFacilitator(Facilitator $facilitator)
 * @method static RoutePayment resolveRoute(\Illuminate\Http\Request $request, array<string, mixed> $options = [])
 * @method static X402Manager flush()
 * @method static FakeFacilitator assertSettled(?int $times = null)
 * @method static FakeFacilitator assertVerified(?int $times = null)
 * @method static FakeFacilitator assertSettledFor(string $resource, string|int|float|null $usd = null)
 * @method static FakeFacilitator assertNotCharged()
 * @method static FakeFacilitator assertNothingVerified()
 *
 * @see X402Manager
 */
final class X402 extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'x402';
    }
}
