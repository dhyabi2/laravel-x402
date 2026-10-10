<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Exceptions;

/**
 * The resource server is misconfigured. Thrown at request time rather than
 * silently charging the wrong amount or paying the wrong wallet.
 */
final class ConfigurationException extends X402Exception {}
