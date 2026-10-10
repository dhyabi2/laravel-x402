<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorConnectionException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorResponseException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorTimeoutException;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Messages\PaymentRequired;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Protocol\Version;
use Requestway\X402\Core\Testing\PaymentPayloadFactory;
use Requestway\X402\Tests\TestCase;

function facilitatorRequirements(): PaymentRequirements
{
    return PaymentRequired::fromArray(fixture('payment_required'))->first();
}

function callFacilitator(): Facilitator
{
    return app(Facilitator::class);
}

function samplePayload(): Requestway\X402\Core\Messages\PaymentPayload
{
    return (new PaymentPayloadFactory)->exact(facilitatorRequirements());
}

it('posts the verify body the specification defines', function (): void {
    Http::fake([
        TestCase::FACILITATOR.'/verify' => Http::response(['isValid' => true, 'payer' => TestCase::PAYER]),
    ]);

    $response = callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x'));

    expect($response->isValid)->toBeTrue()->and($response->payer)->toBe(TestCase::PAYER);

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === TestCase::FACILITATOR.'/verify'
            && $request->method() === 'POST'
            && $body['x402Version'] === Version::V2
            && isset($body['paymentPayload'], $body['paymentRequirements'])
            && $body['paymentRequirements']['amount'] === '10000';
    });
});

it('posts the settle body and reads the settlement response', function (): void {
    Http::fake([
        TestCase::FACILITATOR.'/settle' => Http::response(fixture('settlement')),
    ]);

    $settlement = callFacilitator()->settle(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x'));

    expect($settlement->success)->toBeTrue()
        ->and($settlement->transaction)->toBe(fixture('settlement')['transaction'])
        ->and($settlement->network)->toBe('eip155:84532');

    Http::assertSent(fn (Request $request): bool => $request->url() === TestCase::FACILITATOR.'/settle');
});

it('reads the supported kinds, extensions and signers', function (): void {
    Http::fake([
        TestCase::FACILITATOR.'/supported' => Http::response([
            'kinds' => [
                ['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:84532'],
                ['x402Version' => 2, 'scheme' => 'exact', 'network' => 'eip155:8453'],
            ],
            'extensions' => ['bazaar'],
            'signers' => ['eip155:*' => ['0xsigner']],
        ]),
    ]);

    $supported = callFacilitator()->supported();

    expect($supported->kinds)->toHaveCount(2)
        ->and($supported->supports('exact', 'eip155:8453'))->toBeTrue()
        ->and($supported->extensions)->toBe(['bazaar']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET');
});

it('sends the configured auth headers', function (): void {
    config()->set('x402.facilitator.headers', ['Authorization' => 'Bearer cdp-token', 'X-Key' => 'abc']);

    Http::fake([TestCase::FACILITATOR.'/*' => Http::response(['isValid' => true])]);

    callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x'));

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer cdp-token')
        && $request->hasHeader('X-Key', 'abc'));
});

it('tolerates a trailing slash on the configured URL', function (): void {
    config()->set('x402.facilitator.url', TestCase::FACILITATOR.'/');

    Http::fake([TestCase::FACILITATOR.'/verify' => Http::response(['isValid' => true])]);

    callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x'));

    Http::assertSent(fn (Request $request): bool => $request->url() === TestCase::FACILITATOR.'/verify');
});

it('raises a typed exception on an HTTP error, with the body excerpted', function (): void {
    Http::fake([TestCase::FACILITATOR.'/verify' => Http::response(['error' => 'bad request'], 422)]);

    expect(fn () => callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorResponseException::class, 'returned HTTP 422');
});

it('raises a typed exception on a non-JSON body', function (): void {
    Http::fake([TestCase::FACILITATOR.'/verify' => Http::response('<html>502 Bad Gateway</html>', 200)]);

    expect(fn () => callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorResponseException::class, 'non-JSON body');
});

it('describes an empty error body rather than printing nothing', function (): void {
    Http::fake([TestCase::FACILITATOR.'/settle' => Http::response('', 500)]);

    expect(fn () => callFacilitator()->settle(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorResponseException::class, '<empty response>');
});

it('truncates a very long error body', function (): void {
    Http::fake([TestCase::FACILITATOR.'/verify' => Http::response(str_repeat('x', 5000), 500)]);

    try {
        callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x'));
    } catch (FacilitatorResponseException $exception) {
        expect($exception->getMessage())->toEndWith('...')->and(strlen($exception->getMessage()))->toBeLessThan(300);
    }
});

it('distinguishes a timeout from a refused connection', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorTimeoutException::class, 'timed out');
});

it('raises a connection exception when the facilitator is unreachable', function (): void {
    Http::fake(fn (): never => throw new ConnectionException('cURL error 7: Failed to connect'));

    expect(fn () => callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorConnectionException::class, 'Could not reach the facilitator');
});

it('retries a verify that could not connect', function (): void {
    config()->set('x402.facilitator.retries', 2);
    config()->set('x402.facilitator.retry_delay', 0);

    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts < 3) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return Http::response(['isValid' => true]);
    });

    expect(callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x'))->isValid)
        ->toBeTrue()
        ->and($attempts)->toBe(3);
});

it('does not retry an HTTP error, because the facilitator answered', function (): void {
    config()->set('x402.facilitator.retries', 3);
    config()->set('x402.facilitator.retry_delay', 0);

    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['error' => 'nope'], 500);
    });

    expect(fn () => callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorResponseException::class);

    expect($attempts)->toBe(1);
});

it('never retries a settle by default, because a repeat could charge twice', function (): void {
    config()->set('x402.facilitator.retries', 3);

    $attempts = 0;

    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('cURL error 7: Failed to connect');
    });

    expect(fn () => callFacilitator()->settle(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorConnectionException::class);

    expect($attempts)->toBe(1);
});

it('retries a settle when an operator explicitly opts in', function (): void {
    config()->set('x402.facilitator.settle_retries', 1);
    config()->set('x402.facilitator.retry_delay', 0);

    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }

        return Http::response(fixture('settlement'));
    });

    expect(callFacilitator()->settle(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x'))->success)
        ->toBeTrue()
        ->and($attempts)->toBe(2);
});

it('returns 502 and serves nothing when the facilitator cannot be reached', function (): void {
    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    Http::fake([
        TestCase::FACILITATOR.'/verify' => Http::response(['error' => 'down'], 503),
    ]);

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $response = $this->getJson('/premium', payment($requirements));

    $response->assertStatus(502)->assertJsonMissingPath('data');

    expect($response->json('error'))->toContain('facilitator is unavailable');
});

it('returns 502 when settlement cannot reach the facilitator', function (): void {
    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    Http::fake([
        TestCase::FACILITATOR.'/verify' => Http::response(['isValid' => true, 'payer' => TestCase::PAYER]),
        TestCase::FACILITATOR.'/settle' => fn (): never => throw new ConnectionException('cURL error 7: Failed to connect'),
    ]);

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $this->getJson('/premium', payment($requirements))->assertStatus(502);
});

it('can be configured to serve the route for free when the facilitator is down', function (): void {
    config()->set('x402.settlement.on_facilitator_error', 'fail_open');

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    Http::fake([TestCase::FACILITATOR.'/verify' => Http::response(['error' => 'down'], 503)]);

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $this->getJson('/premium', payment($requirements))
        ->assertOk()
        ->assertExactJson(['data' => 'secret'])
        ->assertHeader(Requestway\X402\Http\Middleware\RequirePayment::BYPASS_HEADER, 'facilitator-unavailable');
});

it('records a facilitator failure as a failed payment', function (): void {
    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    Http::fake([TestCase::FACILITATOR.'/verify' => Http::response(['error' => 'down'], 503)]);

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    $payment = Requestway\X402\Models\X402Payment::query()->failed()->sole();

    expect($payment->failure_reason)->toBe('facilitator_unavailable')
        ->and($payment->facilitator)->toBe('facilitator.test');
});

it('reports a facilitator URL that is not a URL as a typed failure, naming it', function (): void {
    config()->set('x402.facilitator.url', 'not-a-url');

    expect(fn () => callFacilitator()->verify(samplePayload(), facilitatorRequirements(), new ResourceInfo('https://api.test/x')))
        ->toThrow(FacilitatorConnectionException::class, 'not-a-url');
});

it('returns nothing and records the failure when a deferred settlement cannot reach the facilitator', function (): void {
    config()->set('x402.settlement.mode', 'after_response');

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');

    Http::fake([
        TestCase::FACILITATOR.'/verify' => Http::response(['isValid' => true, 'payer' => TestCase::PAYER]),
        TestCase::FACILITATOR.'/settle' => fn (): never => throw new ConnectionException('cURL error 7: Failed to connect'),
    ]);

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    // The client is served, because the route already ran: the failure is the
    // operator's problem to reconcile, and it is recorded as such.
    $this->getJson('/premium', payment($requirements))->assertOk();

    $payment = Requestway\X402\Models\X402Payment::query()->sole();

    expect($payment->status)->toBe(Requestway\X402\Enums\PaymentStatus::Failed)
        ->and($payment->failure_reason)->toBe('facilitator_unavailable')
        ->and($payment->meta['stage'])->toBe('settle_after_response');
});

it('builds auth headers per call for a facilitator that signs each request', function (): void {
    config()->set('x402.facilitator.auth', new class implements Requestway\X402\Core\Facilitator\FacilitatorAuth
    {
        public function headers(string $operation): array
        {
            return ['X-Signed-For' => $operation];
        }
    });

    Http::fake([TestCase::FACILITATOR.'/*' => Http::response(['isValid' => true, 'success' => true, 'transaction' => '0x1', 'network' => 'eip155:84532'])]);

    $requirements = facilitatorRequirements();
    $resource = new ResourceInfo('https://api.test/x');

    callFacilitator()->verify(samplePayload(), $requirements, $resource);
    callFacilitator()->settle(samplePayload(), $requirements, $resource);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Signed-For', 'verify'));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Signed-For', 'settle'));
});

it('resolves a facilitator auth class from the container', function (): void {
    config()->set('x402.facilitator.auth', StaticFacilitatorAuth::class);

    Http::fake([TestCase::FACILITATOR.'/supported' => Http::response(['kinds' => []])]);

    callFacilitator()->supported();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-From-Container', 'supported'));
});

it('sends static headers and signed headers together', function (): void {
    config()->set('x402.facilitator.headers', ['X-Static' => 'yes']);
    config()->set('x402.facilitator.auth', StaticFacilitatorAuth::class);

    Http::fake([TestCase::FACILITATOR.'/supported' => Http::response(['kinds' => []])]);

    callFacilitator()->supported();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Static', 'yes')
        && $request->hasHeader('X-From-Container', 'supported'));
});

final class StaticFacilitatorAuth implements Requestway\X402\Core\Facilitator\FacilitatorAuth
{
    public function headers(string $operation): array
    {
        return ['X-From-Container' => $operation];
    }
}
