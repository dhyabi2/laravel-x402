<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Protocol;

use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;

final class Version
{
    public const V1 = 1;

    public const V2 = 2;

    /** Versions this package can read. */
    public const SUPPORTED = [self::V1, self::V2];

    /** The version this package emits. */
    public const CURRENT = self::V2;

    /**
     * @throws MalformedPaymentException
     */
    public static function require(mixed $value): int
    {
        $version = is_numeric($value) ? (int) $value : null;

        if ($version === null || ! in_array($version, self::SUPPORTED, true)) {
            throw new MalformedPaymentException(sprintf(
                'Unsupported x402 protocol version "%s". This server speaks version %d.',
                is_scalar($value) ? (string) $value : gettype($value),
                self::CURRENT
            ));
        }

        return $version;
    }
}
