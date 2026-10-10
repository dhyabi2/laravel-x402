<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Facilitator\Exceptions;

/**
 * The facilitator accepted the request but did not answer in time.
 *
 * For `/settle` this outcome is indeterminate — the settlement may still have
 * gone through — so it is never retried automatically.
 */
final class FacilitatorTimeoutException extends FacilitatorException {}
