<?php

declare(strict_types=1);

use HydraLabs\X402\Core\Exceptions\ConfigurationException;
use HydraLabs\X402\Enums\Mode;
use HydraLabs\X402\Enums\SettlementMode;

it('parses the modes the config documents', function (string $input, Mode $expected): void {
    expect(Mode::parse($input))->toBe($expected);
})->with([
    ['enforce', Mode::Enforce],
    ['ENFORCE', Mode::Enforce],
    ['  observe  ', Mode::Observe],
    ['off', Mode::Off],
]);

it('passes a mode instance straight through', function (): void {
    expect(Mode::parse(Mode::Observe))->toBe(Mode::Observe)
        ->and(SettlementMode::parse(SettlementMode::AfterResponse))->toBe(SettlementMode::AfterResponse);
});

it('names the valid modes when given something else', function (mixed $input): void {
    expect(fn (): Mode => Mode::parse($input))
        ->toThrow(ConfigurationException::class, 'enforce, observe, off');
})->with(['enabled', '', 1, null, true]);

it('parses the settlement modes', function (): void {
    expect(SettlementMode::parse('before_response'))->toBe(SettlementMode::BeforeResponse)
        ->and(SettlementMode::parse('AFTER_RESPONSE'))->toBe(SettlementMode::AfterResponse);
});

it('names the valid settlement modes when given something else', function (mixed $input): void {
    expect(fn (): SettlementMode => SettlementMode::parse($input))
        ->toThrow(ConfigurationException::class, 'before_response or after_response');
})->with(['later', '', 0, null, [['before_response']]]);
