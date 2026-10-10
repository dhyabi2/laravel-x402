<?php

declare(strict_types=1);

namespace Requestway\X402\Reporting;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Requestway\X402\Reporting\Jobs\SendX402Report;
use Throwable;

/**
 * Buffers events for the request and queues one batched POST on flush.
 *
 * Enabled only when X402_PLATFORM_KEY is set. Nothing here can fail a request:
 * a dispatch failure is reported to the exception handler and dropped.
 */
final class HttpReporter implements Reporter
{
    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly ExceptionHandler $exceptions,
        private readonly string $url,
        private readonly string $key,
        private readonly ?string $queue = null,
        private readonly ?string $connection = null,
        private readonly int $timeout = 5,
        private readonly int $maxBuffer = 100,
    ) {}

    public function record(array $event): void
    {
        if (count($this->buffer) >= $this->maxBuffer) {
            return;
        }

        $this->buffer[] = $event;
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        try {
            $job = new SendX402Report($this->url, $this->key, $events, $this->timeout);

            if ($this->connection !== null) {
                $job->onConnection($this->connection);
            }

            if ($this->queue !== null) {
                $job->onQueue($this->queue);
            }

            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }
}
