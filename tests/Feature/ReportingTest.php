<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Requestway\X402\Facades\X402;
use Requestway\X402\Reporting\HttpReporter;
use Requestway\X402\Reporting\Jobs\SendX402Report;
use Requestway\X402\Reporting\NullReporter;
use Requestway\X402\Reporting\Reporter;

beforeEach(function (): void {
    X402::fake();

    Route::get('/premium', fn (): array => ['data' => 'secret'])->middleware('x402:0.01');
});

it('reports nothing by default', function (): void {
    expect(app(Reporter::class))->toBeInstanceOf(NullReporter::class);

    Queue::fake();

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements))->assertOk();

    Queue::assertNothingPushed();
});

it('stays off when a platform URL is set but no key is', function (): void {
    config()->set('x402.reporting.enabled', true);
    config()->set('x402.reporting.key', null);

    expect(app(Reporter::class))->toBeInstanceOf(NullReporter::class);
});

it('queues a batch of events once the platform key is set', function (): void {
    config()->set('x402.reporting.enabled', true);
    config()->set('x402.reporting.key', 'platform-key');

    Bus::fake();

    expect(app(Reporter::class))->toBeInstanceOf(HttpReporter::class);

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements))->assertOk();

    Bus::assertDispatched(SendX402Report::class);
});

it('puts the job on the configured queue and connection', function (): void {
    config()->set('x402.reporting.enabled', true);
    config()->set('x402.reporting.key', 'platform-key');
    config()->set('x402.reporting.queue', 'reporting');
    config()->set('x402.reporting.connection', 'sync');

    Bus::fake();

    $requirements = paymentRequired($this->getJson('/premium'))->first();
    $this->getJson('/premium', payment($requirements));

    Bus::assertDispatched(SendX402Report::class, function (SendX402Report $job): bool {
        return $job->queue === 'reporting' && $job->connection === 'sync';
    });
});

it('posts the batch to the platform with the key as a bearer token', function (): void {
    Http::fake(['https://platform.test/events' => Http::response(['ok' => true])]);

    (new SendX402Report('https://platform.test/events', 'platform-key', [
        ['type' => 'payment.settled', 'payment' => ['route' => 'premium']],
    ]))->handle();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://platform.test/events'
            && $request->hasHeader('Authorization', 'Bearer platform-key')
            && $request->data()['source'] === 'laravel-x402'
            && $request->data()['events'][0]['type'] === 'payment.settled';
    });
});

it('swallows a rejected batch rather than failing the job', function (): void {
    Http::fake(['https://platform.test/events' => Http::response(['error' => 'nope'], 500)]);

    (new SendX402Report('https://platform.test/events', 'k', [['type' => 'x']]))->handle();

    Http::assertSentCount(1);
});

it('swallows a network failure rather than failing the job', function (): void {
    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('down'));

    (new SendX402Report('https://platform.test/events', 'k', [['type' => 'x']]))->handle();
})->throwsNoExceptions();

it('never lets a reporting failure break a payment', function (): void {
    config()->set('x402.reporting.enabled', true);
    config()->set('x402.reporting.key', 'platform-key');

    Http::fake(fn (): never => throw new Illuminate\Http\Client\ConnectionException('down'));

    $requirements = paymentRequired($this->getJson('/premium'))->first();

    $this->getJson('/premium', payment($requirements))->assertOk();

    X402::assertSettled(1);
});

it('buffers events and sends them in one batch', function (): void {
    $bus = Mockery::mock(Illuminate\Contracts\Bus\Dispatcher::class);
    $bus->shouldReceive('dispatch')->once();

    $reporter = new HttpReporter(
        bus: $bus,
        exceptions: app(Illuminate\Contracts\Debug\ExceptionHandler::class),
        url: 'https://platform.test/events',
        key: 'k',
    );

    $reporter->record(['type' => 'a']);
    $reporter->record(['type' => 'b']);
    $reporter->flush();

    // A second flush with an empty buffer must not dispatch anything.
    $reporter->flush();
});

it('caps the buffer so a pathological request cannot grow without bound', function (): void {
    $bus = Mockery::mock(Illuminate\Contracts\Bus\Dispatcher::class);

    $bus->shouldReceive('dispatch')->once()->with(Mockery::on(
        fn (SendX402Report $job): bool => true
    ));

    $reporter = new HttpReporter(
        bus: $bus,
        exceptions: app(Illuminate\Contracts\Debug\ExceptionHandler::class),
        url: 'https://platform.test/events',
        key: 'k',
        maxBuffer: 2,
    );

    foreach (range(1, 10) as $i) {
        $reporter->record(['type' => (string) $i]);
    }

    $reporter->flush();
});

it('reports a dispatch failure to the exception handler instead of throwing', function (): void {
    $bus = Mockery::mock(Illuminate\Contracts\Bus\Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));

    $exceptions = Mockery::mock(Illuminate\Contracts\Debug\ExceptionHandler::class);
    $exceptions->shouldReceive('report')->once();

    $reporter = new HttpReporter($bus, $exceptions, 'https://platform.test/events', 'k');
    $reporter->record(['type' => 'a']);
    $reporter->flush();

    expect(true)->toBeTrue();
});

it('does nothing when the null reporter is flushed', function (): void {
    $reporter = new NullReporter;
    $reporter->record(['type' => 'a']);
    $reporter->flush();
})->throwsNoExceptions();
