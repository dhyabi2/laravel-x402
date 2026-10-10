<?php

declare(strict_types=1);

namespace HydraLabs\X402\Core\Messages;

use HydraLabs\X402\Core\Protocol\Version;

/**
 * SupportedResponse, x402 core spec v2 §7.3.
 */
final class SupportedResponse
{
    /**
     * @param list<SupportedKind> $kinds
     * @param list<string> $extensions
     * @param array<string, list<string>> $signers
     */
    public function __construct(
        public readonly array $kinds,
        public readonly array $extensions = [],
        public readonly array $signers = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $kinds = $data['kinds'] ?? [];
        $extensions = $data['extensions'] ?? [];
        $signers = $data['signers'] ?? [];

        return new self(
            kinds: array_values(array_map(
                static fn (array $kind): SupportedKind => SupportedKind::fromArray($kind),
                array_filter(is_array($kinds) ? $kinds : [], 'is_array')
            )),
            extensions: array_values(array_filter(
                is_array($extensions) ? $extensions : [],
                'is_string'
            )),
            signers: self::normalizeSigners($signers),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kinds' => array_map(static fn (SupportedKind $kind): array => $kind->toArray(), $this->kinds),
            'extensions' => $this->extensions,
            'signers' => $this->signers,
        ];
    }

    /**
     * Whether the facilitator can handle a scheme on a network.
     *
     * A `signers` or `kinds` entry may be namespace-wildcarded (`eip155:*`),
     * which is honoured here.
     */
    public function supports(string $scheme, string $network, int $version = Version::V2): bool
    {
        foreach ($this->kinds as $kind) {
            if ($kind->matches($scheme, $network, $version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function networks(): array
    {
        return array_values(array_unique(array_map(
            static fn (SupportedKind $kind): string => $kind->network,
            $this->kinds
        )));
    }

    /**
     * @return array<string, list<string>>
     */
    private static function normalizeSigners(mixed $signers): array
    {
        if (! is_array($signers)) {
            return [];
        }

        $normalized = [];

        foreach ($signers as $pattern => $addresses) {
            if (! is_string($pattern) || ! is_array($addresses)) {
                continue;
            }

            $normalized[$pattern] = array_values(array_filter($addresses, 'is_string'));
        }

        return $normalized;
    }
}
