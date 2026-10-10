<?php

declare(strict_types=1);

namespace Requestway\X402\Core\Protocol;

use Requestway\X402\Core\Exceptions\MalformedPaymentException;

/**
 * Chooses the codec for an incoming request and for emitting responses.
 *
 * Codecs are tried newest-first, so a client that sends both the v2 and the v1
 * header is served over v2.
 */
final class CodecRegistry
{
    /** @var array<int, Codec> */
    private array $codecs = [];

    /**
     * @param list<Codec> $codecs
     */
    public function __construct(array $codecs = [])
    {
        foreach ($codecs === [] ? [new V2Codec, new V1Codec] : $codecs as $codec) {
            $this->codecs[$codec->version()] = $codec;
        }

        krsort($this->codecs);
    }

    public function register(Codec $codec): void
    {
        $this->codecs[$codec->version()] = $codec;
        krsort($this->codecs);
    }

    /**
     * The codec used to advertise requirements and encode settlements when no
     * payment has been received yet.
     */
    public function current(): Codec
    {
        return $this->for(Version::CURRENT);
    }

    /**
     * @throws MalformedPaymentException
     */
    public function for(int $version): Codec
    {
        return $this->codecs[$version] ?? throw new MalformedPaymentException(sprintf(
            'No codec registered for x402 protocol version %d.',
            $version
        ));
    }

    /**
     * @return array<int, Codec>
     */
    public function all(): array
    {
        return $this->codecs;
    }

    /**
     * Pick the codec whose payment header the client actually sent.
     *
     * @param callable(string): ?string $header resolves a header name to its value
     * @return array{0: Codec, 1: string}|null
     */
    public function detect(callable $header): ?array
    {
        foreach ($this->codecs as $codec) {
            $value = $header($codec->paymentSignatureHeader());

            if (is_string($value) && trim($value) !== '') {
                return [$codec, trim($value)];
            }
        }

        return null;
    }

    /**
     * Every request header a client might send a payment in.
     *
     * @return list<string>
     */
    public function paymentHeaders(): array
    {
        return array_values(array_map(
            static fn (Codec $codec): string => $codec->paymentSignatureHeader(),
            $this->codecs
        ));
    }
}
