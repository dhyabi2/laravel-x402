<?php

declare(strict_types=1);

namespace HydraLabs\X402\Commands;

use HydraLabs\X402\Core\Messages\PaymentRequired;
use HydraLabs\X402\Core\Messages\PaymentRequirements;
use HydraLabs\X402\Core\Messages\SettleResponse;
use HydraLabs\X402\Core\Money\UsdConverter;
use HydraLabs\X402\Core\Networks\Network;
use HydraLabs\X402\Core\Protocol\Codec;
use HydraLabs\X402\Core\Protocol\CodecRegistry;
use HydraLabs\X402\Core\Support\Base64;
use HydraLabs\X402\Core\Support\Json;
use HydraLabs\X402\Core\Testing\PaymentPayloadFactory;
use HydraLabs\X402\X402Manager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Drives a full payment exchange against the running application with a fake
 * facilitator, and prints both halves with their headers decoded.
 *
 * No wallet, no network and no money: this proves the route is wired up
 * correctly, and shows exactly what a paying client will see.
 */
final class TestCommand extends Command
{
    protected $signature = 'x402:test
        {uri : The path to test, e.g. /api/report}
        {--method=GET : HTTP method}
        {--payer= : Address to pay from}
        {--header=* : Extra request headers, as "Name: value"}
        {--network= : Pay on this network when the route accepts several}
        {--underpay : Send less than the required amount, to check rejection}
        {--fail-settlement : Make settlement fail, to check the failure path}
        {--raw : Also print the raw base64 header values}';

    protected $description = 'Send a request through the 402 → pay → 200 exchange with a fake facilitator';

    public function handle(X402Manager $x402, Kernel $kernel, CodecRegistry $codecs, PaymentPayloadFactory $payloads): int
    {
        $fake = $x402->fake();

        if ($this->option('fail-settlement')) {
            $fake->rejectSettlement();
        }

        $uri = '/'.ltrim($this->stringArgument('uri'), '/');
        $method = strtoupper($this->stringOption('method') ?? 'GET');
        $headers = $this->extraHeaders();

        $this->newLine();
        $this->components->info(sprintf('%s %s', $method, $uri));
        $this->components->twoColumnDetail('<fg=gray>Facilitator</>', 'fake (nothing is charged)');

        $unpaid = $this->send($kernel, $method, $uri, $headers);

        if ($unpaid === null) {
            return self::FAILURE;
        }

        $this->printResponse('1. Unpaid request', $unpaid);

        if ($unpaid->getStatusCode() !== Response::HTTP_PAYMENT_REQUIRED) {
            $this->newLine();
            $this->components->warn(sprintf(
                'Expected 402, got %d. Is the x402 middleware on this route, and is X402_MODE=enforce?',
                $unpaid->getStatusCode()
            ));

            return self::FAILURE;
        }

        [$codec, $paymentRequired] = $this->decodeRequirements($codecs, $unpaid);

        if ($paymentRequired === null || $codec === null) {
            return self::FAILURE;
        }

        $requirements = $this->chooseRequirements($paymentRequired);

        if ($requirements === null) {
            return self::FAILURE;
        }

        $this->printRequirements($paymentRequired, $requirements);

        $payload = $payloads->exact(
            requirements: $requirements,
            resource: $paymentRequired->resource,
            payer: $this->payer(),
            amount: $this->option('underpay')
                ? $this->underpaidAmount($requirements)
                : null,
        );

        $encoded = Base64::encode(Json::encode($payload->toArray()));

        $this->newLine();
        $this->components->twoColumnDetail(
            sprintf('<fg=gray>%s</>', $codec->paymentSignatureHeader()),
            sprintf('%d bytes of base64 JSON', strlen($encoded))
        );
        $this->line(Json::encode($payload->toArray()));

        if ($this->option('raw')) {
            $this->newLine();
            $this->line('<fg=gray>'.$encoded.'</>');
        }

        $paid = $this->send($kernel, $method, $uri, array_merge($headers, [
            $codec->paymentSignatureHeader() => $encoded,
        ]));

        if ($paid === null) {
            return self::FAILURE;
        }

        $this->printResponse('2. Paid request', $paid);

        $settlement = $this->decodeSettlement($codec, $paid);

        if ($settlement !== null) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=gray>Settled</>', $settlement->success ? 'yes' : 'no');
            $this->components->twoColumnDetail('<fg=gray>Transaction</>', $settlement->transaction ?: '-');

            if (! $settlement->success) {
                $this->components->twoColumnDetail('<fg=gray>Reason</>', $settlement->reasonMessage());
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Verifications', (string) $fake->verifyCount());
        $this->components->twoColumnDetail('Settlements', (string) $fake->settleCount());

        $expectSuccess = ! $this->option('underpay') && ! $this->option('fail-settlement');

        if ($expectSuccess && $paid->getStatusCode() >= 400) {
            $this->components->error(sprintf('Payment was rejected with HTTP %d.', $paid->getStatusCode()));

            return self::FAILURE;
        }

        if (! $expectSuccess && $paid->getStatusCode() < 400) {
            $this->components->error('Expected the payment to be rejected, but it was accepted.');

            return self::FAILURE;
        }

        $this->components->info($expectSuccess
            ? 'The full exchange works.'
            : sprintf('Rejected as expected with HTTP %d.', $paid->getStatusCode()));

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(Kernel $kernel, string $method, string $uri, array $headers): ?Response
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        try {
            return $kernel->handle(Request::create($uri, $method, server: $server));
        } catch (Throwable $exception) {
            $this->components->error(sprintf('The request threw %s: %s', $exception::class, $exception->getMessage()));

            return null;
        }
    }

    /**
     * @return array{0: Codec|null, 1: PaymentRequired|null}
     */
    private function decodeRequirements(CodecRegistry $codecs, Response $response): array
    {
        foreach ($codecs->all() as $codec) {
            $header = $codec->paymentRequiredHeader();

            if ($header === null) {
                continue;
            }

            $value = $response->headers->get($header);

            if (! is_string($value) || $value === '') {
                continue;
            }

            try {
                return [$codec, PaymentRequired::fromArray(Json::decodeObject(Base64::decode($value)))];
            } catch (Throwable $exception) {
                $this->components->error(sprintf('Could not decode the %s header: %s', $header, $exception->getMessage()));

                return [null, null];
            }
        }

        $this->components->error('The 402 response carried no PAYMENT-REQUIRED header.');

        return [null, null];
    }

    private function decodeSettlement(Codec $codec, Response $response): ?SettleResponse
    {
        $value = $response->headers->get($codec->paymentResponseHeader());

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return SettleResponse::fromArray(Json::decodeObject(Base64::decode($value)));
        } catch (Throwable) {
            return null;
        }
    }

    private function chooseRequirements(PaymentRequired $paymentRequired): ?PaymentRequirements
    {
        $network = $this->stringOption('network');

        if ($network === null) {
            $first = $paymentRequired->first();

            if ($first === null) {
                $this->components->error('The 402 response advertised no payment methods.');
            }

            return $first;
        }

        $normalized = Network::normalize($network);

        foreach ($paymentRequired->accepts as $requirements) {
            if ($requirements->network === $normalized) {
                return $requirements;
            }
        }

        $this->components->error(sprintf(
            'This route does not accept payments on %s. It accepts: %s',
            Network::label($normalized),
            implode(', ', array_map(
                static fn (PaymentRequirements $requirements): string => $requirements->network,
                $paymentRequired->accepts
            ))
        ));

        return null;
    }

    private function printRequirements(PaymentRequired $paymentRequired, PaymentRequirements $requirements): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Resource</>', $paymentRequired->resource->url);
        $this->components->twoColumnDetail('<fg=gray>Scheme</>', $requirements->scheme);
        $this->components->twoColumnDetail('<fg=gray>Network</>', Network::label($requirements->network));
        $this->components->twoColumnDetail('<fg=gray>Amount</>', sprintf(
            '%s atomic units (%s)',
            $requirements->amount,
            UsdConverter::format($requirements->amount, 6)
        ));
        $this->components->twoColumnDetail('<fg=gray>Asset</>', $requirements->asset);
        $this->components->twoColumnDetail('<fg=gray>Pay to</>', $requirements->payTo);
        $this->components->twoColumnDetail('<fg=gray>Timeout</>', $requirements->maxTimeoutSeconds.'s');
    }

    private function printResponse(string $title, Response $response): void
    {
        $this->newLine();
        $this->components->twoColumnDetail(
            sprintf('<options=bold>%s</>', $title),
            sprintf('HTTP %d', $response->getStatusCode())
        );

        foreach (['PAYMENT-REQUIRED', 'PAYMENT-RESPONSE', 'X-PAYMENT-RESPONSE', 'X-X402-Would-Charge', 'X-X402-Bypass', 'X-X402-Settlement'] as $header) {
            $value = $response->headers->get($header);

            if (is_string($value) && $value !== '') {
                $this->components->twoColumnDetail(
                    sprintf('<fg=gray>%s</>', $header),
                    $this->summariseHeader($value)
                );
            }
        }

        $body = (string) $response->getContent();

        if ($body !== '' && $body !== '{}') {
            $this->line($this->truncate($body));
        }
    }

    private function summariseHeader(string $value): string
    {
        try {
            return Json::encode(Json::decodeObject(Base64::decode($value)));
        } catch (Throwable) {
            return $value;
        }
    }

    private function underpaidAmount(PaymentRequirements $requirements): string
    {
        if ($requirements->amount === '0') {
            return '0';
        }

        $trimmed = substr($requirements->amount, 0, -1);

        return $trimmed === '' ? '0' : $trimmed;
    }

    private function payer(): string
    {
        return $this->stringOption('payer') ?? PaymentPayloadFactory::DEFAULT_PAYER;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function extraHeaders(): array
    {
        $headers = ['Accept' => 'application/json'];

        /** @var list<string> $raw */
        $raw = (array) $this->option('header');

        foreach ($raw as $header) {
            if (! str_contains($header, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $headers[trim($name)] = trim($value);
        }

        return $headers;
    }

    private function truncate(string $body, int $length = 600): string
    {
        return strlen($body) <= $length ? $body : substr($body, 0, $length).'…';
    }
}
