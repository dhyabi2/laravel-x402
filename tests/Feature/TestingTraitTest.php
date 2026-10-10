<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Protocol\V1Codec;
use HydraLabs\X402\Core\Protocol\V2Codec;
use HydraLabs\X402\Facades\X402;
use HydraLabs\X402\Testing\MakesX402Payments;
use Illuminate\Support\Facades\Route;

uses(MakesX402Payments::class);

beforeEach(function (): void {
    X402::fake();

    Route::get('/report', fn (): array => ['data' => 'report'])->middleware('x402:0.01');
});

it('pays a route from its 402 response', function (): void {
    $required = $this->get('/report');

    $required->assertStatus(402);

    $this->get('/report', $this->x402Payment($required))
        ->assertOk()
        ->assertExactJson(['data' => 'report']);

    X402::assertSettledFor('/report', '0.01');
});

it('decodes the requirements and the settlement receipt', function (): void {
    $required = $this->x402Requirements($this->get('/report'));

    expect($required->first()->amount)->toBe('10000');

    $settlement = $this->x402Settlement($this->get('/report', $this->x402Payment($required)));

    expect($settlement->success)->toBeTrue()->and($settlement->transaction)->toStartWith('0x');
});

it('pays from a PaymentRequired or PaymentRequirements object', function (): void {
    $required = $this->x402Requirements($this->get('/report'));

    $this->get('/report', $this->x402Payment($required))->assertOk();
    $this->get('/report', $this->x402Payment($required->first()))->assertOk();

    X402::assertSettled(2);
});

it('builds an underpaying payload for testing rejections', function (): void {
    $required = $this->get('/report');

    $this->get('/report', $this->x402Payment($required, amount: '1'))->assertStatus(402);

    X402::assertNotCharged();
});

it('overrides the authorization and the payer', function (): void {
    $required = $this->x402Requirements($this->get('/report'));

    $this->get('/report', $this->x402Payment(
        $required,
        overrides: ['validBefore' => (string) (time() - 60)],
    ))->assertStatus(402);

    $payload = $this->x402Payload($required, payer: '0x1111111111111111111111111111111111111111');

    expect($payload->payerAddress())->toBe('0x1111111111111111111111111111111111111111');
});

it('builds a legacy v1 payment', function (): void {
    $required = $this->x402Requirements($this->get('/report'));

    $headers = $this->x402Payment($required, version: 1);

    expect($headers)->toHaveKey(V1Codec::PAYMENT_SIGNATURE);

    $response = $this->get('/report', $headers);

    $response->assertOk();

    expect($this->x402Settlement($response)->success)->toBeTrue();
});

it('explains when a response carries no payment headers', function (): void {
    Route::get('/open', fn (): array => [])->middleware([]);

    expect(fn () => $this->x402Requirements($this->get('/open')))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, V2Codec::PAYMENT_REQUIRED)
        ->and(fn () => $this->x402Settlement($this->get('/open')))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class, V2Codec::PAYMENT_RESPONSE);
});
