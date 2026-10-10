<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Errors;

/**
 * Error reasons defined in x402 core specification v2, section 9.
 *
 * Facilitators may return reasons outside this list; callers should treat an
 * unknown string as a terminal failure unless it is `settlement_pending`.
 */
enum ErrorCode: string
{
    case InsufficientFunds = 'insufficient_funds';
    case InvalidValidAfter = 'invalid_exact_evm_payload_authorization_valid_after';
    case InvalidValidBefore = 'invalid_exact_evm_payload_authorization_valid_before';
    case ValueMismatch = 'invalid_exact_evm_payload_authorization_value_mismatch';
    case InvalidSignature = 'invalid_exact_evm_payload_signature';
    case RecipientMismatch = 'invalid_exact_evm_payload_recipient_mismatch';
    case InvalidNetwork = 'invalid_network';
    case InvalidPayload = 'invalid_payload';
    case InvalidPaymentRequirements = 'invalid_payment_requirements';
    case InvalidScheme = 'invalid_scheme';
    case UnsupportedScheme = 'unsupported_scheme';
    case InvalidX402Version = 'invalid_x402_version';
    case InvalidTransactionState = 'invalid_transaction_state';
    case UnexpectedVerifyError = 'unexpected_verify_error';
    case UnexpectedSettleError = 'unexpected_settle_error';
    case SettlementPending = 'settlement_pending';

    /**
     * Permit2 tokens signal a missing one-time allowance, which the HTTP
     * transport maps to 412 rather than 402.
     */
    public const PERMIT2_ALLOWANCE_REQUIRED = 'permit2_allowance_required';

    /**
     * A human-readable sentence for the 402 body and logs.
     */
    public function message(): string
    {
        return match ($this) {
            self::InsufficientFunds => 'The paying wallet does not hold enough of the required asset.',
            self::InvalidValidAfter => 'The payment authorization is not valid yet.',
            self::InvalidValidBefore => 'The payment authorization has expired.',
            self::ValueMismatch => 'The authorized amount does not match the required amount.',
            self::InvalidSignature => 'The payment signature is invalid.',
            self::RecipientMismatch => 'The payment recipient does not match the required recipient.',
            self::InvalidNetwork => 'The requested network is not supported.',
            self::InvalidPayload => 'The payment payload is malformed or has already been used.',
            self::InvalidPaymentRequirements => 'The payment requirements are invalid.',
            self::InvalidScheme => 'The payment scheme is invalid.',
            self::UnsupportedScheme => 'The payment scheme is not supported.',
            self::InvalidX402Version => 'The x402 protocol version is not supported.',
            self::InvalidTransactionState => 'The settlement transaction failed on chain.',
            self::UnexpectedVerifyError => 'Payment verification failed unexpectedly.',
            self::UnexpectedSettleError => 'Payment settlement failed unexpectedly.',
            self::SettlementPending => 'The settlement transaction was broadcast but is not yet confirmed.',
        };
    }

    /**
     * Whether a failure with this reason may still succeed on chain.
     */
    public function isTerminal(): bool
    {
        return $this !== self::SettlementPending;
    }

    public static function tryFromReason(?string $reason): ?self
    {
        return $reason === null ? null : self::tryFrom($reason);
    }

    /**
     * Human-readable text for any reason string, known or not.
     */
    public static function describe(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return 'The payment was rejected.';
        }

        return self::tryFrom($reason)?->message() ?? sprintf('The payment was rejected (%s).', $reason);
    }
}
