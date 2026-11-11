<?php

declare(strict_types=1);

require dirname(__DIR__) . '/server/vendor/autoload.php';

use PrivateChat\ClientIpResolver;
use PrivateChat\IpRateLimiter;
use PrivateChat\OriginPolicy;

function assertSecurity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$origins = new OriginPolicy(['http://localhost:8080', 'https://chat.example.test'], false);
assertSecurity($origins->allows('http://localhost:8080'), 'Exact development origin was rejected.');
assertSecurity(!$origins->allows('http://localhost:8081'), 'Origin port was not compared exactly.');
assertSecurity(!$origins->allows('https://chat.example.test.evil.invalid'), 'Origin used substring matching.');
assertSecurity(!$origins->allows(null), 'Missing production origin was accepted.');

$resolver = new ClientIpResolver(['127.0.0.1'], true);
assertSecurity($resolver->resolve('203.0.113.5', '198.51.100.9') === '203.0.113.5', 'Untrusted proxy header was accepted.');
assertSecurity($resolver->resolve('127.0.0.1', '198.51.100.9') === '198.51.100.9', 'Trusted X-Real-IP was not used.');
assertSecurity($resolver->resolve('127.0.0.1', '198.51.100.9, 203.0.113.1') === null, 'An IP chain was accepted as X-Real-IP.');

$now = 1_000;
$limiter = new IpRateLimiter(2, 10, 2, 10, 2, 10, 2, 20, 2, static function () use (&$now): int { return $now; }, 'unit-test-key');
$first = $limiter->acquireConnection('198.51.100.10');
$second = $limiter->acquireConnection('198.51.100.10');
$third = $limiter->acquireConnection('198.51.100.10');
assertSecurity($first['allowed'] && $second['allowed'] && !$third['allowed'], 'Connection/concurrency limiting failed.');
$limiter->releaseConnection($first['key']);
assertSecurity(!$limiter->acquireConnection('198.51.100.10')['allowed'], 'Reconnect reset the connection window.');
assertSecurity($limiter->allowJoin($first['key']) && $limiter->allowJoin($first['key']) && !$limiter->allowJoin($first['key']), 'Join window limiting failed.');
assertSecurity($limiter->allowChat($first['key']) && $limiter->allowChat($first['key']) && !$limiter->allowChat($first['key']), 'Chat window limiting failed.');
$limiter->releaseConnection($second['key']);
$now += 31;
assertSecurity($limiter->prune() === 1 && $limiter->trackedCount() === 0, 'Expired IP limiter state was not released.');

echo "PASS: exact Origin policy, trusted-proxy IP resolution, bounded IP rate windows, reconnect persistence, and limiter-state cleanup.\n";
