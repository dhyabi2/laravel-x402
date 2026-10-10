<?php

declare(strict_types=1);

use Requestway\X402\Core\Exceptions\InvalidAmountException;
use Requestway\X402\Core\Money\UsdConverter;

it('parses prices written the ways people write them', function (string|int|float $input, string $expected): void {
    expect(UsdConverter::parsePrice($input))->toBe($expected);
})->with([
    ['0.01', '0.01'],
    ['$0.01', '0.01'],
    ['  $1.50  ', '1.5'],
    ['1', '1'],
    ['0', '0'],
    ['000.0100', '0.01'],
    ['10.000', '10'],
    [1, '1'],
    [0, '0'],
    [0.25, '0.25'],
]);

it('rejects prices that are not money', function (string|int|float $input): void {
    expect(fn (): string => UsdConverter::parsePrice($input))->toThrow(InvalidAmountException::class);
})->with(['-1', '1e-7', 'free', '', '1.2.3', '0,01', '$', -1, -0.5, NAN, INF]);

it('converts USD to atomic units for a 6-decimal asset', function (string $usd, string $atomic): void {
    expect(UsdConverter::toAtomic($usd, 6))->toBe($atomic);
})->with([
    ['0', '0'],
    ['0.01', '10000'],
    ['0.000001', '1'],
    ['1', '1000000'],
    ['1.5', '1500000'],
    ['0.001', '1000'],
    ['1234.567891', '1234567891'],
    ['$0.10', '100000'],
]);

it('rounds sub-atomic precision up so a route is never underpriced', function (): void {
    expect(UsdConverter::toAtomic('0.0000001', 6))->toBe('1')
        ->and(UsdConverter::toAtomic('0.0000019', 6))->toBe('2')
        ->and(UsdConverter::toAtomic('0.00000100', 6))->toBe('1')
        ->and(UsdConverter::toAtomic('1.0000005', 6))->toBe('1000001')
        ->and(UsdConverter::toAtomic('0.5', 0))->toBe('1');
});

it('handles 18-decimal assets without losing precision', function (): void {
    expect(UsdConverter::toAtomic('1', 18))->toBe('1000000000000000000')
        ->and(UsdConverter::toAtomic('0.000000000000000001', 18))->toBe('1')
        ->and(UsdConverter::toAtomic('1234.5', 18))->toBe('1234500000000000000000');
});

it('rejects impossible decimal counts', function (int $decimals): void {
    expect(fn (): string => UsdConverter::toAtomic('1', $decimals))->toThrow(InvalidAmountException::class);
})->with([-1, 37]);

it('renders atomic units back to decimals', function (): void {
    expect(UsdConverter::toDecimalString('10000', 6))->toBe('0.01')
        ->and(UsdConverter::toDecimalString('1', 6))->toBe('0.000001')
        ->and(UsdConverter::toDecimalString('1000000', 6))->toBe('1')
        ->and(UsdConverter::toDecimalString('0', 6))->toBe('0')
        ->and(UsdConverter::toDecimalString('1234', 0))->toBe('1234');
});

it('formats atomic units as USD', function (): void {
    expect(UsdConverter::format('10000', 6))->toBe('$0.01')
        ->and(UsdConverter::format('1000000', 6))->toBe('$1.00')
        ->and(UsdConverter::format('1500000', 6))->toBe('$1.50')
        ->and(UsdConverter::format('1', 6))->toBe('$0.000001');
});

it('round-trips a price through atomic units', function (string $usd): void {
    expect(UsdConverter::toDecimalString(UsdConverter::toAtomic($usd, 6), 6))
        ->toBe(UsdConverter::parsePrice($usd));
})->with(['0.01', '0.25', '1', '12.345678', '0.000001']);
