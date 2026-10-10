<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * src/Core is the future requestway/x402-php: a framework-agnostic protocol
 * implementation for WordPress and plain PHP. A stray Illuminate import would
 * quietly make that split impossible, so it is checked rather than hoped for.
 */
it('keeps the protocol core free of framework dependencies', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src/Core')->name('*.php') as $file) {
        $contents = (string) $file->getContents();

        if (preg_match_all('/^use\s+(Illuminate|Symfony|Laravel)\\\\[^;]+;/m', $contents, $matches) === 0) {
            continue;
        }

        $offenders[$file->getRelativePathname()] = $matches[0];
    }

    expect($offenders)->toBe([], sprintf(
        "src/Core must not depend on a framework. Found:\n%s",
        json_encode($offenders, JSON_PRETTY_PRINT)
    ));
});

it('keeps the protocol core free of Laravel helper functions', function (): void {
    $helpers = ['config(', 'app(', 'cache(', 'now(', 'logger(', 'request(', 'response(', 'resolve(', 'event('];
    $offenders = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src/Core')->name('*.php') as $file) {
        $contents = (string) $file->getContents();

        foreach ($helpers as $helper) {
            // A method declaration or a call on an object of our own is fine;
            // only a bare global helper call is not.
            if (preg_match('/(?<![\w$>:])(?<!function )'.preg_quote($helper, '/').'/', $contents) === 1) {
                $offenders[$file->getRelativePathname()][] = rtrim($helper, '(');
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('declares strict types everywhere', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php') as $file) {
        if (! str_contains((string) $file->getContents(), 'declare(strict_types=1);')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});

it('never logs a raw signature or payment payload', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php') as $file) {
        $contents = (string) $file->getContents();

        // A log call must not be handed a signature, a decoded payload or a
        // raw payment header.
        if (preg_match('/(logger|Log::|->(info|debug|warning|error|critical))\s*\(.*(signature|->payload|rawHeader)/i', $contents) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
