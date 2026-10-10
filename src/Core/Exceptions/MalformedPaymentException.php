<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Exceptions;

/**
 * The client sent something that is not a decodable x402 payment payload.
 * Maps to HTTP 400 per the HTTP transport spec.
 */
final class MalformedPaymentException extends X402Exception {}
