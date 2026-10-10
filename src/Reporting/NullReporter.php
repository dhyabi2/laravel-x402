<?php

declare(strict_types=1);

namespace HydraLabs\X402\Reporting;

/**
 * The default: reports nothing, anywhere.
 */
final class NullReporter implements Reporter
{
    public function record(array $event): void {}

    public function flush(): void {}
}
