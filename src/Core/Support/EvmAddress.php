<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Support;

final class EvmAddress
{
    public static function isValid(string $address): bool
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $address) === 1;
    }

    /**
     * Compare two addresses, ignoring EIP-55 checksum casing.
     */
    public static function matches(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        return strtolower(trim($a)) === strtolower(trim($b));
    }

    public static function normalize(string $address): string
    {
        return strtolower(trim($address));
    }

    /**
     * Shorten an address for display and logs: 0x1234…cdef.
     */
    public static function abbreviate(?string $address): string
    {
        if ($address === null || $address === '') {
            return '-';
        }

        if (strlen($address) <= 12) {
            return $address;
        }

        return substr($address, 0, 6).'…'.substr($address, -4);
    }
}
