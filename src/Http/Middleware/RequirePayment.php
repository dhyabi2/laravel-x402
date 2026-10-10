<?php

declare(strict_types=1);

namespace HydraLabs\X402\Http\Middleware;

use Closure;
use HydraLabs\X402\Core\Errors\ErrorCode;
use HydraLabs\X402\Core\Exceptions\MalformedPaymentException;
use HydraLabs\X402\Core\Facilitator\Exceptions\FacilitatorException;
use HydraLabs\X402\Core\Messages\PaymentPayload;
use HydraLabs\X402\Core\Messages\PaymentRequired;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\SettleResponse;
use HydraLabs\X402\Core\Protocol\Codec;
use HydraLabs\X402\Core\Validation\ValidationFailure;
use HydraLabs\X402\Enums\Mode;
use HydraLabs\X402\Enums\SettlementMode;
use HydraLabs\X402\Events\PaymentContext;
use HydraLabs\X402\Http\DeferredSettlement;
use HydraLabs\X402\Http\PaymentResponseFactory;
use HydraLabs\X402\Routing\RouteOptionParser;
use HydraLabs\X402\Routing\RoutePayment;
use HydraLabs\X402\X402Manager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charges for a route with the x402 payment protocol.
 *
 *     Route::get('/report', ...)->middleware('x402:0.01');
 *     Route::get('/report', ...)->middleware('x402:0.25,free-for-auth');
 *     Route::get('/report', ...)->middleware('x402:premium');
 *
 * The flow follows the spec's `authorization` payment flow: verify before the
 * route runs, settle after it succeeds. See docs/SPEC_NOTES.md §7.
 */
class RequirePayment
{
    public const WOULD_CHARGE_HEADER = 'X-X402-Would-Charge';

    public const BYPASS_HEADER = 'X-X402-Bypass';

    public const DEFERRED_HEADER = 'X-X402-Settlement';

    public const REQUEST_ATTRIBUTE = 'x402.deferred';

    public const HANDLED_ATTRIBUTE = 'x402.handled';

    public function __construct(
        protected readonly X402Manager $x402,
        protected readonly PaymentResponseFactory $responses,
    ) {}

    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        $config = $this->x402->config();

        if (! $config->isEnabled()) {
            return $next($request);
        }

        $options = $this->options($request, array_values($parameters));

        if (! $this->shouldProtect($request, $options)) {
            return $next($request);
        }

        // A route can end up with the middleware listed twice — the paid()
        // macro adds a bare `x402` alongside an explicit `x402:0.09`, or a
        // group repeats it. Charging once is the only sane reading.
        if ($request->attributes->get(self::HANDLED_ATTRIBUTE) === true) {
            return $next($request);
        }

        $request->attributes->set(self::HANDLED_ATTRIBUTE, true);

        $route = $this->x402->resolveRoute($request, $options);
        $resourceUrl = $this->resourceUrl($request);
        $bypass = $this->x402->bypass()->reason($request, $route);

        if ($bypass !== null) {
            return $this->serveFree($request, $next, $bypass);
        }

        $paymentRequired = $this->x402->requirements()->make($route, $resourceUrl);
        $mode = $config->mode();

        if ($mode === Mode::Observe) {
            return $this->observe($request, $next, $route, $paymentRequired, $resourceUrl);
        }

        $detected = $this->x402->codecs()->detect(
            static fn (string $header): ?string => $request->header($header)
        );

        if ($detected === null) {
            return $this->requirePayment($route, $paymentRequired, $resourceUrl, $request);
        }

        [$codec, $rawHeader] = $detected;

        try {
            $payload = $codec->decodePaymentPayload($rawHeader);
        } catch (MalformedPaymentException $exception) {
            return $this->reject(
                $codec,
                $paymentRequired,
                ValidationFailure::malformed($exception->getMessage()),
                $this->context($route, $resourceUrl, $request, $paymentRequired->first()),
            );
        }

        return $this->processPayment($request, $next, $codec, $payload, $route, $paymentRequired, $resourceUrl);
    }

    /**
     * Settle a payment deferred to after_response mode, then flush reporting.
     *
     * Deferred state travels on the request rather than on this instance,
     * because Laravel resolves a fresh middleware instance to terminate with.
     */
    public function terminate(Request $request, Response $response): void
    {
        $deferred = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($deferred instanceof DeferredSettlement) {
            $request->attributes->remove(self::REQUEST_ATTRIBUTE);

            $this->settleDeferred($deferred);
        }

        $this->x402->reporter()->flush();
    }

    /**
     * Every x402 option declared on the route, with this invocation's own
     * parameters taking precedence.
     *
     * @param list<string> $parameters
     * @return array<string, mixed>
     */
    private function options(Request $request, array $parameters): array
    {
        $own = RouteOptionParser::parse(array_values($parameters));
        $route = $request->route();

        if (! $route instanceof \Illuminate\Routing\Route) {
            return $own;
        }

        return array_merge($this->x402->routes()->middlewareOptionsFor($route) ?? [], $own);
    }

    /**
     * Whether this middleware should charge for the current request.
     *
     * Always true here: the `x402` alias is an explicit instruction. The
     * attribute-driven variant overrides this.
     *
     * @param array<string, mixed> $options
     */
    protected function shouldProtect(Request $request, array $options): bool
    {
        return true;
    }

    /**
     * Verify, run the route, then settle.
     *
     * @param Closure(Request): Response $next
     */
    private function processPayment(
        Request $request,
        Closure $next,
        Codec $codec,
        PaymentPayload $payload,
        RoutePayment $route,
        PaymentRequired $paymentRequired,
        string $resourceUrl,
    ): Response {
        [$requirements, $failure] = $this->x402->validator()->match($payload, $paymentRequired->accepts);

        if ($requirements === null || $failure !== null) {
            return $this->reject(
                $codec,
                $paymentRequired,
                $failure ?? ValidationFailure::code(ErrorCode::InvalidPayload),
                $this->context($route, $resourceUrl, $request, $paymentRequired->first(), $payload),
            );
        }

        // A v1 payload carries no `accepted` echo; adopt the requirement it
        // matched so the rest of the flow has one shape to work with.
        $payload = $payload->accepted === null ? $payload->withAccepted($requirements) : $payload;
        $context = $this->context($route, $resourceUrl, $request, $requirements, $payload);

        $failure = $this->x402->validator()->validate($payload, $requirements, $this->acceptableResourceUrls($request));

        if ($failure !== null) {
            return $this->reject($codec, $paymentRequired, $failure, $context);
        }

        $guard = $this->x402->replayGuard();

        if (! $guard->claim($payload)) {
            return $this->reject(
                $codec,
                $paymentRequired,
                ValidationFailure::code(
                    ErrorCode::InvalidPayload,
                    'This payment has already been used. Sign a new one.'
                ),
                $context,
            );
        }

        $resource = $this->x402->requirements()->resource($route, $resourceUrl);

        try {
            $verification = $this->x402->facilitator()->verify($payload, $requirements, $resource);
        } catch (FacilitatorException $exception) {
            $guard->release($payload);

            return $this->facilitatorFailure($request, $next, $codec, $paymentRequired, $context, 'verify', $exception);
        }

        if (! $verification->isValid) {
            $guard->release($payload);

            return $this->reject(
                $codec,
                $paymentRequired,
                ValidationFailure::code(
                    ErrorCode::tryFromReason($verification->invalidReason) ?? ErrorCode::InvalidPayload,
                    $verification->reasonMessage(),
                ),
                $context,
                $verification->invalidReason,
            );
        }

        $deferred = $route->settlement === SettlementMode::AfterResponse;

        $recordId = $this->x402->recorder()->verified($context, $verification, persist: $deferred);

        $response = $next($request);

        if (! $this->routeSucceeded($response)) {
            // The route itself failed, so nothing is charged: the client can
            // present the same payment again once the route works.
            $guard->release($payload);

            return $response;
        }

        $settlement = new DeferredSettlement($codec, $payload, $requirements, $resource, $context, $recordId);

        if ($deferred) {
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $settlement);
            $response->headers->set(self::DEFERRED_HEADER, 'deferred');
            $this->responses->markPrivate($response);

            return $response;
        }

        return $this->settleNow($response, $codec, $paymentRequired, $settlement);
    }

    private function settleNow(
        Response $response,
        Codec $codec,
        PaymentRequired $paymentRequired,
        DeferredSettlement $settlement,
    ): Response {
        $guard = $this->x402->replayGuard();

        try {
            $result = $this->settle($settlement);
        } catch (FacilitatorException $exception) {
            $guard->release($settlement->payload);

            $this->x402->recorder()->failed(
                $settlement->context,
                'settle',
                'facilitator_unavailable',
                $exception->getMessage(),
                exception: $exception,
                recordId: $settlement->recordId,
            );

            return $this->responses->paymentRequired(
                $codec,
                $paymentRequired->withError('Payment could not be settled: the facilitator is unavailable.'),
                Response::HTTP_BAD_GATEWAY,
            );
        }

        if ($result->success || $result->isPending()) {
            $this->x402->recorder()->settled($settlement->context, $result, $settlement->recordId);

            return $this->responses->attachSettlement($response, $codec, $result);
        }

        $guard->release($settlement->payload);

        $this->x402->recorder()->failed(
            $settlement->context,
            'settle',
            $result->errorReason ?? ErrorCode::UnexpectedSettleError->value,
            $result->reasonMessage(),
            settlement: $result,
            recordId: $settlement->recordId,
        );

        // The resource is not served: the response body built by the route is
        // discarded in favour of a 402 carrying the settlement failure.
        return $this->responses->paymentRequired(
            $codec,
            $paymentRequired->withError($result->reasonMessage()),
            Response::HTTP_PAYMENT_REQUIRED,
            $result,
        );
    }

    private function settleDeferred(DeferredSettlement $settlement): void
    {
        $recorder = $this->x402->recorder();

        try {
            $result = $this->settle($settlement);
        } catch (FacilitatorException $exception) {
            $this->x402->replayGuard()->release($settlement->payload);

            $recorder->failed(
                $settlement->context,
                'settle_after_response',
                'facilitator_unavailable',
                $exception->getMessage(),
                exception: $exception,
                recordId: $settlement->recordId,
            );

            return;
        }

        if ($result->success || $result->isPending()) {
            $recorder->settled($settlement->context, $result, $settlement->recordId);

            return;
        }

        $this->x402->replayGuard()->release($settlement->payload);

        $recorder->failed(
            $settlement->context,
            'settle_after_response',
            $result->errorReason ?? ErrorCode::UnexpectedSettleError->value,
            $result->reasonMessage(),
            settlement: $result,
            recordId: $settlement->recordId,
        );
    }

    /**
     * Settle, retrying exactly once on the spec's non-terminal
     * `settlement_pending` reason, as the reference server does. The retry
     * reconciles against the already-broadcast transaction rather than
     * broadcasting a second one.
     */
    private function settle(DeferredSettlement $settlement): SettleResponse
    {
        $facilitator = $this->x402->facilitator();

        $result = $facilitator->settle($settlement->payload, $settlement->requirements, $settlement->resource);

        if (! $result->isPending()) {
            return $result;
        }

        return $facilitator->settle($settlement->payload, $settlement->requirements, $settlement->resource);
    }

    /**
     * @param Closure(Request): Response $next
     */
    private function facilitatorFailure(
        Request $request,
        Closure $next,
        Codec $codec,
        PaymentRequired $paymentRequired,
        PaymentContext $context,
        string $stage,
        FacilitatorException $exception,
    ): Response {
        $this->x402->recorder()->failed(
            $context,
            $stage,
            'facilitator_unavailable',
            $exception->getMessage(),
            exception: $exception,
        );

        if ($this->x402->config()->failsOpenOnFacilitatorError()) {
            $response = $next($request);
            $response->headers->set(self::BYPASS_HEADER, 'facilitator-unavailable');

            return $response;
        }

        return $this->responses->paymentRequired(
            $codec,
            $paymentRequired->withError('Payment could not be verified: the facilitator is unavailable.'),
            Response::HTTP_BAD_GATEWAY,
        );
    }

    private function requirePayment(
        RoutePayment $route,
        PaymentRequired $paymentRequired,
        string $resourceUrl,
        Request $request,
    ): Response {
        $codec = $this->x402->codecs()->current();
        $message = sprintf(
            'Payment of %s is required to access this resource. Send a signed x402 payment in the %s header.',
            $route->formattedPrice(),
            $codec->paymentSignatureHeader(),
        );

        $this->x402->recorder()->required(
            $this->context($route, $resourceUrl, $request, $paymentRequired->first())
        );

        return $this->responses->paymentRequired($codec, $paymentRequired->withError($message));
    }

    private function reject(
        Codec $codec,
        PaymentRequired $paymentRequired,
        ValidationFailure $failure,
        PaymentContext $context,
        ?string $reason = null,
    ): Response {
        $this->x402->recorder()->failed(
            $context,
            'verify',
            $reason ?? $failure->reason(),
            $failure->message,
        );

        return $this->responses->paymentRequired(
            $codec,
            $paymentRequired->withError($failure->message),
            $failure->status,
        );
    }

    /**
     * @param Closure(Request): Response $next
     */
    private function observe(
        Request $request,
        Closure $next,
        RoutePayment $route,
        PaymentRequired $paymentRequired,
        string $resourceUrl,
    ): Response {
        $this->x402->recorder()->observed(
            $this->context($route, $resourceUrl, $request, $paymentRequired->first())
        );

        $response = $next($request);
        $response->headers->set(self::WOULD_CHARGE_HEADER, $route->formattedPrice());

        return $response;
    }

    /**
     * @param Closure(Request): Response $next
     */
    private function serveFree(Request $request, Closure $next, string $reason): Response
    {
        $response = $next($request);
        $response->headers->set(self::BYPASS_HEADER, $reason);

        return $response;
    }

    private function context(
        RoutePayment $route,
        string $resourceUrl,
        Request $request,
        ?PaymentRequirements $requirements,
        ?PaymentPayload $payload = null,
    ): PaymentContext {
        $network = $requirements?->network;

        return new PaymentContext(
            route: $route,
            resourceUrl: $resourceUrl,
            method: $request->getMethod(),
            mode: $this->x402->mode(),
            requirements: $requirements,
            payload: $payload,
            asset: $network === null || $requirements === null
                ? null
                : $this->x402->config()->assets()->find($network, $requirements->asset),
            requestId: $this->requestId($request),
        );
    }

    /**
     * Only a successful route result is charged for: an error page is not the
     * resource the client paid for.
     */
    private function routeSucceeded(Response $response): bool
    {
        return $response->getStatusCode() < 400;
    }

    private function resourceUrl(Request $request): string
    {
        return $request->fullUrl();
    }

    /**
     * Forms of the current URL a client may legitimately have signed for.
     *
     * @return list<string>
     */
    private function acceptableResourceUrls(Request $request): array
    {
        return array_values(array_unique([
            $request->fullUrl(),
            $request->url(),
        ]));
    }

    private function requestId(Request $request): ?string
    {
        foreach (['X-Request-Id', 'X-Correlation-Id', 'X-Amzn-Trace-Id'] as $header) {
            $value = $request->header($header);

            if (is_string($value) && $value !== '') {
                return substr($value, 0, 80);
            }
        }

        return null;
    }
}
