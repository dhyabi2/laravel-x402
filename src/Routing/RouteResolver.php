<?php

declare(strict_types=1);

namespace Requestway\X402\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Requestway\X402\Attributes\RequiresPayment;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Exceptions\ConfigurationException;
use Requestway\X402\Core\Networks\Network;
use Requestway\X402\Enums\SettlementMode;
use Requestway\X402\Pricing\PriceResolver;

/**
 * Builds a route's resolved payment configuration.
 *
 * Options are layered, least specific first: config defaults, then the
 * #[RequiresPayment] attribute, then the ->paid() macro, then the middleware
 * parameters written on the route itself.
 */
final class RouteResolver
{
    /** @var array<string, array<string, mixed>> */
    private array $attributeCache = [];

    public function __construct(private readonly X402Config $config) {}

    /**
     * @param array<string, mixed> $middlewareOptions
     *
     * @throws ConfigurationException
     */
    public function resolve(Request $request, array $middlewareOptions = [], ?PriceResolver $priceResolver = null): RoutePayment
    {
        $route = $request->route();
        $route = $route instanceof Route ? $route : null;

        $options = array_merge(
            $this->attributeOptions($route),
            $this->macroOptions($route),
            $middlewareOptions,
        );

        return $this->build($request, $route, $options, $priceResolver);
    }

    /**
     * Resolve a route without a live request, for discovery and the
     * x402:routes command. Returns null when the route is not paid.
     *
     * @throws ConfigurationException
     */
    public function resolveForRoute(Route $route): ?RoutePayment
    {
        $middlewareOptions = $this->middlewareOptionsFor($route);
        $attributeOptions = $this->attributeOptions($route);
        $macroOptions = $this->macroOptions($route);

        if ($middlewareOptions === null && $attributeOptions === [] && $macroOptions === []) {
            return null;
        }

        return $this->build(
            request: null,
            route: $route,
            options: array_merge($attributeOptions, $macroOptions, $middlewareOptions ?? []),
            priceResolver: null,
        );
    }

    /**
     * Whether a route is protected by this package at all.
     */
    public function isPaid(Route $route): bool
    {
        return $this->middlewareOptionsFor($route) !== null
            || $this->attributeOptions($route) !== []
            || $this->macroOptions($route) !== [];
    }

    /**
     * The middleware options declared on a route, or null when the route has
     * no x402 middleware. An empty array means the middleware is present with
     * no parameters.
     *
     * @return array<string, mixed>|null
     */
    public function middlewareOptionsFor(Route $route): ?array
    {
        $options = null;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'x402') {
                $options ??= [];

                continue;
            }

            // A parameterised entry is the more specific declaration, so it
            // wins over a bare `x402` added by the paid() macro.
            if (Str::startsWith($middleware, 'x402:')) {
                $options = array_merge($options ?? [], RouteOptionParser::parse(array_map(
                    'trim',
                    explode(',', Str::after($middleware, 'x402:'))
                )));
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributeOptions(?Route $route): array
    {
        if ($route === null) {
            return [];
        }

        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return [];
        }

        if (array_key_exists($action, $this->attributeCache)) {
            return $this->attributeCache[$action];
        }

        [$class, $method] = explode('@', $action, 2);
        $options = [];

        if (! class_exists($class)) {
            return $this->attributeCache[$action] = [];
        }

        try {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getAttributes(RequiresPayment::class) as $attribute) {
                $options = array_merge($options, $attribute->newInstance()->toOptions());
            }

            if ($reflection->hasMethod($method)) {
                $options = array_merge($options, $this->methodAttributeOptions($reflection->getMethod($method)));
            }
        } catch (ReflectionException) {
            $options = [];
        }

        return $this->attributeCache[$action] = $options;
    }

    /**
     * Options set by the ->paid() route macro.
     *
     * @return array<string, mixed>
     */
    public function macroOptions(?Route $route): array
    {
        $options = $route?->getAction('x402');

        return is_array($options) ? $options : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function methodAttributeOptions(ReflectionMethod $method): array
    {
        $options = [];

        foreach ($method->getAttributes(RequiresPayment::class) as $attribute) {
            $options = array_merge($options, $attribute->newInstance()->toOptions());
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException
     */
    private function build(?Request $request, ?Route $route, array $options, ?PriceResolver $priceResolver): RoutePayment
    {
        $uri = $route?->uri();
        $method = $this->primaryMethod($route);
        $context = $route === null ? 'this route' : trim(($method ?? '').' /'.ltrim((string) $uri, '/'));

        $payTo = isset($options['pay_to']) && is_string($options['pay_to'])
            ? $this->config->assertAddress(trim($options['pay_to']), 'the route\'s payTo')
            : $this->config->payTo();

        $payment = new RoutePayment(
            price: $this->config->resolvePrice($this->priceOption($options), $context),
            networks: $this->networks($options),
            payTo: $payTo,
            scheme: $this->config->scheme(),
            maxTimeoutSeconds: $this->maxTimeoutSeconds($options),
            settlement: isset($options['settlement'])
                ? SettlementMode::parse($options['settlement'])
                : $this->config->settlementMode(),
            freeForAuth: (bool) ($options['free_for_auth'] ?? $this->config->get('bypass.auth.always', false)),
            description: $this->stringOption($options, 'description') ?? $this->config->defaultDescription(),
            mimeType: $this->stringOption($options, 'mime_type') ?? $this->config->defaultMimeType(),
            serviceName: $this->config->serviceName(),
            iconUrl: $this->config->iconUrl(),
            tags: $this->tags($options),
            routeName: $route?->getName(),
            routeUri: $uri === null ? null : '/'.ltrim($uri, '/'),
            method: $method,
        );

        if ($request === null || $priceResolver === null) {
            return $payment;
        }

        $dynamic = $priceResolver->resolve($request, $payment);

        return $dynamic === null || $dynamic === ''
            ? $payment
            : $payment->withPrice($this->config->resolvePrice($dynamic, $context));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function priceOption(array $options): string|int|float|null
    {
        $price = $options['price'] ?? null;

        return is_string($price) || is_int($price) || is_float($price) ? $price : null;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function networks(array $options): array
    {
        $networks = [];

        if (isset($options['network']) && is_string($options['network'])) {
            $networks[] = Network::normalize($options['network']);
        }

        foreach ((array) ($options['networks'] ?? []) as $network) {
            if (is_string($network) && $network !== '') {
                $normalized = Network::normalize($network);

                if (! in_array($normalized, $networks, true)) {
                    $networks[] = $normalized;
                }
            }
        }

        if ($networks === []) {
            return $this->config->networks();
        }

        return $networks;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function maxTimeoutSeconds(array $options): int
    {
        $max = $this->config->maxTimeoutSeconds();
        $requested = $options['max_timeout_seconds'] ?? null;

        if (! is_int($requested) && ! (is_string($requested) && ctype_digit($requested))) {
            return $max;
        }

        $requested = (int) $requested;

        return $requested > 0 ? min($requested, $max) : $max;
    }

    /**
     * @param array<string, mixed> $options
     * @return list<string>
     */
    private function tags(array $options): array
    {
        $tags = $options['tags'] ?? null;

        if (! is_array($tags) || $tags === []) {
            return $this->config->tags();
        }

        return array_values(array_filter($tags, 'is_string'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function primaryMethod(?Route $route): ?string
    {
        if ($route === null) {
            return null;
        }

        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        return $methods[0] ?? null;
    }
}
