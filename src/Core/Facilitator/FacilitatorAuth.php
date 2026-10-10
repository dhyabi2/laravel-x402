<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Facilitator;

/**
 * Supplies authentication headers for each facilitator call.
 *
 * Some facilitators take a long-lived bearer token, which belongs in
 * `x402.facilitator.headers`. Others — the Coinbase CDP facilitator among them
 * — require a signature over the request path, so the headers have to be built
 * per call. Point `x402.facilitator.auth` at an implementation of this contract
 * for those.
 */
interface FacilitatorAuth
{
    /**
     * Headers for one facilitator operation: `verify`, `settle` or `supported`.
     *
     * @return array<string, string>
     */
    public function headers(string $operation): array;
}
