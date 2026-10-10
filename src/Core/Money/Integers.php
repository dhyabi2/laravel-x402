<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Money;

use HydraLabs\X402\Core\Exceptions\InvalidAmountException;

/**
 * Arbitrary-precision unsigned integer helpers over decimal strings.
 *
 * Atomic token amounts routinely exceed PHP's integer range (1 ETH is 10^18
 * wei), and floats cannot represent them, so every amount in this package is
 * carried as a decimal string and compared with these helpers. Implemented
 * without ext-bcmath or ext-gmp, neither of which can be assumed present.
 */
final class Integers
{
    /**
     * Strip leading zeros, keeping a single "0" for zero.
     *
     * @throws InvalidAmountException when the value is not an unsigned integer
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new InvalidAmountException(sprintf('"%s" is not an unsigned integer amount.', $value));
        }

        $trimmed = ltrim($value, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^\d+$/', trim($value)) === 1;
    }

    /**
     * Compare two unsigned integer strings: -1, 0 or 1.
     */
    public static function compare(string $a, string $b): int
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        $lengthComparison = strlen($a) <=> strlen($b);

        return $lengthComparison !== 0 ? $lengthComparison : strcmp($a, $b) <=> 0;
    }

    public static function greaterThanOrEqual(string $a, string $b): bool
    {
        return self::compare($a, $b) >= 0;
    }

    public static function isZero(string $value): bool
    {
        return self::normalize($value) === '0';
    }

    /**
     * Add one to an unsigned integer string.
     */
    public static function increment(string $value): string
    {
        $digits = self::normalize($value);
        $index = strlen($digits) - 1;

        while ($index >= 0) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return $digits;
            }

            $digits[$index] = '0';
            $index--;
        }

        return '1'.$digits;
    }

    /**
     * Multiply an unsigned integer string by a small non-negative multiplier.
     */
    public static function multiplyBySmall(string $value, int $multiplier): string
    {
        if ($multiplier < 0) {
            throw new InvalidAmountException('Multiplier must not be negative.');
        }

        $digits = self::normalize($value);

        if ($multiplier === 0 || $digits === '0') {
            return '0';
        }

        $result = '';
        $carry = 0;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $product = ((int) $digits[$index]) * $multiplier + $carry;
            $result = ((string) ($product % 10)).$result;
            $carry = intdiv($product, 10);
        }

        while ($carry > 0) {
            $result = ((string) ($carry % 10)).$result;
            $carry = intdiv($carry, 10);
        }

        return self::normalize($result);
    }
}
