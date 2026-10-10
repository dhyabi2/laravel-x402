<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Money;

use HydraLabs\X402\Core\Exceptions\InvalidAmountException;

/**
 * Converts between human USD prices and atomic token units.
 *
 * All arithmetic is string based. Sub-atomic precision always rounds up, so a
 * route can never be underpriced by rounding.
 */
final class UsdConverter
{
    /**
     * Parse a price written as "0.01", "$0.01", "1", "$1.50" or 0.01.
     *
     * @return string a normalized decimal string with no currency symbol
     */
    public static function parsePrice(string|int|float $price): string
    {
        if (is_int($price)) {
            if ($price < 0) {
                throw new InvalidAmountException('Price must not be negative.');
            }

            return (string) $price;
        }

        if (is_float($price)) {
            if (! is_finite($price) || $price < 0) {
                throw new InvalidAmountException(sprintf('"%s" is not a valid price.', (string) $price));
            }

            // Floats are inherently lossy for money; format at the widest
            // precision any supported asset uses and let the caller's decimals
            // decide the real cut-off.
            $price = rtrim(rtrim(number_format($price, 18, '.', ''), '0'), '.');

            if ($price === '') {
                $price = '0';
            }

            return $price;
        }

        $cleaned = trim($price);
        $cleaned = ltrim($cleaned, '$');
        $cleaned = trim($cleaned);

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $cleaned, $matches) !== 1) {
            throw new InvalidAmountException(sprintf('"%s" is not a valid USD price.', $price));
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = isset($matches[2]) ? rtrim($matches[2], '0') : '';

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    /**
     * Convert a USD price to atomic units of an asset with the given decimals.
     *
     * Precision finer than one atomic unit rounds up.
     */
    public static function toAtomic(string|int|float $price, int $decimals): string
    {
        if ($decimals < 0 || $decimals > 36) {
            throw new InvalidAmountException(sprintf('Asset decimals must be between 0 and 36, got %d.', $decimals));
        }

        $parsed = self::parsePrice($price);
        [$whole, $fraction] = self::split($parsed);

        $keep = substr($fraction, 0, $decimals);
        $keep = str_pad($keep, $decimals, '0');
        $remainder = substr($fraction, $decimals);

        $atomic = Integers::normalize($whole.$keep);

        if ($remainder !== '' && ltrim($remainder, '0') !== '') {
            $atomic = Integers::increment($atomic);
        }

        return $atomic;
    }

    /**
     * Render atomic units back to a decimal string, for display only.
     */
    public static function toDecimalString(string $atomic, int $decimals): string
    {
        $atomic = Integers::normalize($atomic);

        if ($decimals === 0) {
            return $atomic;
        }

        $padded = str_pad($atomic, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$decimals);
        $fraction = rtrim(substr($padded, -$decimals), '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }

    /**
     * Format atomic units as a USD string for humans: $0.01.
     */
    public static function format(string $atomic, int $decimals): string
    {
        $value = self::toDecimalString($atomic, $decimals);

        if (! str_contains($value, '.')) {
            return '$'.$value.'.00';
        }

        [$whole, $fraction] = explode('.', $value, 2);

        return '$'.$whole.'.'.str_pad(substr($fraction, 0, 6), 2, '0');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function split(string $decimal): array
    {
        if (! str_contains($decimal, '.')) {
            return [$decimal, ''];
        }

        /** @var array{0: string, 1: string} $parts */
        $parts = explode('.', $decimal, 2);

        return $parts;
    }
}
