<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Requestway\X402\Core\Messages\PaymentRequired;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Protocol\V2Codec;
use Requestway\X402\Core\Support\Base64;
use Requestway\X402\Core\Support\Json;
use Requestway\X402\Core\Testing\PaymentPayloadFactory;
use Requestway\X402\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Decode the PAYMENT-REQUIRED header from a 402 response.
 */
function paymentRequired(TestResponse $response): PaymentRequired
{
    $header = $response->headers->get(V2Codec::PAYMENT_REQUIRED);

    expect($header)->toBeString()->not->toBeEmpty();

    return PaymentRequired::fromArray(Json::decodeObject(Base64::decode((string) $header)));
}

/**
 * Decode the PAYMENT-RESPONSE header from a settled response.
 */
function settlement(TestResponse $response): SettleResponse
{
    $header = $response->headers->get(V2Codec::PAYMENT_RESPONSE);

    expect($header)->toBeString()->not->toBeEmpty();

    return SettleResponse::fromArray(Json::decodeObject(Base64::decode((string) $header)));
}

/**
 * Build the PAYMENT-SIGNATURE header value for a set of requirements.
 *
 * @param array<string, mixed> $overrides merged into the EIP-3009 authorization
 */
function paymentHeader(
    PaymentRequirements $requirements,
    ?ResourceInfo $resource = null,
    ?string $amount = null,
    array $overrides = [],
    int $version = 2,
): string {
    $payload = (new PaymentPayloadFactory)->exact(
        requirements: $requirements,
        resource: $resource,
        payer: TestCase::PAYER,
        amount: $amount,
        overrides: $overrides,
        version: $version,
    );

    return Base64::encode(Json::encode($payload->toArray()));
}

/**
 * The header array a paying client would send.
 *
 * @return array<string, string>
 */
function payment(PaymentRequirements $requirements, ?ResourceInfo $resource = null, ?string $amount = null, array $overrides = []): array
{
    return [V2Codec::PAYMENT_SIGNATURE => paymentHeader($requirements, $resource, $amount, $overrides)];
}

/**
 * Load a golden fixture captured from the specification.
 *
 * @return array<string, mixed>
 */
function goldenFixture(string $name): array
{
    return Json::decodeObject((string) file_get_contents(__DIR__.'/Fixtures/'.$name.'.json'));
}

/**
 * Run an artisan command for real and return [exit status, output].
 *
 * Commands are asserted against their real rendered output rather than a
 * mocked console, because the table and summary rendering is a large part of
 * what these commands are for.
 *
 * @param array<string, mixed> $parameters
 * @return array{0: int, 1: string}
 */
function runCommand(string $command, array $parameters = []): array
{
    test()->withoutMockingConsoleOutput();

    $status = Artisan::call($command, $parameters);

    return [$status, Artisan::output()];
}
