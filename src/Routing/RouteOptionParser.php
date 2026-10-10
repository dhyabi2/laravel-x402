<?php

declare(strict_types=1);

namespace Requestway\X402\Routing;

use Requestway\X402\Core\Exceptions\ConfigurationException;

/**
 * Parses middleware parameters into route options.
 *
 *     x402:0.01                       → price 0.01
 *     x402:premium                    → the "premium" tier
 *     x402:0.25,free-for-auth         → price plus a flag
 *     x402:0.01,network=eip155:8453   → price plus a key=value option
 */
final class RouteOptionParser
{
    private const FLAGS = [
        'free-for-auth' => ['free_for_auth', true],
        'paid-for-auth' => ['free_for_auth', false],
    ];

    private const KEYS = [
        'price' => 'price',
        'tier' => 'price',
        'description' => 'description',
        'mime' => 'mime_type',
        'mime_type' => 'mime_type',
        'mimetype' => 'mime_type',
        'network' => 'network',
        'networks' => 'networks',
        'pay_to' => 'pay_to',
        'payto' => 'pay_to',
        'settle' => 'settlement',
        'settlement' => 'settlement',
        'timeout' => 'max_timeout_seconds',
        'max_timeout_seconds' => 'max_timeout_seconds',
        'tags' => 'tags',
    ];

    /**
     * @param list<string> $parameters
     * @return array<string, mixed>
     */
    public static function parse(array $parameters): array
    {
        $options = [];

        foreach ($parameters as $index => $parameter) {
            $parameter = trim($parameter);

            if ($parameter === '') {
                continue;
            }

            if (isset(self::FLAGS[strtolower($parameter)])) {
                [$key, $value] = self::FLAGS[strtolower($parameter)];
                $options[$key] = $value;

                continue;
            }

            if (str_contains($parameter, '=')) {
                [$rawKey, $value] = explode('=', $parameter, 2);
                $key = self::KEYS[strtolower(trim($rawKey))] ?? null;

                if ($key === null) {
                    throw new ConfigurationException(sprintf(
                        'Unknown x402 middleware option "%s".',
                        trim($rawKey)
                    ));
                }

                $options[$key] = self::castValue($key, trim($value));

                continue;
            }

            if ($index === 0) {
                $options['price'] = $parameter;

                continue;
            }

            throw new ConfigurationException(sprintf(
                'Unknown x402 middleware option "%s". Expected a flag or key=value pair.',
                $parameter
            ));
        }

        return $options;
    }

    private static function castValue(string $key, string $value): mixed
    {
        return match ($key) {
            'max_timeout_seconds' => (int) $value,
            'networks', 'tags' => array_values(array_filter(array_map('trim', explode('|', $value)))),
            default => $value,
        };
    }
}
