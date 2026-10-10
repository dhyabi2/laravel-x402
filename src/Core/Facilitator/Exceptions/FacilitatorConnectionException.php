<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Facilitator\Exceptions;

/**
 * The facilitator could not be reached at all. Safe to retry: nothing was
 * committed, because no request completed.
 */
final class FacilitatorConnectionException extends FacilitatorException {}
