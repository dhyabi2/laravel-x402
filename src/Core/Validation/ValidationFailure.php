<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Validation;

use Requestway\X402\Core\Errors\ErrorCode;

/**
 * A locally detected reason to reject a payment, before the facilitator is
 * involved. Carries a spec error code so the client sees the same vocabulary
 * it would get from a facilitator.
 */
final class ValidationFailure
{
    public function __construct(
        public readonly ErrorCode $code,
        public readonly string $message,
        public readonly int $status = 402,
    ) {}

    public static function code(ErrorCode $code, ?string $message = null, int $status = 402): self
    {
        return new self($code, $message ?? $code->message(), $status);
    }

    /**
     * A payload we could not even parse. The HTTP transport maps this to 400.
     */
    public static function malformed(string $message): self
    {
        return new self(ErrorCode::InvalidPayload, $message, 400);
    }

    public function reason(): string
    {
        return $this->code->value;
    }
}
