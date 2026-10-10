<?php

declare(strict_types=1);

namespace HydraLabs\X402\Discovery;

use HydraLabs\X402\Bypass\BypassEvaluator;
use HydraLabs\X402\Config\X402Config;
use HydraLabs\X402\Core\Exceptions\ConfigurationException;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Protocol\Version;
use HydraLabs\X402\Routing\RequirementsFactory;
use HydraLabs\X402\Routing\RouteResolver;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Enumerates the application's paid routes.
 *
 * Backs both the discovery endpoint and `php artisan x402:routes`. A route
 * whose configuration is broken is reported with its error rather than taking
 * the whole catalogue down with it.
 */
final class RouteCatalog
{
    public function __construct(
        private readonly Router $router,
        private readonly RouteResolver $resolver,
        private readonly RequirementsFactory $requirements,
        private readonly X402Config $config,
        private readonly UrlGenerator $url,
        private readonly BypassEvaluator $bypass,
    ) {}

    /**
     * @return list<CatalogEntry>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $this->resolver->isPaid($route)) {
                continue;
            }

            $entries[] = $this->entry($route);
        }

        usort(
            $entries,
            static fn (CatalogEntry $a, CatalogEntry $b): int => [$a->uri, $a->method] <=> [$b->uri, $b->method]
        );

        return $entries;
    }

    /**
     * The spec's discovery payload shape (core spec v2 §8.3), so any client
     * that can read a Bazaar listing can read this endpoint.
     *
     * @return array<string, mixed>
     */
    public function toDiscoveryDocument(): array
    {
        $items = [];

        // Nothing is for sale while the package is off, so advertising prices
        // would be a lie to the one audience this endpoint exists for.
        foreach ($this->config->isEnabled() ? $this->entries() : [] as $entry) {
            if ($entry->payment === null || $entry->accepts === []) {
                continue;
            }

            $items[] = array_filter([
                'resource' => $entry->resourceUrl($this->url),
                'type' => 'http',
                'x402Version' => Version::CURRENT,
                'method' => $entry->method,
                'accepts' => array_map(
                    static fn (PaymentRequirements $requirements): array => $requirements->toArray(),
                    $entry->accepts
                ),
                'description' => $entry->payment->description,
                'mimeType' => $entry->payment->mimeType,
                'lastUpdated' => gmdate('c'),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'x402Version' => Version::CURRENT,
            'items' => $items,
            'pagination' => [
                'limit' => count($items),
                'offset' => 0,
                'total' => count($items),
            ],
            'service' => array_filter([
                'name' => $this->config->serviceName(),
                'iconUrl' => $this->config->iconUrl(),
                'tags' => $this->config->tags() === [] ? null : $this->config->tags(),
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    private function entry(Route $route): CatalogEntry
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        try {
            $payment = $this->resolver->resolveForRoute($route);
            $accepts = $payment === null ? [] : $this->requirements->accepts($payment);
            $error = null;
        } catch (ConfigurationException $exception) {
            $payment = null;
            $accepts = [];
            $error = $exception->getMessage();
        }

        return new CatalogEntry(
            uri: '/'.ltrim($route->uri(), '/'),
            method: $methods[0] ?? 'GET',
            name: $route->getName(),
            action: $route->getActionName(),
            payment: $payment,
            accepts: $accepts,
            bypass: $payment === null ? [] : $this->bypass->describe($payment),
            error: $error,
        );
    }
}
