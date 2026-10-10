<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Messages;

use Requestway\X402\Core\Errors\ErrorCode;

/**
 * SettlementResponse, x402 core spec v2 §5.3.
 *
 * Returned on the base64 `PAYMENT-RESPONSE` header. The facilitator's
 * `extensionResponses` sidechannel is deliberately not represented here: the
 * spec forbids forwarding it to buyers.
 */
final class SettleResponse
{
    /**
     * @param array<string, mixed> $extensions
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $transaction,
        public readonly string $network,
        public readonly ?string $errorReason = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $payer = null,
        public readonly ?string $amount = null,
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
            success: (bool) ($data['success'] ?? false),
            transaction: is_string($data['transaction'] ?? null) ? $data['transaction'] : '',
            network: is_string($data['network'] ?? null) ? $data['network'] : '',
            errorReason: self::nullableString($data['errorReason'] ?? null),
            errorMessage: self::nullableString($data['errorMessage'] ?? null),
            payer: self::nullableString($data['payer'] ?? null),
            amount: self::nullableString($data['amount'] ?? null),
            extensions: is_array($extensions) ? $extensions : [],
            extra: is_array($extra) ? $extra : [],
        );
    }

    public static function settled(string $network, string $transaction, ?string $payer = null, ?string $amount = null): self
    {
        return new self(
            success: true,
            transaction: $transaction,
            network: $network,
            payer: $payer,
            amount: $amount,
        );
    }

    public static function failed(string $network, ErrorCode|string $reason, ?string $payer = null, string $transaction = '', ?string $message = null): self
    {
        $reason = $reason instanceof ErrorCode ? $reason->value : $reason;

        return new self(
            success: false,
            transaction: $transaction,
            network: $network,
            errorReason: $reason,
            errorMessage: $message ?? ErrorCode::describe($reason),
            payer: $payer,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'success' => $this->success,
            'transaction' => $this->transaction,
            'network' => $this->network,
        ];

        foreach (['errorReason' => $this->errorReason, 'errorMessage' => $this->errorMessage, 'payer' => $this->payer, 'amount' => $this->amount] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        if ($this->extensions !== []) {
            $data['extensions'] = $this->extensions;
        }

        return $data;
    }

    /**
     * A settlement that was broadcast but not confirmed. The spec marks this
     * non-terminal: the transaction may still land, so it must not be reported
     * as a hard failure.
     */
    public function isPending(): bool
    {
        return ! $this->success
            && $this->errorReason === ErrorCode::SettlementPending->value
            && $this->transaction !== '';
    }

    public function reasonMessage(): string
    {
        return $this->errorMessage ?? ErrorCode::describe($this->errorReason);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
