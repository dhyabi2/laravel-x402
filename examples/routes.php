<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Example paid routes
|--------------------------------------------------------------------------
|
| Copy the parts you need into routes/api.php. Each block is independent.
|
| Try any of them without a wallet or a chain:
|
|     php artisan x402:test /market-data
|
*/

use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Requestway\X402\Facades\X402;
use Requestway\X402\Routing\RoutePayment;

/*
| 1. The whole integration: a price, in USD.
*/

Route::get('/market-data', fn (): array => [
    'btc' => 67_000,
    'eth' => 3_400,
    'as_of' => now()->toIso8601String(),
])->middleware('x402:0.01');

/*
| 2. Free for your signed-in customers, paid for everyone else. Agents can pay
|    their way in without an account; humans keep using their subscription.
*/

Route::get('/reports/{report}', [ReportController::class, 'show'])
    ->middleware(['x402:0.25,free-for-auth']);

/*
| 3. A named tier from config/x402.php, so prices live in one place.
*/

Route::get('/forecast', fn (): array => ['outlook' => 'bullish'])
    ->middleware('x402:premium');

/*
| 4. Description and mime type, so agents and directories know what they are
|    buying before they buy it. These appear in the 402 and in /.well-known/x402.
*/

Route::get('/filings.csv', fn () => response()->streamDownload(
    fn () => print 'cik,name,date'.PHP_EOL,
    'filings.csv',
))->middleware('x402:0.05,description=SEC filings for the last 24 hours,mime=text/csv');

/*
| 5. The fluent macro, when the middleware string gets long.
*/

Route::post('/summarise', fn (Request $request): array => [
    'summary' => str($request->string('text'))->limit(280)->toString(),
])->paid('0.02', [
    'description' => 'Summarise a document',
    'mime_type' => 'application/json',
    'tags' => ['nlp', 'summarisation'],
]);

/*
| 6. Attributes, for teams that keep route metadata on the controller.
|
|     #[RequiresPayment(price: '0.10', description: 'Full company profile')]
|     public function show(Company $company): CompanyResource
|
|    With the bare `x402` middleware, the attribute supplies the price. Push
|    HonorPaymentAttributes into a middleware group to honour attributes without
|    touching each route.
*/

Route::get('/companies/{company}', [ReportController::class, 'company'])
    ->middleware('x402');

/*
| 7. Accept several chains, so a client pays on whichever one it holds funds on.
*/

Route::get('/multi-chain', fn (): array => ['ok' => true])
    ->middleware('x402:0.01,networks=eip155:8453|eip155:42161|eip155:84532');

/*
| 8. Per-route settlement mode. after_response answers sooner and settles in
|    terminate(); a failure there fires PaymentFailed instead of withholding the
|    response. Use it for cheap, latency-sensitive endpoints.
*/

Route::get('/quote', fn (): array => ['quote' => 42])
    ->middleware('x402:0.001,settle=after_response');

/*
| 9. A second wallet for one route, for revenue share or a white-labelled
|    endpoint. Funds still go straight to the address, never through this app.
*/

Route::get('/partner-feed', fn (): array => ['items' => []])
    ->middleware('x402:0.02,pay_to=0x0000000000000000000000000000000000000abc');

/*
| 10. Dynamic pricing. Register this in a service provider's boot(), not here.
|     Returning null falls back to the route's own price.
*/

X402::priceUsing(function (Request $request, RoutePayment $route): ?string {
    // Charge by result size.
    if ($route->routeName === 'search') {
        return bcmul('0.0005', (string) max(1, $request->integer('limit', 10)), 6);
    }

    // Repeat payers get a discount.
    if ($request->hasHeader('PAYMENT-SIGNATURE') && $request->boolean('bulk')) {
        return '0.005';
    }

    return null;
});

Route::get('/search', fn (Request $request): array => ['hits' => []])
    ->middleware('x402:0.01')
    ->name('search');

/*
| 11. Your own bypass rule, beyond the configured ones.
*/

X402::bypassUsing(fn (Request $request, RoutePayment $route): bool => $request->user()?->credits_remaining > 0);
