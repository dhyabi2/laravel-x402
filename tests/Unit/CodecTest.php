<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;
use HydraLabs\X402\Core\Messages\PaymentPayload;
use HydraLabs\X402\Core\Messages\PaymentRequired;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\ResourceInfo;
use HydraLabs\X402\Core\Messages\SettleResponse;
use HydraLabs\X402\Core\Protocol\Codec;
use HydraLabs\X402\Core\Protocol\CodecRegistry;
use HydraLabs\X402\Core\Protocol\V1Codec;
use HydraLabs\X402\Core\Protocol\V2Codec;
use HydraLabs\X402\Core\Protocol\Version;
use HydraLabs\X402\Core\Support\Base64;
use HydraLabs\X402\Core\Support\Json;

it('uses the header names the v2 HTTP transport defines', function (): void {
    $codec = new V2Codec;

    expect($codec->version())->toBe(2)
        ->and($codec->paymentRequiredHeader())->toBe('PAYMENT-REQUIRED')
        ->and($codec->paymentSignatureHeader())->toBe('PAYMENT-SIGNATURE')
        ->and($codec->paymentResponseHeader())->toBe('PAYMENT-RESPONSE');
});

it('uses the header names the v1 HTTP transport defined', function (): void {
    $codec = new V1Codec;

    expect($codec->version())->toBe(1)
        ->and($codec->paymentRequiredHeader())->toBeNull()
        ->and($codec->paymentSignatureHeader())->toBe('X-PAYMENT')
        ->and($codec->paymentResponseHeader())->toBe('X-PAYMENT-RESPONSE');
});

it('encodes the specification PaymentRequired example byte for byte', function (): void {
    $golden = fixture('payment_required');

    $encoded = (new V2Codec)->encodePaymentRequired(PaymentRequired::fromArray($golden));

    expect(Json::decodeObject(Base64::decode($encoded)))->toBe($golden);
});

it('decodes the specification PaymentPayload example', function (): void {
    $golden = fixture('payment_payload');

    $payload = (new V2Codec)->decodePaymentPayload(Base64::encode(Json::encode($golden)));

    expect($payload->x402Version)->toBe(2)
        ->and($payload->scheme())->toBe('exact')
        ->and($payload->network())->toBe('eip155:84532')
        ->and($payload->resourceUrl())->toBe('https://api.example.com/premium-data')
        ->and($payload->payerAddress())->toBe('0x857b06519E91e3A54538791bDbb0E22373e36b66')
        ->and($payload->recipientAddress())->toBe('0x209693Bc6afc0C5328bA36FaF03C514EF312287C')
        ->and($payload->authorizedValue())->toBe('10000')
        ->and($payload->validAfter())->toBe(1740672089)
        ->and($payload->validBefore())->toBe(1740672154)
        ->and($payload->nonce())->toBe('0xf3746613c2d920b5fdabc0856f2aeb2d4f88ee6037b8cc5d04a71a4462f13480')
        ->and($payload->toArray())->toBe($golden);
});

it('round-trips a payment payload through the wire format', function (): void {
    $codec = new V2Codec;
    $golden = fixture('payment_payload');
    $encoded = Base64::encode(Json::encode($golden));

    $decoded = $codec->decodePaymentPayload($encoded);

    expect(Base64::encode(Json::encode($decoded->toArray())))->toBe($encoded);
});

it('round-trips a settlement response', function (): void {
    $codec = new V2Codec;
    $golden = fixture('settlement');

    $settlement = SettleResponse::fromArray($golden);
    $encoded = $codec->encodeSettlement($settlement);

    expect(Json::decodeObject(Base64::decode($encoded)))->toBe($golden);
});

it('decodes a flat v1 payload with no accepted echo', function (): void {
    $payload = (new V1Codec)->decodePaymentPayload(Base64::encode(Json::encode(fixture('payment_payload_v1'))));

    expect($payload->x402Version)->toBe(1)
        ->and($payload->accepted)->toBeNull()
        ->and($payload->scheme())->toBe('exact')
        ->and($payload->network())->toBe('base-sepolia')
        ->and($payload->authorizedValue())->toBe('10000')
        ->and($payload->toArray())->toBe(fixture('payment_payload_v1'));
});

it('builds the facilitator request body the spec defines', function (): void {
    $codec = new V2Codec;
    $payload = $codec->decodePaymentPayload(Base64::encode(Json::encode(fixture('payment_payload'))));
    $required = PaymentRequired::fromArray(fixture('payment_required'));
    $requirements = $required->first();

    $body = $codec->facilitatorRequest($payload, $requirements, $required->resource);

    expect($body)->toHaveKeys(['x402Version', 'paymentPayload', 'paymentRequirements'])
        ->and($body['x402Version'])->toBe(2)
        ->and($body['paymentRequirements'])->toBe($requirements->toArray());
});

it('builds the legacy facilitator request body with v1 field names', function (): void {
    $codec = new V1Codec;
    $payload = $codec->decodePaymentPayload(Base64::encode(Json::encode(fixture('payment_payload_v1'))));
    $required = PaymentRequired::fromArray(fixture('payment_required'));

    $body = $codec->facilitatorRequest($payload, $required->first(), $required->resource);

    expect($body['x402Version'])->toBe(1)
        ->and($body['paymentPayload'])->toBe(fixture('payment_payload_v1'))
        ->and($body['paymentRequirements'])->toMatchArray([
            'maxAmountRequired' => '10000',
            'resource' => 'https://api.example.com/premium-data',
            'description' => 'Access to premium market data',
            'mimeType' => 'application/json',
            'outputSchema' => null,
        ])
        ->and($body['paymentRequirements'])->not->toHaveKey('amount');
});

it('emits the v1 body shape for a legacy 402', function (): void {
    $body = (new V1Codec)->paymentRequiredBody(PaymentRequired::fromArray(fixture('payment_required')));

    expect($body['x402Version'])->toBe(1)
        ->and($body['error'])->toBe('PAYMENT-SIGNATURE header is required')
        ->and($body['accepts'][0])->toHaveKey('maxAmountRequired');
});

it('rejects malformed wire values with a clean exception', function (string $header): void {
    expect(fn () => (new V2Codec)->decodePaymentPayload($header))->toThrow(MalformedPaymentException::class);
})->with([
    'not base64 at all!',
    '',
    '   ',
    'YQ==',
    'eyJmb28iOiJiYXIi',
]);

it('rejects a payload missing required fields', function (array $payload): void {
    expect(fn () => (new V2Codec)->decodePaymentPayload(Base64::encode(Json::encode($payload))))
        ->toThrow(MalformedPaymentException::class);
})->with([
    'no version' => [['accepted' => [], 'payload' => ['a' => 'b']]],
    'unsupported version' => [['x402Version' => 99, 'accepted' => [], 'payload' => ['a' => 'b']]],
    'no accepted' => [['x402Version' => 2, 'payload' => ['a' => 'b']]],
    'no payload' => [['x402Version' => 2, 'accepted' => ['scheme' => 'exact']]],
    'empty payload' => [['x402Version' => 2, 'accepted' => ['scheme' => 'exact'], 'payload' => []]],
]);

it('rejects a v1 payload missing scheme, network or payload', function (array $payload): void {
    expect(fn () => (new V1Codec)->decodePaymentPayload(Base64::encode(Json::encode($payload))))
        ->toThrow(MalformedPaymentException::class);
})->with([
    [['x402Version' => 1, 'payload' => ['a' => 'b']]],
    [['x402Version' => 1, 'scheme' => 'exact', 'payload' => ['a' => 'b']]],
    [['x402Version' => 1, 'scheme' => 'exact', 'network' => 'base', 'payload' => []]],
]);

it('accepts unpadded and URL-safe base64 from lenient clients', function (): void {
    $json = Json::encode(fixture('payment_payload'));
    $standard = Base64::encode($json);

    expect(Base64::decode(rtrim($standard, '=')))->toBe($json)
        ->and(Base64::decode(strtr($standard, '+/', '-_')))->toBe($json);
});

it('prefers the newest protocol version a client offers', function (): void {
    $registry = new CodecRegistry;
    $headers = [
        'PAYMENT-SIGNATURE' => 'v2-header',
        'X-PAYMENT' => 'v1-header',
    ];

    [$codec, $value] = $registry->detect(fn (string $name): ?string => $headers[$name] ?? null);

    expect($codec->version())->toBe(2)->and($value)->toBe('v2-header');
});

it('falls back to the v1 header when that is all the client sent', function (): void {
    [$codec, $value] = (new CodecRegistry)->detect(
        fn (string $name): ?string => $name === 'X-PAYMENT' ? 'v1-header' : null
    );

    expect($codec->version())->toBe(1)->and($value)->toBe('v1-header');
});

it('detects nothing when no payment header is present', function (): void {
    expect((new CodecRegistry)->detect(fn (): ?string => null))->toBeNull()
        ->and((new CodecRegistry)->detect(fn (): ?string => '   '))->toBeNull();
});

it('exposes the registered codecs and payment headers', function (): void {
    $registry = new CodecRegistry;

    expect($registry->current()->version())->toBe(Version::CURRENT)
        ->and($registry->for(1))->toBeInstanceOf(V1Codec::class)
        ->and(array_keys($registry->all()))->toBe([2, 1])
        ->and($registry->paymentHeaders())->toBe(['PAYMENT-SIGNATURE', 'X-PAYMENT']);
});

it('refuses to serve a codec for an unknown version', function (): void {
    expect(fn () => (new CodecRegistry)->for(99))->toThrow(MalformedPaymentException::class);
});

it('accepts a custom codec for a future protocol version', function (): void {
    $registry = new CodecRegistry;
    $future = new class(new V2Codec) implements Codec
    {
        public function __construct(private readonly V2Codec $inner) {}

        public function version(): int
        {
            return 3;
        }

        public function paymentSignatureHeader(): string
        {
            return $this->inner->paymentSignatureHeader();
        }

        public function paymentRequiredHeader(): ?string
        {
            return $this->inner->paymentRequiredHeader();
        }

        public function paymentResponseHeader(): string
        {
            return $this->inner->paymentResponseHeader();
        }

        public function encodePaymentRequired(PaymentRequired $paymentRequired): string
        {
            return $this->inner->encodePaymentRequired($paymentRequired);
        }

        public function decodePaymentPayload(string $header): PaymentPayload
        {
            return $this->inner->decodePaymentPayload($header);
        }

        public function encodeSettlement(SettleResponse $settlement): string
        {
            return $this->inner->encodeSettlement($settlement);
        }

        public function facilitatorRequest(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): array
        {
            return $this->inner->facilitatorRequest($payload, $requirements, $resource);
        }

        public function paymentRequiredBody(PaymentRequired $paymentRequired): array
        {
            return $this->inner->paymentRequiredBody($paymentRequired);
        }
    };

    $registry->register($future);

    expect($registry->for(3)->version())->toBe(3)
        ->and(array_keys($registry->all()))->toBe([3, 2, 1]);
});
