<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Exceptions\ConfigurationException;
use HydraLabs\X402\Routing\RouteOptionParser;

it('reads a bare price as the first parameter', function (): void {
    expect(RouteOptionParser::parse(['0.01']))->toBe(['price' => '0.01'])
        ->and(RouteOptionParser::parse(['premium']))->toBe(['price' => 'premium'])
        ->and(RouteOptionParser::parse([]))->toBe([]);
});

it('reads flags', function (): void {
    expect(RouteOptionParser::parse(['0.01', 'free-for-auth']))
        ->toBe(['price' => '0.01', 'free_for_auth' => true])
        ->and(RouteOptionParser::parse(['0.01', 'FREE-FOR-AUTH']))
        ->toBe(['price' => '0.01', 'free_for_auth' => true])
        ->and(RouteOptionParser::parse(['0.01', 'paid-for-auth']))
        ->toBe(['price' => '0.01', 'free_for_auth' => false]);
});

it('reads a flag as the first parameter', function (): void {
    expect(RouteOptionParser::parse(['free-for-auth']))->toBe(['free_for_auth' => true]);
});

it('reads every supported key, including its aliases', function (array $parameters, array $expected): void {
    expect(RouteOptionParser::parse($parameters))->toBe($expected);
})->with([
    [['price=0.02'], ['price' => '0.02']],
    [['tier=premium'], ['price' => 'premium']],
    [['description=Market data'], ['description' => 'Market data']],
    [['mime=text/csv'], ['mime_type' => 'text/csv']],
    [['mime_type=text/csv'], ['mime_type' => 'text/csv']],
    [['mimetype=text/csv'], ['mime_type' => 'text/csv']],
    [['network=base'], ['network' => 'base']],
    [['networks=base|base-sepolia'], ['networks' => ['base', 'base-sepolia']]],
    [['pay_to=0xabc'], ['pay_to' => '0xabc']],
    [['payto=0xabc'], ['pay_to' => '0xabc']],
    [['settle=after_response'], ['settlement' => 'after_response']],
    [['settlement=after_response'], ['settlement' => 'after_response']],
    [['timeout=30'], ['max_timeout_seconds' => 30]],
    [['max_timeout_seconds=30'], ['max_timeout_seconds' => 30]],
    [['tags=a|b'], ['tags' => ['a', 'b']]],
]);

it('ignores empty parameters', function (): void {
    expect(RouteOptionParser::parse(['0.01', '', '  ']))->toBe(['price' => '0.01']);
});

it('keeps an equals sign inside a value', function (): void {
    expect(RouteOptionParser::parse(['description=a=b']))->toBe(['description' => 'a=b']);
});

it('tolerates spaces around keys and values', function (): void {
    expect(RouteOptionParser::parse([' description = Market data ']))
        ->toBe(['description' => 'Market data']);
});

it('drops blank entries from a list value', function (): void {
    expect(RouteOptionParser::parse(['networks=base||base-sepolia|']))
        ->toBe(['networks' => ['base', 'base-sepolia']]);
});

it('rejects an unknown key', function (): void {
    expect(fn (): array => RouteOptionParser::parse(['0.01', 'colour=red']))
        ->toThrow(ConfigurationException::class, 'Unknown x402 middleware option "colour"');
});

it('rejects a bare parameter that is not the first one', function (): void {
    expect(fn (): array => RouteOptionParser::parse(['0.01', 'nonsense']))
        ->toThrow(ConfigurationException::class, 'Expected a flag or key=value pair');
});
