<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Messages;

/**
 * ResourceInfo, x402 core spec v2 §5.1.2.
 *
 * The spec caps serviceName at 32 printable ASCII characters, tags at 5 entries
 * of 32 characters, and iconUrl at 2048 characters. Values are clamped on the
 * way out so an over-long description can never produce an invalid header.
 */
final class ResourceInfo
{
    private const MAX_SERVICE_NAME = 32;

    private const MAX_TAGS = 5;

    private const MAX_TAG_LENGTH = 32;

    private const MAX_ICON_URL = 2048;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        public readonly string $url,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?string $serviceName = null,
        public readonly array $tags = [],
        public readonly ?string $iconUrl = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tags = $data['tags'] ?? [];

        return new self(
            url: is_string($data['url'] ?? null) ? $data['url'] : '',
            description: self::nullableString($data['description'] ?? null),
            mimeType: self::nullableString($data['mimeType'] ?? null),
            serviceName: self::nullableString($data['serviceName'] ?? null),
            tags: is_array($tags) ? array_values(array_filter(array_map(
                static fn (mixed $tag): ?string => is_string($tag) ? $tag : null,
                $tags
            ))) : [],
            iconUrl: self::nullableString($data['iconUrl'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = ['url' => $this->url];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }

        if ($this->serviceName !== null) {
            $data['serviceName'] = self::clampAscii($this->serviceName, self::MAX_SERVICE_NAME);
        }

        if ($this->tags !== []) {
            $data['tags'] = array_values(array_map(
                static fn (string $tag): string => self::clampAscii($tag, self::MAX_TAG_LENGTH),
                array_slice($this->tags, 0, self::MAX_TAGS)
            ));
        }

        if ($this->iconUrl !== null) {
            $data['iconUrl'] = substr($this->iconUrl, 0, self::MAX_ICON_URL);
        }

        return $data;
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->description, $this->mimeType, $this->serviceName, $this->tags, $this->iconUrl);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function clampAscii(string $value, int $length): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';

        return substr($ascii, 0, $length);
    }
}
