<?php

declare(strict_types=1);

namespace PrivateChat;

final class OriginPolicy
{
    /** @var array<string, true> */
    private array $allowed = [];

    /** @param list<string> $allowedOrigins */
    public function __construct(array $allowedOrigins, private readonly bool $allowMissing)
    {
        foreach ($allowedOrigins as $origin) {
            $normalized = self::normalize($origin);
            if ($normalized === null || $normalized !== $origin) {
                throw new \InvalidArgumentException('WebSocket origins must be canonical http(s) origins without paths or wildcards.');
            }
            $this->allowed[$normalized] = true;
        }

        if (!$this->allowMissing && $this->allowed === []) {
            throw new \InvalidArgumentException('At least one allowed WebSocket origin is required.');
        }
    }

    public function allows(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return $this->allowMissing;
        }

        $normalized = self::normalize($origin);
        return $normalized !== null && isset($this->allowed[$normalized]);
    }

    private static function normalize(string $origin): ?string
    {
        if ($origin === '' || str_contains($origin, '*') || preg_match('/[\r\n]/', $origin) === 1) {
            return null;
        }

        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (isset($parts['path']) && $parts['path'] !== '') {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        if ($host === '' || preg_match('/\s/', $host) === 1) {
            return null;
        }

        $host = str_contains($host, ':') ? "[{$host}]" : $host;
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return "{$scheme}://{$host}{$port}";
    }
}
