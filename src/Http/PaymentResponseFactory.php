<?php

declare(strict_types=1);

namespace Requestway\X402\Http;

use Illuminate\Http\JsonResponse;
use Requestway\X402\Core\Messages\PaymentRequired;
use Requestway\X402\Core\Messages\SettleResponse;
use Requestway\X402\Core\Protocol\Codec;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the HTTP responses the x402 transport defines.
 *
 * Protocol v2 carries everything in headers and leaves the body to the server.
 * The body here is the same PaymentRequired object as JSON, so a browser, a
 * curl user or a client that has not implemented header parsing still sees
 * what is being asked for and why.
 */
final class PaymentResponseFactory
{
    /**
     * Shared caches must never store a 402: the requirements are
     * request-specific and short-lived.
     */
    public const CACHE_CONTROL = 'no-store, private';

    public function paymentRequired(
        Codec $codec,
        PaymentRequired $paymentRequired,
        int $status = Response::HTTP_PAYMENT_REQUIRED,
        ?SettleResponse $settlement = null,
    ): JsonResponse {
        $headers = [
            'Cache-Control' => self::CACHE_CONTROL,
        ];

        $header = $codec->paymentRequiredHeader();

        if ($header !== null) {
            $headers[$header] = $codec->encodePaymentRequired($paymentRequired);
        }

        if ($settlement !== null) {
            $headers[$codec->paymentResponseHeader()] = $codec->encodeSettlement($settlement);
        }

        return new JsonResponse(
            $codec->paymentRequiredBody($paymentRequired),
            $status,
            $headers,
        );
    }

    /**
     * Attach a settlement receipt to a response that has already been built.
     */
    public function attachSettlement(Response $response, Codec $codec, SettleResponse $settlement): Response
    {
        $response->headers->set($codec->paymentResponseHeader(), $codec->encodeSettlement($settlement));

        $this->markPrivate($response);

        return $response;
    }

    /**
     * A paid response is specific to the client that paid for it, so it must
     * not be stored by a shared cache.
     */
    public function markPrivate(Response $response): void
    {
        $cacheControl = $response->headers->get('Cache-Control');

        if ($cacheControl === null || $cacheControl === '') {
            $response->headers->set('Cache-Control', 'private');

            return;
        }

        $directives = array_map('trim', explode(',', strtolower($cacheControl)));

        if (! in_array('private', $directives, true) && ! in_array('no-store', $directives, true)) {
            $response->headers->set('Cache-Control', $cacheControl.', private');
        }
    }
}
