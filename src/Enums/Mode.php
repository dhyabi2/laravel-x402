<?php

declare(strict_types=1);

namespace HydraLabs\X402\Enums;

use HydraLabs\X402\Core\Exceptions\ConfigurationException;

enum Mode: string
{
    /** Charge for access. */
    case Enforce = 'enforce';

    /**
     * Never charge: record what would have been charged and serve the request.
     * Lets an operator measure agent demand before turning payments on.
     */
    case Observe = 'observe';

    /** Disable the package entirely; routes behave as if unprotected. */
    case Off = 'off';

    public static function parse(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $mode = self::tryFrom(strtolower(trim($value)));

            if ($mode !== null) {
                return $mode;
            }
        }

        throw new ConfigurationException(sprintf(
            'x402 mode must be one of enforce, observe, off. Got "%s".',
            is_scalar($value) ? (string) $value : gettype($value)
        ));
    }
}
