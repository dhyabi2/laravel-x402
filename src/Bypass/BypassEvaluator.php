<?php

declare(strict_types=1);

namespace Requestway\X402\Bypass;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Routing\RoutePayment;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Decides whether a request is served without payment.
 *
 * Rules are checked cheapest first. The reason is returned so it can be logged
 * and surfaced on the response, which makes "why wasn't this charged?" a
 * question with an answer.
 */
final class BypassEvaluator
{
    /** @var list<Closure(Request, RoutePayment): bool> */
    private array $callbacks = [];

    public function __construct(private readonly X402Config $config) {}

    /**
     * @param Closure(Request, RoutePayment): bool $callback
     */
    public function register(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function flush(): void
    {
        $this->callbacks = [];
    }

    /**
     * The name of the first matching rule, or null when payment is required.
     */
    public function reason(Request $request, RoutePayment $route): ?string
    {
        if ($route->isFree()) {
            return 'free';
        }

        if ($this->matchesIp($request)) {
            return 'ip-allowlist';
        }

        if ($this->matchesApiKey($request)) {
            return 'api-key';
        }

        if ($route->freeForAuth && $this->isAuthenticated()) {
            return 'authenticated';
        }

        foreach ($this->callbacks as $callback) {
            if ($callback($request, $route) === true) {
                return 'custom';
            }
        }

        return null;
    }

    /**
     * A human-readable summary of a route's bypass rules, for x402:routes.
     *
     * @return list<string>
     */
    public function describe(RoutePayment $route): array
    {
        $rules = [];

        if ($route->freeForAuth) {
            $rules[] = 'auth';
        }

        $ips = $this->ipAllowlist();

        if ($ips !== []) {
            $rules[] = sprintf('ips(%d)', count($ips));
        }

        if ($this->apiKeys() !== []) {
            $rules[] = 'api-key';
        }

        if ($this->callbacks !== []) {
            $rules[] = 'closure';
        }

        return $rules;
    }

    private function matchesIp(Request $request): bool
    {
        $allowlist = $this->ipAllowlist();
        $ip = $request->ip();

        return $allowlist !== [] && $ip !== null && IpUtils::checkIp($ip, $allowlist);
    }

    private function matchesApiKey(Request $request): bool
    {
        $keys = $this->apiKeys();

        if ($keys === []) {
            return false;
        }

        $header = $this->config->get('bypass.api_keys.header', 'X-API-Key');
        $presented = $request->header(is_string($header) ? $header : 'X-API-Key');

        if (! is_string($presented) || $presented === '') {
            return false;
        }

        foreach ($keys as $key) {
            if (hash_equals($key, $presented)) {
                return true;
            }
        }

        return false;
    }

    private function isAuthenticated(): bool
    {
        $guards = $this->config->get('bypass.auth.guards', []);
        $guards = array_values(array_filter(is_array($guards) ? $guards : [], 'is_string'));

        if ($guards === []) {
            return Auth::hasUser() || Auth::check();
        }

        foreach ($guards as $guard) {
            try {
                if (Auth::guard($guard)->check()) {
                    return true;
                }
            } catch (InvalidArgumentException) {
                // A guard named in config that the application does not define
                // must not take the route down, and must not hand the resource
                // out for free either: treat it as not authenticated.
                continue;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function ipAllowlist(): array
    {
        $ips = $this->config->get('bypass.ips', []);

        return array_values(array_filter(array_map(
            static fn (mixed $ip): string => is_string($ip) ? trim($ip) : '',
            is_array($ips) ? $ips : []
        ), static fn (string $ip): bool => $ip !== ''));
    }

    /**
     * @return list<string>
     */
    private function apiKeys(): array
    {
        $keys = $this->config->get('bypass.api_keys.keys', []);

        return array_values(array_filter(array_map(
            static fn (mixed $key): string => is_string($key) ? trim($key) : '',
            is_array($keys) ? $keys : []
        ), static fn (string $key): bool => $key !== ''));
    }
}
