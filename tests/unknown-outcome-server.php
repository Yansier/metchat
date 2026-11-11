<?php

declare(strict_types=1);

use Workerman\Connection\TcpConnection;
use Workerman\Worker;

require dirname(__DIR__) . '/server/vendor/autoload.php';

// Test-only server: acknowledge joins, then close immediately after receiving
// a chat packet without returning chat_result. It makes the browser's
// unknown-outcome path deterministic without changing production behavior.
$worker = new Worker('websocket://127.0.0.1:2346');
$worker->count = 1;
$worker->name = 'metchat-unknown-outcome-test';
$joinCount = 0;
$worker->onMessage = static function (TcpConnection $connection, string $raw) use (&$joinCount): void {
    $packet = json_decode($raw, true);
    if (!is_array($packet)) {
        return;
    }

    if (($packet['type'] ?? null) === 'join') {
        ++$joinCount;
        $memberId = bin2hex(random_bytes(16));
        $senderId = bin2hex(random_bytes(16));
        $peerMemberId = bin2hex(random_bytes(16));
        $peerSenderId = bin2hex(random_bytes(16));
        $nickname = is_string($packet['nickname'] ?? null) ? $packet['nickname'] : 'Tester';
        $now = gmdate('c');
        $history = [];
        if ($joinCount > 1) {
            $history[] = [
                'id' => bin2hex(random_bytes(16)), 'nickname' => 'HistoryPeer',
                'text' => 'history-after-unknown', 'sentAt' => $now,
                'expiresAt' => gmdate('c', time() + 60), 'senderId' => $peerSenderId,
            ];
        }
        $connection->send(json_encode([
            'type' => 'join', 'nickname' => $nickname, 'roomId' => 'TESTONLY',
            'self' => true, 'joinedAt' => $now, 'senderId' => $senderId,
            'memberId' => $memberId,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $connection->send(json_encode([
            'type' => 'history', 'serverTime' => $now, 'messages' => $history,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $connection->send(json_encode([
            'type' => 'presence', 'event' => 'join', 'nickname' => $nickname, 'count' => 1,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $connection->send(json_encode([
            'type' => 'members', 'version' => $joinCount, 'generatedAt' => $now,
            'count' => $joinCount > 1 ? 2 : 1,
            'selfMemberId' => $memberId,
            'members' => [[
                'memberId' => $memberId, 'nickname' => $nickname, 'senderId' => $senderId,
                'joinedAt' => $now, 'lastSeenAt' => $now,
            ], ...($joinCount > 1 ? [[
                'memberId' => $peerMemberId, 'nickname' => 'LivePeer', 'senderId' => $peerSenderId,
                'joinedAt' => $now, 'lastSeenAt' => $now,
            ]] : [])],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($joinCount > 1) {
            $connection->send(json_encode([
                'type' => 'typing', 'active' => true, 'memberId' => $peerMemberId,
                'nickname' => 'LivePeer', 'senderId' => $peerSenderId,
                'sequence' => 1, 'expiresAt' => gmdate('c', time() + 5),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $connection->send(json_encode([
                'type' => 'presence', 'event' => 'join', 'nickname' => 'LivePeer', 'count' => 2,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $connection->send(json_encode([
                'type' => 'pong', 'ts' => 1,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $connection->send(json_encode([
                'type' => 'chat', 'id' => bin2hex(random_bytes(16)), 'nickname' => 'LivePeer',
                'text' => 'chat-after-unknown', 'sentAt' => $now,
                'expiresAt' => gmdate('c', time() + 60), 'senderId' => $peerSenderId,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        return;
    }

    if (($packet['type'] ?? null) === 'chat') {
        $connection->close();
    }
};

Worker::runAll();
