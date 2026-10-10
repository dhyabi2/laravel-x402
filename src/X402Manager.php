<?php

declare(strict_types=1);

namespace Requestway\X402;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Requestway\X402\Config\X402Config;
use Requestway\X402\Core\Facilitator\Facilitator;
use Requestway\X402\Core\Protocol\CodecRegistry;
use Requestway\X402\Core\Replay\ReplayGuard;
use Requestway\X402\Core\Validation\PaymentValidator;
use Requestway\X402\Enums\Mode;
use Requestway\X402\Facilitators\FakeFacilitator;
use Requestway\X402\Pricing\CallbackPriceResolver;
use Requestway\X402\Pricing\PriceResolver;
use Requestway\X402\Recording\PaymentRecorder;
use Requestway\X402\Reporting\Reporter;
use Requestway\X402\Routing\RequirementsFactory;
use Requestway\X402\Routing\RoutePayment;
use Requestway\X402\Routing\RouteResolver;

/**
 * The package's public API, resolved as the `x402` container binding and
 * fronted by the X402 facade.
 *
 * @mixin FakeFacilitator
 */
class X402Manager
{
    private ?PriceResolver $priceResolver = null;

    private ?Facilitator $facilitatorOverride = null;

    public function __construct(
        private readonly Container $container,
        private readonly X402Config $config,
        private readonly CodecRegistry $codecs,
        private readonly RouteResolver $routes,
        private readonly RequirementsFactory $requirements,
        private readonly PaymentValidator $validator,
        private readonly Bypass\BypassEvaluator $bypass,
    ) {}

    public function config(): X402Config
    {
        return $this->config;
    }

    public function codecs(): CodecRegistry
    {
        return $this->codecs;
    }

    public function routes(): RouteResolver
    {
        return $this->routes;
    }

    public function requirements(): RequirementsFactory
    {
        return $this->requirements;
    }

    public function validator(): PaymentValidator
    {
        return $this->validator;
    }

    public function bypass(): Bypass\BypassEvaluator
    {
        return $this->bypass;
    }

    public function recorder(): PaymentRecorder
    {
        return $this->container->make(PaymentRecorder::class);
    }

    public function replayGuard(): ReplayGuard
    {
        return $this->container->make(ReplayGuard::class);
    }

    public function reporter(): Reporter
    {
        return $this->container->make(Reporter::class);
    }

    public function mode(): Mode
    {
        return $this->config->mode();
    }

    public function facilitator(): Facilitator
    {
        return $this->facilitatorOverride ?? $this->container->make(Facilitator::class);
    }

    /**
     * Swap in a facilitator for the rest of the process. Mainly useful for
     * tests and for x402:test.
     */
    public function useFacilitator(Facilitator $facilitator): Facilitator
    {
        $this->container->instance(Facilitator::class, $facilitator);

        return $this->facilitatorOverride = $facilitator;
    }

    /**
     * Replace the facilitator with an in-memory fake that accepts payments and
     * records them, in the style of Http::fake().
     */
    public function fake(): FakeFacilitator
    {
        $fake = $this->facilitatorOverride instanceof FakeFacilitator
            ? $this->facilitatorOverride->reset()
            : new FakeFacilitator;

        $this->useFacilitator($fake);

        return $fake;
    }

    public function isFaked(): bool
    {
        return $this->facilitatorOverride instanceof FakeFacilitator;
    }

    /**
     * Price routes dynamically.
     *
     *     X402::priceUsing(fn (Request $request) => $request->user()?->isPro() ? '0.001' : '0.01');
     *
     * Returning null from the callback falls back to the route's static price.
     *
     * @param Closure(Request, RoutePayment): (string|int|float|null)|PriceResolver|class-string<PriceResolver> $resolver
     */
    public function priceUsing(Closure|PriceResolver|string $resolver): self
    {
        $this->priceResolver = match (true) {
            $resolver instanceof Closure => new CallbackPriceResolver($resolver),
            $resolver instanceof PriceResolver => $resolver,
            default => $this->container->make($resolver),
        };

        return $this;
    }

    public function priceResolver(): ?PriceResolver
    {
        return $this->priceResolver;
    }

    /**
     * Serve matching requests without payment.
     *
     * @param Closure(Request, RoutePayment): bool $callback
     */
    public function bypassUsing(Closure $callback): self
    {
        $this->bypass->register($callback);

        return $this;
    }

    /**
     * Resolve a route's payment configuration for a request, applying any
     * dynamic price resolver.
     *
     * @param array<string, mixed> $options
     */
    public function resolveRoute(Request $request, array $options = []): RoutePayment
    {
        return $this->routes->resolve($request, $options, $this->priceResolver);
    }

    /**
     * Forget runtime customisation. Called between tests.
     */
    public function flush(): self
    {
        $this->priceResolver = null;
        $this->facilitatorOverride = null;
        $this->bypass->flush();

        return $this;
    }

    /**
     * Proxy assertions to the fake facilitator, so tests can call
     * X402::assertSettled() directly.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $facilitator = $this->facilitator();

        if (! $facilitator instanceof FakeFacilitator) {
            throw new \BadMethodCallException(sprintf(
                'X402::%s() is only available after calling X402::fake().',
                $method
            ));
        }

        /** @var callable $callable */
        $callable = [$facilitator, $method];

        return $callable(...$arguments);
    }
}
