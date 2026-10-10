<?php

declare(strict_types=1);

namespace HydraLabs\X402\Reporting\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a batch of payment events to the configured platform URL.
 *
 * Failures are logged and swallowed: reporting must never retry a payment or
 * bubble an error into the application.
 */
final class SendX402Report implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 15;

    /**
     * @param list<array<string, mixed>> $events
     */
    public function __construct(
        private readonly string $url,
        private readonly string $key,
        private readonly array $events,
        private readonly int $requestTimeout = 5,
    ) {}

    public function handle(): void
    {
        try {
            $response = Http::asJson()
                ->withToken($this->key)
                ->timeout(max(1, $this->requestTimeout))
                ->post($this->url, [
                    'source' => 'laravel-x402',
                    'events' => $this->events,
                ]);

            if ($response->failed()) {
                Log::channel($this->channel())->info('x402 reporting rejected a batch.', [
                    'status' => $response->status(),
                    'events' => count($this->events),
                ]);
            }
        } catch (Throwable $exception) {
            Log::channel($this->channel())->info('x402 reporting failed.', [
                'message' => $exception->getMessage(),
                'events' => count($this->events),
            ]);
        }
    }

    private function channel(): string
    {
        $channel = config('x402.logging.channel');

        return is_string($channel) && $channel !== '' ? $channel : (string) config('logging.default', 'stack');
    }
}
