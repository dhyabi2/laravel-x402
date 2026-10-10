<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Errors\ErrorCode;
use HydraLabs\X402\Core\Facilitator\Facilitator;
use HydraLabs\X402\Core\Messages\SettleResponse;
use HydraLabs\X402\Core\Messages\SupportedKind;
use HydraLabs\X402\Core\Messages\VerifyResponse;
use HydraLabs\X402\Core\Protocol\Version;
use HydraLabs\X402\Enums\Mode;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Facilitators\FakeFacilitator;
use HydraLabs\X402\Facilitators\HttpFacilitator;
use HydraLabs\X402\Pricing\CallbackPriceResolver;
use HydraLabs\X402\Reporting\Reporter;
use HydraLabs\X402\X402Manager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('exposes the package services', function (): void {
    $x402 = app(X402Manager::class);

    expect($x402->config()->mode())->toBe(Mode::Enforce)
        ->and($x402->mode())->toBe(Mode::Enforce)
        ->and($x402->codecs()->current()->version())->toBe(Version::CURRENT)
        ->and($x402->routes())->toBeInstanceOf(HydraLabs\X402\Routing\RouteResolver::class)
        ->and($x402->requirements())->toBeInstanceOf(HydraLabs\X402\Routing\RequirementsFactory::class)
        ->and($x402->validator())->toBeInstanceOf(HydraLabs\X402\Core\Validation\PaymentValidator::class)
        ->and($x402->bypass())->toBeInstanceOf(HydraLabs\X402\Bypass\BypassEvaluator::class)
        ->and($x402->recorder())->toBeInstanceOf(HydraLabs\X402\Recording\PaymentRecorder::class)
        ->and($x402->replayGuard())->toBeInstanceOf(HydraLabs\X402\Core\Replay\ReplayGuard::class)
        ->and($x402->reporter())->toBeInstanceOf(Reporter::class)
        ->and($x402->container())->toBe(app());
});

it('resolves the HTTP facilitator by default', function (): void {
    expect(X402::facilitator())->toBeInstanceOf(HttpFacilitator::class)
        ->and(X402::isFaked())->toBeFalse();
});

it('swaps in a fake and resets it on a second call', function (): void {
    $first = X402::fake();

    expect(X402::isFaked())->toBeTrue()
        ->and(X402::facilitator())->toBe($first);

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');
    $this->getJson('/premium');

    $second = X402::fake();

    expect($second)->toBe($first)->and($second->verifyCount())->toBe(0);
});

it('swaps in any facilitator implementation', function (): void {
    $custom = new class implements Facilitator
    {
        public function verify($payload, $requirements, $resource): VerifyResponse
        {
            return VerifyResponse::invalid(ErrorCode::InvalidNetwork);
        }

        public function settle($payload, $requirements, $resource): SettleResponse
        {
            return SettleResponse::failed('eip155:1', ErrorCode::UnexpectedSettleError);
        }

        public function supported(): HydraLabs\X402\Core\Messages\SupportedResponse
        {
            return new HydraLabs\X402\Core\Messages\SupportedResponse([]);
        }
    };

    X402::useFacilitator($custom);

    expect(X402::facilitator())->toBe($custom)->and(app(Facilitator::class))->toBe($custom);
});

it('refuses fake assertions when no fake is installed', function (): void {
    expect(fn () => X402::assertSettled())
        ->toThrow(BadMethodCallException::class, 'only available after calling X402::fake()');
});

it('keeps and forgets the dynamic price resolver', function (): void {
    expect(app(X402Manager::class)->priceResolver())->toBeNull();

    X402::priceUsing(fn (): string => '0.02');

    expect(app(X402Manager::class)->priceResolver())->toBeInstanceOf(CallbackPriceResolver::class);

    X402::flush();

    expect(app(X402Manager::class)->priceResolver())->toBeNull()
        ->and(X402::isFaked())->toBeFalse();
});

it('resolves a route payment for a request', function (): void {
    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    $payment = X402::resolveRoute(Request::create('http://localhost/premium'), ['price' => '0.33']);

    expect($payment->price)->toBe('0.33')->and($payment->payTo)->toBe(HydraLabs\X402\Tests\TestCase::PAY_TO);
});

it('forgets registered bypass callbacks when flushed', function (): void {
    X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    X402::bypassUsing(fn (): bool => true);

    $this->getJson('/premium')->assertOk();

    X402::flush();
    X402::fake();

    $this->getJson('/premium')->assertStatus(402);
});

it('lets the fake declare what it supports', function (): void {
    $fake = X402::fake();

    expect($fake->supported()->supports('exact', 'eip155:8453'))->toBeTrue();

    $fake->supports([new SupportedKind(Version::V2, 'exact', 'eip155:1')]);

    expect($fake->supported()->supports('exact', 'eip155:1'))->toBeTrue()
        ->and($fake->supported()->supports('exact', 'eip155:8453'))->toBeFalse();
});

it('lets the fake reject with a boolean shorthand', function (): void {
    $fake = X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $fake->rejectVerification(fn (): bool => false);

    $this->getJson('/premium', payment($requirements))->assertStatus(402);

    $fake->reset();
    $fake->rejectSettlement(fn (): bool => false);

    $this->getJson('/premium', payment($requirements))->assertStatus(402);
});

it('lets the fake accept with a closure that returns a response', function (): void {
    $fake = X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    $fake->rejectSettlement(fn ($payload, $requirements): SettleResponse => SettleResponse::settled(
        $requirements->network,
        '0xcustom',
    ));

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $response = $this->getJson('/premium', payment($requirements));

    expect(settlement($response)->transaction)->toBe('0xcustom');
});

it('exposes the recorded verifications and settlements', function (): void {
    $fake = X402::fake();

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    expect($fake->verifications())->toHaveCount(1)
        ->and($fake->settlements())->toHaveCount(1)
        ->and($fake->settlements()[0]['requirements']->amount)->toBe('10000');
});

it('fails an assertion when the expected count does not match', function (): void {
    $fake = new FakeFacilitator;

    expect(fn (): FakeFacilitator => $fake->assertVerified())
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected a payment to be verified')
        ->and(fn (): FakeFacilitator => $fake->assertVerified(2))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected 2 payment verification(s), got 0')
        ->and(fn (): FakeFacilitator => $fake->assertSettled())
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected a payment to be settled')
        ->and(fn (): FakeFacilitator => $fake->assertSettled(1))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected 1 settled payment(s), got 0')
        ->and(fn (): FakeFacilitator => $fake->assertSettledFor('/premium'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Settled: none');
});

it('fails assertSettledFor when the amount is wrong', function (): void {
    $fake = X402::fake();

    Route::get('/premium', fn (): array => [])->middleware('x402:0.01');

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    $fake->assertSettledFor('/premium', '0.01');

    expect(fn (): FakeFacilitator => $fake->assertSettledFor('/premium', '0.99'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected a settled payment of $0.99')
        ->and(fn (): FakeFacilitator => $fake->assertSettledFor('/other'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected a settled payment for "/other"')
        ->and(fn (): FakeFacilitator => $fake->assertNotCharged())
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected no payment to be charged')
        ->and(fn (): FakeFacilitator => $fake->assertNothingVerified())
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, 'Expected no payment to be verified');
});

it('is reachable through both the facade and the container alias', function (): void {
    expect(app('x402'))->toBe(app(X402Manager::class))
        ->and(X402::getFacadeRoot())->toBe(app(X402Manager::class));
});
