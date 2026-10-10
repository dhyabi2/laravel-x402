<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Validation;

use Requestway\X402\Core\Errors\ErrorCode;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Money\Integers;
use Requestway\X402\Core\Protocol\Version;
use Requestway\X402\Core\Support\Clock;
use Requestway\X402\Core\Support\EvmAddress;
use Requestway\X402\Core\Support\SystemClock;

/**
 * Local validation of a payment payload against the requirements this server
 * advertised, run before any facilitator call.
 *
 * Everything here is a check the resource server can make on its own, cheaply,
 * and must make: the facilitator validates the signature and the chain state,
 * but it cannot know which resource the payment was bought for, which of
 * several offers the client should have taken, or whether this exact payload
 * has already bought something.
 */
final class PaymentValidator
{
    /**
     * Tolerance for clock skew between this server and the client when
     * checking an authorization's validity window.
     */
    public const CLOCK_SKEW_SECONDS = 6;

    public function __construct(
        private readonly Clock $clock = new SystemClock,
        private readonly int $clockSkewSeconds = self::CLOCK_SKEW_SECONDS,
    ) {}

    /**
     * Select the advertised requirement a payload paid for, and reject it if
     * it does not satisfy any of them.
     *
     * @param list<PaymentRequirements> $accepts
     * @return array{0: PaymentRequirements|null, 1: ValidationFailure|null}
     */
    public function match(PaymentPayload $payload, array $accepts): array
    {
        if ($accepts === []) {
            return [null, ValidationFailure::code(
                ErrorCode::InvalidPaymentRequirements,
                'This route advertises no acceptable payment method.',
                500
            )];
        }

        if ($payload->x402Version === Version::V1) {
            return $this->matchLegacy($payload, $accepts);
        }

        $accepted = $payload->accepted;

        if ($accepted === null) {
            return [null, ValidationFailure::malformed('Payment payload is missing "accepted".')];
        }

        foreach ($accepts as $requirements) {
            if ($requirements->isSatisfiedBy($accepted)) {
                return [$requirements, null];
            }
        }

        return [null, $this->explainMismatch($accepted, $accepts)];
    }

    /**
     * Validate a payload against the requirement it matched.
     *
     * @param list<string> $acceptableResourceUrls the current request URL, plus any equivalent form of it
     */
    public function validate(
        PaymentPayload $payload,
        PaymentRequirements $requirements,
        array $acceptableResourceUrls,
    ): ?ValidationFailure {
        if ($payload->scheme() !== $requirements->scheme) {
            return ValidationFailure::code(ErrorCode::InvalidScheme);
        }

        if ($payload->network() !== $requirements->network) {
            return ValidationFailure::code(ErrorCode::InvalidNetwork);
        }

        if (($failure = $this->validateResource($payload, $acceptableResourceUrls)) !== null) {
            return $failure;
        }

        if (($failure = $this->validateAuthorization($payload, $requirements)) !== null) {
            return $failure;
        }

        return null;
    }

    /**
     * A v2 payload may echo the resource it is paying for. When it does, it
     * must be this resource: otherwise a payload bought for a cheap endpoint
     * could be presented at an expensive one.
     *
     * @param list<string> $acceptableResourceUrls
     */
    private function validateResource(PaymentPayload $payload, array $acceptableResourceUrls): ?ValidationFailure
    {
        $claimed = $payload->resourceUrl();

        if ($claimed === null || $claimed === '' || $acceptableResourceUrls === []) {
            return null;
        }

        foreach ($acceptableResourceUrls as $url) {
            if ($this->resourceUrlsMatch($claimed, $url)) {
                return null;
            }
        }

        return ValidationFailure::code(
            ErrorCode::InvalidPayload,
            'The payment was authorized for a different resource.'
        );
    }

    /**
     * Validate the scheme payload's own amount, recipient and validity window.
     *
     * These duplicate checks the facilitator also makes. They are made here
     * because a payment that is already expired or underpaid should not cost a
     * network round trip, and because a facilitator is a third party.
     */
    private function validateAuthorization(
        PaymentPayload $payload,
        PaymentRequirements $requirements,
    ): ?ValidationFailure {
        $authorization = $payload->authorization();

        if ($authorization === null) {
            // A scheme this package does not model locally; the facilitator
            // remains authoritative.
            return null;
        }

        $value = $payload->authorizedValue();

        if ($value !== null && ! Integers::greaterThanOrEqual($value, $requirements->amount)) {
            return ValidationFailure::code(ErrorCode::ValueMismatch);
        }

        $recipient = $payload->recipientAddress();

        if ($recipient !== null && ! EvmAddress::matches($recipient, $requirements->payTo)) {
            return ValidationFailure::code(ErrorCode::RecipientMismatch);
        }

        $now = $this->clock->now();
        $validAfter = $payload->validAfter();

        if ($validAfter !== null && $validAfter > $now + $this->clockSkewSeconds) {
            return ValidationFailure::code(ErrorCode::InvalidValidAfter);
        }

        $validBefore = $payload->validBefore();

        if ($validBefore !== null && $validBefore <= $now - $this->clockSkewSeconds) {
            return ValidationFailure::code(ErrorCode::InvalidValidBefore);
        }

        return null;
    }

    /**
     * @param list<PaymentRequirements> $accepts
     */
    private function explainMismatch(PaymentRequirements $accepted, array $accepts): ValidationFailure
    {
        $sameNetwork = array_values(array_filter(
            $accepts,
            static fn (PaymentRequirements $requirements): bool => $requirements->network === $accepted->network
        ));

        if ($sameNetwork === []) {
            return ValidationFailure::code(
                ErrorCode::InvalidNetwork,
                sprintf('Payments on %s are not accepted for this resource.', $accepted->network)
            );
        }

        foreach ($sameNetwork as $requirements) {
            if ($requirements->scheme !== $accepted->scheme) {
                return ValidationFailure::code(ErrorCode::InvalidScheme);
            }

            if (! EvmAddress::matches($requirements->asset, $accepted->asset)) {
                return ValidationFailure::code(
                    ErrorCode::InvalidPayload,
                    'The payment uses an asset this resource does not accept.'
                );
            }

            if (! EvmAddress::matches($requirements->payTo, $accepted->payTo)) {
                return ValidationFailure::code(ErrorCode::RecipientMismatch);
            }

            if ($requirements->isUnderpaidBy($accepted)) {
                return ValidationFailure::code(ErrorCode::ValueMismatch);
            }
        }

        return ValidationFailure::code(
            ErrorCode::InvalidPayload,
            'The payment does not match the requirements advertised for this resource.'
        );
    }

    /**
     * Protocol v1 payloads carry no `accepted` echo, so — as in the reference
     * server — the requirement is matched on scheme and network alone, and the
     * inner authorization is then checked against it.
     *
     * @param list<PaymentRequirements> $accepts
     * @return array{0: PaymentRequirements|null, 1: ValidationFailure|null}
     */
    private function matchLegacy(PaymentPayload $payload, array $accepts): array
    {
        foreach ($accepts as $requirements) {
            if ($requirements->scheme === $payload->scheme() && $requirements->network === $payload->network()) {
                return [$requirements, null];
            }
        }

        return [null, ValidationFailure::code(
            ErrorCode::InvalidNetwork,
            sprintf(
                'Payments using scheme "%s" on %s are not accepted for this resource.',
                $payload->scheme(),
                $payload->network()
            )
        )];
    }

    /**
     * Compare two resource URLs, ignoring differences that do not change which
     * resource is addressed: scheme case, host case, a default port, and a
     * trailing slash.
     */
    private function resourceUrlsMatch(string $a, string $b): bool
    {
        return $this->canonicalUrl($a) === $this->canonicalUrl($b);
    }

    private function canonicalUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['host'])) {
            return rtrim(strtolower(trim($url)), '/');
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? null;

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.strtolower($parts['host'])
            .($port === null ? '' : ':'.$port)
            .$path
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
