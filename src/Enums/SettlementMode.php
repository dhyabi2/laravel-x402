<?php

declare(strict_types=1);

namespace Requestway\X402\Enums;

use Requestway\X402\Core\Exceptions\ConfigurationException;

enum SettlementMode: string
{
    /**
     * Verify, run the route, settle, then respond. A settlement failure means
     * the client is not served. This is the spec's `authorization` flow and the
     * safe default.
     */
    case BeforeResponse = 'before_response';

    /**
     * Verify, run the route, respond, then settle in terminate(). Faster, at
     * the cost of a window in which a served response can fail to settle.
     */
    case AfterResponse = 'after_response';

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
            'x402 settlement mode must be before_response or after_response. Got "%s".',
            is_scalar($value) ? (string) $value : gettype($value)
        ));
    }
}
