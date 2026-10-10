<?php

declare(strict_types=1);

namespace HydraLabs\X402\Http\Middleware;

use Illuminate\Http\Request;

/**
 * Applies #[RequiresPayment] attributes app-wide.
 *
 * Push this into a middleware group to charge for every action that carries
 * the attribute, without adding the `x402` middleware to each route. Requests
 * to routes with no attribute pass straight through.
 */
final class HonorPaymentAttributes extends RequirePayment
{
    /**
     * @param array<string, mixed> $options
     */
    protected function shouldProtect(Request $request, array $options): bool
    {
        $route = $request->route();

        if (! $route instanceof \Illuminate\Routing\Route) {
            return false;
        }

        // Routes with the x402 middleware are handled by that middleware, so
        // skip them here rather than charging twice.
        if ($this->x402->routes()->middlewareOptionsFor($route) !== null) {
            return false;
        }

        return $this->x402->routes()->attributeOptions($route) !== []
            || $this->x402->routes()->macroOptions($route) !== [];
    }
}
