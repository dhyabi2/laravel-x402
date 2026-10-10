<?php

declare(strict_types=1);

namespace Requestway\X402\Facilitators;

use Closure;
use PHPUnit\Framework\Assert;
use Requestway\X402\Core\Errors\ErrorCode;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\SupportedKind;
use Requestway\X402\Core\Messages\SupportedResponse;
use Requestway\X402\Core\Messages\VerifyResponse;
use Requestway\X402\Core\Money\UsdConverter;
use Requestway\X402\Core\Protocol\Version;

/**
 * An in-memory facilitator for tests and local development.
 *
 * Accepts every payment unless told otherwise, records what it was asked to
 * do, and exposes assertions in the style of Http::fake().
 *
 *     X402::fake();
 *     $this->get('/premium');
 *     X402::assertSettled();
 */
final class FakeFacilitator implements Facilitator
{
    /** @var list<array{payload: PaymentPayload, requirements: PaymentRequirements, resource: ResourceInfo}> */
    private array $verified = [];

    /** @var list<array{payload: PaymentPayload, requirements: PaymentRequirements, resource: ResourceInfo, response: SettleResponse}> */
    private array $settled = [];

    private ?Closure $verifyUsing = null;

    private ?Closure $settleUsing = null;

    /** @var list<SupportedKind>|null */
    private ?array $supportedKinds = null;

    private int $transactionCounter = 0;

    public function verify(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): VerifyResponse
    {
        $this->verified[] = compact('payload', 'requirements', 'resource');

        if ($this->verifyUsing !== null) {
            /** @var VerifyResponse|bool $result */
            $result = ($this->verifyUsing)($payload, $requirements, $resource);

            if ($result instanceof VerifyResponse) {
                return $result;
            }

            if ($result === false) {
                return VerifyResponse::invalid(ErrorCode::InvalidPayload, $payload->payerAddress());
            }
        }

        return VerifyResponse::valid($payload->payerAddress());
    }

    public function settle(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): SettleResponse
    {
        $response = $this->settleResponse($payload, $requirements, $resource);

        $this->settled[] = [
            'payload' => $payload,
            'requirements' => $requirements,
            'resource' => $resource,
            'response' => $response,
        ];

        return $response;
    }

    public function supported(): SupportedResponse
    {
        return new SupportedResponse(
            kinds: $this->supportedKinds ?? [
                new SupportedKind(Version::V2, PaymentRequirements::SCHEME_EXACT, 'eip155:*'),
            ],
            extensions: [],
            signers: ['eip155:*' => ['0x0000000000000000000000000000000000000f33']],
        );
    }

    /**
     * Make verification fail.
     *
     * @param ErrorCode|string|Closure(PaymentPayload, PaymentRequirements, ResourceInfo): (VerifyResponse|bool) $reason
     */
    public function rejectVerification(ErrorCode|string|Closure $reason = ErrorCode::InvalidPayload): self
    {
        $this->verifyUsing = $reason instanceof Closure
            ? $reason
            : static fn (PaymentPayload $payload): VerifyResponse => VerifyResponse::invalid($reason, $payload->payerAddress());

        return $this;
    }

    /**
     * Make settlement fail.
     *
     * @param ErrorCode|string|Closure(PaymentPayload, PaymentRequirements, ResourceInfo): (SettleResponse|bool) $reason
     */
    public function rejectSettlement(ErrorCode|string|Closure $reason = ErrorCode::InvalidTransactionState): self
    {
        $this->settleUsing = $reason instanceof Closure
            ? $reason
            : static fn (PaymentPayload $payload, PaymentRequirements $requirements): SettleResponse => SettleResponse::failed(
                $requirements->network,
                $reason,
                $payload->payerAddress(),
            );

        return $this;
    }

    /**
     * Answer /settle with the non-terminal settlement_pending reason.
     */
    public function pendingSettlement(string $transaction = '0xpending'): self
    {
        $this->settleUsing = static fn (PaymentPayload $payload, PaymentRequirements $requirements): SettleResponse => SettleResponse::failed(
            $requirements->network,
            ErrorCode::SettlementPending,
            $payload->payerAddress(),
            $transaction,
        );

        return $this;
    }

    /**
     * @param list<SupportedKind> $kinds
     */
    public function supports(array $kinds): self
    {
        $this->supportedKinds = $kinds;

        return $this;
    }

    public function reset(): self
    {
        $this->verified = [];
        $this->settled = [];
        $this->verifyUsing = null;
        $this->settleUsing = null;
        $this->supportedKinds = null;
        $this->transactionCounter = 0;

        return $this;
    }

    /**
     * @return list<array{payload: PaymentPayload, requirements: PaymentRequirements, resource: ResourceInfo}>
     */
    public function verifications(): array
    {
        return $this->verified;
    }

    /**
     * @return list<array{payload: PaymentPayload, requirements: PaymentRequirements, resource: ResourceInfo, response: SettleResponse}>
     */
    public function settlements(): array
    {
        return $this->settled;
    }

    public function verifyCount(): int
    {
        return count($this->verified);
    }

    public function settleCount(): int
    {
        return count($this->settled);
    }

    public function assertVerified(?int $times = null): self
    {
        if ($times === null) {
            Assert::assertNotEmpty($this->verified, 'Expected a payment to be verified, but none was.');

            return $this;
        }

        Assert::assertCount($times, $this->verified, sprintf(
            'Expected %d payment verification(s), got %d.',
            $times,
            count($this->verified)
        ));

        return $this;
    }

    public function assertSettled(?int $times = null): self
    {
        $successful = array_values(array_filter(
            $this->settled,
            static fn (array $settlement): bool => $settlement['response']->success
        ));

        if ($times === null) {
            Assert::assertNotEmpty($successful, 'Expected a payment to be settled, but none was.');

            return $this;
        }

        Assert::assertCount($times, $successful, sprintf(
            'Expected %d settled payment(s), got %d.',
            $times,
            count($successful)
        ));

        return $this;
    }

    /**
     * Assert that a resource was paid for, optionally at an exact USD price.
     *
     * @param string $resource a full URL, or any substring of one
     */
    public function assertSettledFor(string $resource, string|int|float|null $usd = null, int $decimals = 6): self
    {
        $matches = array_values(array_filter(
            $this->settled,
            static fn (array $settlement): bool => $settlement['response']->success
                && str_contains($settlement['resource']->url, $resource)
        ));

        Assert::assertNotEmpty($matches, sprintf(
            'Expected a settled payment for "%s". Settled: %s',
            $resource,
            $this->settled === [] ? 'none' : implode(', ', array_map(
                static fn (array $settlement): string => $settlement['resource']->url,
                $this->settled
            ))
        ));

        if ($usd === null) {
            return $this;
        }

        $expected = UsdConverter::toAtomic($usd, $decimals);
        $amounts = array_map(
            static fn (array $settlement): string => $settlement['requirements']->amount,
            $matches
        );

        Assert::assertContains($expected, $amounts, sprintf(
            'Expected a settled payment of %s (%s atomic units) for "%s", got %s.',
            UsdConverter::format($expected, $decimals),
            $expected,
            $resource,
            implode(', ', $amounts)
        ));

        return $this;
    }

    public function assertNotCharged(): self
    {
        Assert::assertSame([], array_values(array_filter(
            $this->settled,
            static fn (array $settlement): bool => $settlement['response']->success
        )), 'Expected no payment to be charged, but one was settled.');

        return $this;
    }

    public function assertNothingVerified(): self
    {
        Assert::assertSame([], $this->verified, 'Expected no payment to be verified, but one was.');

        return $this;
    }

    private function settleResponse(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): SettleResponse
    {
        if ($this->settleUsing !== null) {
            /** @var SettleResponse|bool $result */
            $result = ($this->settleUsing)($payload, $requirements, $resource);

            if ($result instanceof SettleResponse) {
                return $result;
            }

            if ($result === false) {
                return SettleResponse::failed(
                    $requirements->network,
                    ErrorCode::UnexpectedSettleError,
                    $payload->payerAddress(),
                );
            }
        }

        return SettleResponse::settled(
            network: $requirements->network,
            transaction: sprintf('0x%064x', ++$this->transactionCounter),
            payer: $payload->payerAddress(),
            amount: $requirements->amount,
        );
    }
}
