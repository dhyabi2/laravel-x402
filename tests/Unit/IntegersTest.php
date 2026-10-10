<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Exceptions\InvalidAmountException;
use HydraLabs\X402\Core\Money\Integers;

it('normalizes leading zeros', function (): void {
    expect(Integers::normalize('000'))->toBe('0')
        ->and(Integers::normalize('0010000'))->toBe('10000')
        ->and(Integers::normalize('10000'))->toBe('10000')
        ->and(Integers::normalize(' 42 '))->toBe('42');
});

it('rejects anything that is not an unsigned integer', function (string $value): void {
    expect(fn (): string => Integers::normalize($value))->toThrow(InvalidAmountException::class);
})->with(['-1', '1.5', '1e6', '0x10', '', 'abc', '1 000']);

it('compares amounts beyond PHP integer range', function (): void {
    $max = (string) PHP_INT_MAX;

    expect(Integers::compare('1000000000000000000000000', '999999999999999999999999'))->toBe(1)
        ->and(Integers::compare('999999999999999999999999', '1000000000000000000000000'))->toBe(-1)
        ->and(Integers::compare($max, $max))->toBe(0)
        ->and(Integers::compare('0010', '10'))->toBe(0)
        ->and(Integers::compare('19', '9'))->toBe(1);
});

it('answers greater-than-or-equal without floats', function (): void {
    expect(Integers::greaterThanOrEqual('10000', '10000'))->toBeTrue()
        ->and(Integers::greaterThanOrEqual('10001', '10000'))->toBeTrue()
        ->and(Integers::greaterThanOrEqual('9999', '10000'))->toBeFalse();
});

it('increments across carries', function (string $value, string $expected): void {
    expect(Integers::increment($value))->toBe($expected);
})->with([
    ['0', '1'],
    ['9', '10'],
    ['99', '100'],
    ['10999', '11000'],
    ['999999999999999999999999999', '1000000000000000000000000000'],
]);

it('multiplies by a small factor', function (): void {
    expect(Integers::multiplyBySmall('0', 10))->toBe('0')
        ->and(Integers::multiplyBySmall('123', 0))->toBe('0')
        ->and(Integers::multiplyBySmall('99', 11))->toBe('1089')
        ->and(Integers::multiplyBySmall('123456789012345678901234567890', 7))->toBe('864197523086419752308641975230');
});

it('refuses a negative multiplier', function (): void {
    expect(fn (): string => Integers::multiplyBySmall('1', -1))->toThrow(InvalidAmountException::class);
});

it('detects zero', function (): void {
    expect(Integers::isZero('0'))->toBeTrue()
        ->and(Integers::isZero('0000'))->toBeTrue()
        ->and(Integers::isZero('1'))->toBeFalse()
        ->and(Integers::isValid('12'))->toBeTrue()
        ->and(Integers::isValid('-12'))->toBeFalse();
});
