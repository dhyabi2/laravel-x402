<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Messages;

use HydraLabs\X402\Core\Errors\ErrorCode;

/**
 * VerifyResponse, x402 core spec v2 §5.4.
 */
final class VerifyResponse
{
    /**
     * @param array<string, mixed> $extensions
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly ?string $invalidReason = null,
        public readonly ?string $invalidMessage = null,
        public readonly ?string $payer = null,
        public readonly array $extensions = [],
        public readonly array $extra = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $extensions = $data['extensions'] ?? [];
        $extra = $data['extra'] ?? [];

        return new self(
            isValid: (bool) ($data['isValid'] ?? false),
            invalidReason: self::nullableString($data['invalidReason'] ?? null),
            invalidMessage: self::nullableString($data['invalidMessage'] ?? null),
            payer: self::nullableString($data['payer'] ?? null),
            extensions: is_array($extensions) ? $extensions : [],
            extra: is_array($extra) ? $extra : [],
        );
    }

    public static function valid(?string $payer = null): self
    {
        return new self(isValid: true, payer: $payer);
    }

    public static function invalid(ErrorCode|string $reason, ?string $payer = null, ?string $message = null): self
    {
        $reason = $reason instanceof ErrorCode ? $reason->value : $reason;

        return new self(
            isValid: false,
            invalidReason: $reason,
            invalidMessage: $message ?? ErrorCode::describe($reason),
            payer: $payer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'isValid' => $this->isValid,
            'invalidReason' => $this->invalidReason,
            'invalidMessage' => $this->invalidMessage,
            'payer' => $this->payer,
            'extensions' => $this->extensions === [] ? null : $this->extensions,
            'extra' => $this->extra === [] ? null : $this->extra,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function reasonMessage(): string
    {
        return $this->invalidMessage ?? ErrorCode::describe($this->invalidReason);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
