<?php

declare(strict_types=1);

namespace PrivateChat;

final class ClientIpResolver
{
    /** @var array<string, true> */
    private array $trustedProxies = [];

    /** @param list<string> $trustedProxies */
    public function __construct(array $trustedProxies, private readonly bool $requireProxyClientIp)
    {
        foreach ($trustedProxies as $proxy) {
            $normalized = self::normalizeIp($proxy);
            if ($normalized === null) {
                throw new \InvalidArgumentException('Trusted proxies must be exact IP addresses.');
            }
            $this->trustedProxies[$normalized] = true;
        }
    }

    public function isTrustedProxy(string $peerIp): bool
    {
        $peerIp = self::normalizeIp($peerIp) ?? '';
        return isset($this->trustedProxies[$peerIp]);
    }

    public function resolve(string $peerIp, ?string $xRealIp): ?string
    {
        $peer = self::normalizeIp($peerIp);
        if ($peer === null) {
            return null;
        }

        if (!isset($this->trustedProxies[$peer])) {
            return $peer;
        }

        $forwarded = self::normalizeIp($xRealIp ?? '');
        if ($forwarded !== null) {
            return $forwarded;
        }

        return $this->requireProxyClientIp ? null : $peer;
    }

    private static function normalizeIp(string $ip): ?string
    {
        if ($ip === '' || str_contains($ip, ',') || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = @inet_pton($ip);
        return $packed === false ? null : inet_ntop($packed);
    }
}
