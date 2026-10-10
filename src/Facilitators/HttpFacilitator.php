<?php

declare(strict_types=1);

namespace Requestway\X402\Facilitators;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorConnectionException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorResponseException;
use Requestway\X402\Core\Facilitator\Exceptions\FacilitatorTimeoutException;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Facilitator\FacilitatorAuth;
use Requestway\X402\Core\Messages\PaymentPayload;
use Requestway\X402\Core\Messages\PaymentRequirements;
use Requestway\X402\Core\Messages\ResourceInfo;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Messages\SupportedResponse;
use Requestway\X402\Core\Messages\VerifyResponse;
use Requestway\X402\Core\Protocol\CodecRegistry;
use Throwable;

/**
 * Talks to a facilitator over HTTP, per x402 core spec v2 §7.
 *
 * Works with the Coinbase CDP facilitator, the x402 Foundation facilitator and
 * any self-hosted one: the only differences are the base URL and the auth
 * headers.
 *
 * Retries cover connection failures only. A timed-out `/settle` is an
 * indeterminate outcome — the payment may have gone through — so it is never
 * retried unless an operator explicitly opts in.
 *
 * Static headers cover a facilitator with a long-lived token; a facilitator
 * that signs each request takes a {@see FacilitatorAuth} instead.
 */
final class HttpFacilitator implements Facilitator
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CodecRegistry $codecs,
        private readonly string $baseUrl,
        private readonly array $headers = [],
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 5,
        private readonly int $retries = 2,
        private readonly int $settleRetries = 0,
        private readonly int $retryDelay = 250,
        private readonly bool $verifySsl = true,
        private readonly ?FacilitatorAuth $auth = null,
    ) {}

    public function verify(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): VerifyResponse
    {
        return VerifyResponse::fromArray($this->post(
            'verify',
            $this->codecs->for($payload->x402Version)->facilitatorRequest($payload, $requirements, $resource),
            $this->retries,
        ));
    }

    public function settle(PaymentPayload $payload, PaymentRequirements $requirements, ResourceInfo $resource): SettleResponse
    {
        return SettleResponse::fromArray($this->post(
            'settle',
            $this->codecs->for($payload->x402Version)->facilitatorRequest($payload, $requirements, $resource),
            $this->settleRetries,
        ));
    }

    public function supported(): SupportedResponse
    {
        $response = $this->send('supported', fn (PendingRequest $request): Response => $request->get(
            $this->baseUrl.'/supported'
        ), $this->retries);

        return SupportedResponse::fromArray($this->decode('supported', $response));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $operation, array $body, int $retries): array
    {
        $response = $this->send(
            $operation,
            fn (PendingRequest $request): Response => $request->post($this->baseUrl.'/'.$operation, $body),
            $retries,
        );

        return $this->decode($operation, $response);
    }

    /**
     * @param callable(PendingRequest): Response $send
     */
    private function send(string $operation, callable $send, int $retries): Response
    {
        $request = $this->http
            ->asJson()
            ->acceptJson()
            ->withHeaders(array_merge($this->headers, $this->auth?->headers($operation) ?? []))
            ->timeout(max(1, $this->timeout))
            ->connectTimeout(max(1, $this->connectTimeout))
            ->withOptions(['verify' => $this->verifySsl]);

        if ($retries > 0) {
            $request = $request->retry(
                $retries + 1,
                max(0, $this->retryDelay),
                // Only a failure to reach the facilitator is safe to repeat.
                // An HTTP error means it answered, and repeating a settle it
                // already processed could charge the payer twice.
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );
        }

        try {
            $response = $send($request);
        } catch (ConnectionException $exception) {
            throw $this->connectionFailure($operation, $exception);
        } catch (Throwable $exception) {
            throw new FacilitatorResponseException(
                sprintf('Facilitator %s failed: %s', $operation, $exception->getMessage()),
                operation: $operation,
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new FacilitatorResponseException(
                sprintf(
                    'Facilitator %s returned HTTP %d: %s',
                    $operation,
                    $response->status(),
                    $this->excerpt($response->body())
                ),
                operation: $operation,
                status: $response->status(),
            );
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $operation, Response $response): array
    {
        /** @var mixed $decoded */
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new FacilitatorResponseException(
                sprintf('Facilitator %s returned a non-JSON body: %s', $operation, $this->excerpt($response->body())),
                operation: $operation,
                status: $response->status(),
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function connectionFailure(string $operation, ConnectionException $exception): FacilitatorConnectionException|FacilitatorTimeoutException
    {
        $message = strtolower($exception->getMessage());
        $timedOut = str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'operation aborted');

        if ($timedOut) {
            return new FacilitatorTimeoutException(
                sprintf('Facilitator %s timed out after %ds.', $operation, $this->timeout),
                operation: $operation,
                previous: $exception,
            );
        }

        return new FacilitatorConnectionException(
            sprintf('Could not reach the facilitator at %s: %s', $this->baseUrl, $exception->getMessage()),
            operation: $operation,
            previous: $exception,
        );
    }

    private function excerpt(string $body, int $limit = 200): string
    {
        $compact = trim((string) preg_replace('/\s+/', ' ', $body));

        if ($compact === '') {
            return '<empty response>';
        }

        return strlen($compact) <= $limit ? $compact : substr($compact, 0, $limit - 3).'...';
    }
}
