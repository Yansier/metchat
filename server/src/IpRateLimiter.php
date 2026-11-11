<?php

declare(strict_types=1);

namespace PrivateChat;

use Closure;

final class IpRateLimiter
{
    /**
     * @var array<string, array{last_seen: int, concurrent: int, connections: list<int>, joins: list<int>, chats: list<int>}>
     */
    private array $states = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function __construct(
        private readonly int $connectionLimit,
        private readonly int $connectionWindow,
        private readonly int $joinLimit,
        private readonly int $joinWindow,
        private readonly int $chatLimit,
        private readonly int $chatWindow,
        private readonly int $concurrentLimit,
        private readonly int $stateTtl,
        private readonly int $maxTrackedIps,
        ?Closure $clock = null,
        ?string $hashKey = null,
    ) {
        foreach ([$connectionLimit, $connectionWindow, $joinLimit, $joinWindow, $chatLimit, $chatWindow, $concurrentLimit, $stateTtl, $maxTrackedIps] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('IP rate limits must be positive integers.');
            }
        }

        $this->clock = $clock ?? static fn (): int => time();
        $this->hashKey = $hashKey ?? random_bytes(32);
    }

    private readonly string $hashKey;

    /** @return array{allowed: bool, key: string, reason?: string} */
    public function acquireConnection(string $ip): array
    {
        $now = ($this->clock)();
        $key = hash_hmac('sha256', $ip, $this->hashKey);
        if (!isset($this->states[$key])) {
            $this->prune($now);
            if (count($this->states) >= $this->maxTrackedIps) {
                return ['allowed' => false, 'key' => $key, 'reason' => 'state_capacity'];
            }
            $this->states[$key] = ['last_seen' => $now, 'concurrent' => 0, 'connections' => [], 'joins' => [], 'chats' => []];
        }

        $state = &$this->states[$key];
        $state['last_seen'] = $now;
        $state['connections'] = $this->fresh($state['connections'], $now - $this->connectionWindow);
        if (count($state['connections']) >= $this->connectionLimit) {
            return ['allowed' => false, 'key' => $key, 'reason' => 'connection_rate'];
        }
        $state['connections'][] = $now;

        if ($state['concurrent'] >= $this->concurrentLimit) {
            return ['allowed' => false, 'key' => $key, 'reason' => 'concurrent'];
        }

        ++$state['concurrent'];
        return ['allowed' => true, 'key' => $key];
    }

    public function releaseConnection(string $key): void
    {
        if (!isset($this->states[$key])) {
            return;
        }
        $this->states[$key]['concurrent'] = max(0, $this->states[$key]['concurrent'] - 1);
        $this->states[$key]['last_seen'] = ($this->clock)();
    }

    public function allowJoin(string $key): bool
    {
        return $this->allow($key, 'joins', $this->joinLimit, $this->joinWindow);
    }

    public function allowChat(string $key): bool
    {
        return $this->allow($key, 'chats', $this->chatLimit, $this->chatWindow);
    }

    public function prune(?int $now = null): int
    {
        $now ??= ($this->clock)();
        $removed = 0;
        foreach ($this->states as $key => &$state) {
            $state['connections'] = $this->fresh($state['connections'], $now - $this->connectionWindow);
            $state['joins'] = $this->fresh($state['joins'], $now - $this->joinWindow);
            $state['chats'] = $this->fresh($state['chats'], $now - $this->chatWindow);
            if ($state['concurrent'] === 0 && $state['last_seen'] < $now - $this->stateTtl) {
                unset($this->states[$key]);
                ++$removed;
            }
        }
        unset($state);
        return $removed;
    }

    public function trackedCount(): int
    {
        return count($this->states);
    }

    /** @param 'joins'|'chats' $bucket */
    private function allow(string $key, string $bucket, int $limit, int $window): bool
    {
        if (!isset($this->states[$key])) {
            return false;
        }
        $now = ($this->clock)();
        $state = &$this->states[$key];
        $state['last_seen'] = $now;
        $state[$bucket] = $this->fresh($state[$bucket], $now - $window);
        if (count($state[$bucket]) >= $limit) {
            return false;
        }
        $state[$bucket][] = $now;
        return true;
    }

    /** @param list<int> $timestamps @return list<int> */
    private function fresh(array $timestamps, int $cutoff): array
    {
        return array_values(array_filter($timestamps, static fn (int $timestamp): bool => $timestamp > $cutoff));
    }
}
