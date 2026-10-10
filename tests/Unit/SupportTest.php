<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;
use HydraLabs\X402\Core\Support\EvmAddress;
use HydraLabs\X402\Core\Support\FrozenClock;
use HydraLabs\X402\Core\Support\Json;
use HydraLabs\X402\Core\Support\SystemClock;

it('validates EVM addresses', function (string $address, bool $valid): void {
    expect(EvmAddress::isValid($address))->toBe($valid);
})->with([
    ['0x209693Bc6afc0C5328bA36FaF03C514EF312287C', true],
    ['0x209693bc6afc0c5328ba36faf03c514ef312287c', true],
    ['209693Bc6afc0C5328bA36FaF03C514EF312287C', false],
    ['0x209693Bc6afc0C5328bA36FaF03C514EF31228', false],
    ['0x209693Bc6afc0C5328bA36FaF03C514EF312287CC', false],
    ['0xZZ9693Bc6afc0C5328bA36FaF03C514EF312287C', false],
    ['', false],
]);

it('compares addresses case-insensitively and safely', function (): void {
    expect(EvmAddress::matches('0xABC', '0xabc'))->toBeTrue()
        ->and(EvmAddress::matches(' 0xABC ', '0xabc'))->toBeTrue()
        ->and(EvmAddress::matches('0xABC', '0xdef'))->toBeFalse()
        ->and(EvmAddress::matches(null, '0xabc'))->toBeFalse()
        ->and(EvmAddress::matches('0xabc', null))->toBeFalse();
});

it('abbreviates addresses for logs', function (): void {
    expect(EvmAddress::abbreviate('0x209693Bc6afc0C5328bA36FaF03C514EF312287C'))->toBe('0x2096…287C')
        ->and(EvmAddress::abbreviate(null))->toBe('-')
        ->and(EvmAddress::abbreviate(''))->toBe('-')
        ->and(EvmAddress::abbreviate('0xshort'))->toBe('0xshort')
        ->and(EvmAddress::normalize(' 0xABC '))->toBe('0xabc');
});

it('encodes JSON without escaping slashes, so resource URLs stay readable', function (): void {
    expect(Json::encode(['url' => 'https://api.test/a/b']))->toBe('{"url":"https://api.test/a/b"}');
});

it('canonicalizes objects for stable hashing but keeps list order', function (): void {
    expect(Json::canonical(['b' => 1, 'a' => ['d' => 2, 'c' => 3]]))
        ->toBe('{"a":{"c":3,"d":2},"b":1}')
        ->and(Json::canonical(['items' => [3, 1, 2]]))->toBe('{"items":[3,1,2]}');
});

it('rejects JSON that is not an object', function (string $json): void {
    expect(fn (): array => Json::decodeObject($json))->toThrow(MalformedPaymentException::class);
})->with(['not json', '"a string"', '42', 'null', '']);

it('decodes nested JSON objects', function (): void {
    expect(Json::decodeObject('{"a":{"b":[1,2]}}'))->toBe(['a' => ['b' => [1, 2]]]);
});

it('reads the system clock and a frozen one', function (): void {
    $frozen = new FrozenClock(1000);

    expect($frozen->now())->toBe(1000)
        ->and((new SystemClock)->now())->toBeGreaterThan(1700000000);

    $frozen->set(2000);

    expect($frozen->now())->toBe(2000);
});

it('rejects base64 whose length cannot be valid', function (): void {
    expect(fn (): string => HydraLabs\X402\Core\Support\Base64::decode('YWJjZGU'.'A'.'====='))
        ->toThrow(MalformedPaymentException::class)
        ->and(fn (): string => HydraLabs\X402\Core\Support\Base64::decode('A'))
        ->toThrow(MalformedPaymentException::class);
});

it('rejects base64 that decodes to nothing', function (): void {
    expect(fn (): string => HydraLabs\X402\Core\Support\Base64::decode('===='))
        ->toThrow(MalformedPaymentException::class);
});

it('encodes to standard padded base64', function (): void {
    expect(HydraLabs\X402\Core\Support\Base64::encode('{"a":1}'))->toBe('eyJhIjoxfQ==');
});
