<?php

declare(strict_types=1);

namespace PrivateChat;

use JsonException;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

final class ChatRoom
{
    /** @var array<int, array{connection: TcpConnection, nickname: ?string, room_key: ?string, connected_at: int, last_seen_at: int, join_attempts: int, handshake_authorized: bool, rate_key: ?string, rate_acquired: bool, chat_times: list<int>, rate_violations: int}> */
    private array $clients = [];
    private readonly RoomManager $rooms;
    private bool $shuttingDown = false;

    public function __construct(
        private readonly int $maxFrameBytes,
        private readonly int $maxNicknameCharacters,
        private readonly int $maxMessageCharacters,
        private readonly int $heartbeatTimeout,
        int $historyMaxMessages,
        int $historyTtlSeconds,
        int $maxRooms,
        private readonly int $maxTotalConnections,
        int $maxConnectionsPerRoom,
        private readonly int $maxJoinAttemptsPerConnection,
        private readonly int $roomCodeMinLength,
        private readonly int $roomCodeMaxLength,
        private readonly int $unjoinedTimeout,
        private readonly int $maxSendBufferBytes,
        private readonly int $connectionChatLimit,
        private readonly int $connectionChatWindow,
        private readonly OriginPolicy $originPolicy,
        private readonly ClientIpResolver $ipResolver,
        private readonly IpRateLimiter $ipLimiter,
    ) {
        $this->rooms = new RoomManager($maxRooms, $maxConnectionsPerRoom, $historyMaxMessages, $historyTtlSeconds);
    }

    public function attach(TcpConnection $connection): void
    {
        $connection->maxPackageSize = $this->maxFrameBytes + 14;
        $connection->maxSendBufferSize = $this->maxSendBufferBytes;
        if ($this->shuttingDown || count($this->clients) >= $this->maxTotalConnections) {
            $this->rejectHandshake($connection, 503, 'Service Unavailable', 'server_capacity');
            return;
        }

        $now = time();
        $rateKey = null;
        $rateAcquired = false;
        $peerIp = $connection->getRemoteIp();
        if (!$this->ipResolver->isTrustedProxy($peerIp)) {
            $clientIp = $this->ipResolver->resolve($peerIp, null);
            if ($clientIp === null) {
                $this->rejectHandshake($connection, 403, 'Forbidden', 'invalid_peer');
                return;
            }
            $result = $this->ipLimiter->acquireConnection($clientIp);
            if (!$result['allowed']) {
                $this->rejectHandshake($connection, 429, 'Too Many Requests', 'connection_rate_limited');
                return;
            }
            $rateKey = $result['key'];
            $rateAcquired = true;
        }

        $this->clients[$connection->id] = [
            'connection' => $connection, 'nickname' => null, 'room_key' => null,
            'connected_at' => $now, 'last_seen_at' => $now, 'join_attempts' => 0,
            'handshake_authorized' => false, 'rate_key' => $rateKey, 'rate_acquired' => $rateAcquired,
            'chat_times' => [], 'rate_violations' => 0,
        ];
    }

    public function authorizeHandshake(TcpConnection $connection, Request $request): void
    {
        if (!isset($this->clients[$connection->id])) {
            $this->rejectHandshake($connection, 503, 'Service Unavailable', 'connection_rejected');
            return;
        }
        $client = &$this->clients[$connection->id];
        if (!$client['rate_acquired']) {
            $xRealIp = $request->header('x-real-ip');
            $clientIp = $this->ipResolver->resolve($connection->getRemoteIp(), is_string($xRealIp) ? $xRealIp : null);
            if ($clientIp === null) {
                $this->rejectHandshake($connection, 403, 'Forbidden', 'proxy_ip_rejected');
                return;
            }
            $result = $this->ipLimiter->acquireConnection($clientIp);
            if (!$result['allowed']) {
                $this->rejectHandshake($connection, 429, 'Too Many Requests', 'connection_rate_limited');
                return;
            }
            $client['rate_key'] = $result['key'];
            $client['rate_acquired'] = true;
        }

        // Count the connection attempt before checking Origin so reconnecting
        // with a forged/missing Origin cannot bypass the per-IP window.
        $origin = $request->header('origin');
        if (!$this->originPolicy->allows(is_string($origin) ? $origin : null)) {
            $this->rejectHandshake($connection, 403, 'Forbidden', 'origin_rejected');
            return;
        }
        $client['handshake_authorized'] = true;
    }

    public function afterWebSocketConnected(TcpConnection $connection): void
    {
        if (($this->clients[$connection->id]['handshake_authorized'] ?? false) !== true) {
            $this->safeClose($connection);
        }
    }

    public function detach(TcpConnection $connection): void
    {
        $client = $this->clients[$connection->id] ?? null;
        if ($client === null) {
            return;
        }
        unset($this->clients[$connection->id]);
        if ($client['rate_acquired'] && $client['rate_key'] !== null) {
            $this->ipLimiter->releaseConnection($client['rate_key']);
        }
        if ($client['nickname'] !== null && $client['room_key'] !== null) {
            $this->rooms->leave($client['room_key'], $connection->id);
            $this->broadcastPresence($client['room_key'], 'leave', $client['nickname']);
        }
    }

    public function handle(TcpConnection $connection, string $rawFrame): void
    {
        if (!isset($this->clients[$connection->id]) || !$this->clients[$connection->id]['handshake_authorized']) {
            $this->safeClose($connection);
            return;
        }
        if (strlen($rawFrame) > $this->maxFrameBytes) {
            $this->sendErrorAndClose($connection, 'frame_too_large', '请求内容过长');
            return;
        }
        try {
            $packet = json_decode($rawFrame, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->sendError($connection, 'invalid_json', '请求格式无效');
            return;
        }
        unset($rawFrame);
        if (!is_array($packet) || array_is_list($packet) || count($packet) > 3 || !isset($packet['type']) || !is_string($packet['type'])) {
            $this->sendError($connection, 'invalid_packet', '消息结构无效');
            return;
        }

        switch ($packet['type']) {
            case 'join':
                if (!$this->hasOnlyKeys($packet, ['type', 'nickname', 'roomCode'])) {
                    $this->sendError($connection, 'invalid_packet', '消息结构无效'); return;
                }
                $this->clients[$connection->id]['last_seen_at'] = time();
                $this->handleJoin($connection, $packet); return;
            case 'chat':
                if (!$this->hasOnlyKeys($packet, ['type', 'text'])) {
                    $this->sendError($connection, 'invalid_packet', '消息结构无效'); return;
                }
                $this->clients[$connection->id]['last_seen_at'] = time();
                $this->handleChat($connection, $packet); return;
            case 'ping':
                if (!$this->hasOnlyKeys($packet, ['type', 'ts']) || (isset($packet['ts']) && !is_int($packet['ts']) && !is_float($packet['ts']))) {
                    $this->sendError($connection, 'invalid_packet', '消息结构无效'); return;
                }
                $this->clients[$connection->id]['last_seen_at'] = time();
                $this->sendJson($connection, ['type' => 'pong', 'ts' => $packet['ts'] ?? null]); return;
            case 'pong':
                if (!$this->hasOnlyKeys($packet, ['type', 'ts'])) {
                    $this->sendError($connection, 'invalid_packet', '消息结构无效'); return;
                }
                $this->clients[$connection->id]['last_seen_at'] = time(); return;
            default:
                $this->sendError($connection, 'unsupported_type', '不支持的消息类型');
        }
    }

    public function closeStaleConnections(): void
    {
        $now = time();
        foreach ($this->clients as $client) {
            $stale = $client['room_key'] === null
                ? $client['connected_at'] < $now - $this->unjoinedTimeout
                : $client['last_seen_at'] < $now - $this->heartbeatTimeout;
            if ($stale) {
                $this->safeClose($client['connection']);
            }
        }
    }

    /** @return array{expiredMessages: int, releasedRooms: int} */
    public function pruneExpiredMessages(): array
    {
        $this->ipLimiter->prune();
        return $this->rooms->pruneExpired();
    }

    public function handleBufferFull(TcpConnection $connection): void
    {
        $this->diagnostic('slow_client_closed');
        $this->safeClose($connection);
    }

    public function handleTransportError(TcpConnection $connection): void
    {
        $this->diagnostic('transport_error');
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;
        foreach ($this->clients as $client) {
            $this->sendErrorAndClose($client['connection'], 'server_shutdown', '服务器正在停止，请稍后重连');
        }
    }

    /** @param array<string, mixed> $packet */
    private function handleJoin(TcpConnection $connection, array $packet): void
    {
        $client = $this->clients[$connection->id];
        if ($client['room_key'] !== null) {
            $this->sendError($connection, 'already_joined', '当前连接已经加入房间'); return;
        }
        ++$this->clients[$connection->id]['join_attempts'];
        if ($this->clients[$connection->id]['join_attempts'] > $this->maxJoinAttemptsPerConnection) {
            $this->sendErrorAndClose($connection, 'too_many_join_attempts', '加入尝试过于频繁，请稍后重试'); return;
        }
        if ($client['rate_key'] === null || !$this->ipLimiter->allowJoin($client['rate_key'])) {
            $this->sendErrorAndClose($connection, 'join_rate_limited', '加入尝试过于频繁，请稍后重试'); return;
        }

        $nickname = is_string($packet['nickname'] ?? null) ? trim($packet['nickname']) : '';
        $roomCode = is_string($packet['roomCode'] ?? null) ? $packet['roomCode'] : '';
        if ($nickname === '' || !$this->isValidUtf8($nickname)) {
            $this->sendError($connection, 'invalid_nickname', '请输入有效昵称'); return;
        }
        if ($this->unicodeLength($nickname) > $this->maxNicknameCharacters) {
            $this->sendError($connection, 'nickname_too_long', '昵称过长'); return;
        }
        if (!$this->isValidRoomCode($roomCode)) {
            $this->sendError($connection, 'invalid_room_code', '房间暗号格式无效'); return;
        }

        $joinResult = $this->rooms->join($roomCode, $connection->id);
        unset($roomCode);
        if ($joinResult['status'] === RoomManager::ROOM_LIMIT_REACHED) {
            $this->sendError($connection, 'room_capacity', '当前房间数量已达上限，请稍后重试'); return;
        }
        if ($joinResult['status'] === RoomManager::ROOM_FULL) {
            $this->sendError($connection, 'room_full', '当前房间在线人数已达上限'); return;
        }

        $roomKey = $joinResult['roomKey'];
        $this->clients[$connection->id]['nickname'] = $nickname;
        $this->clients[$connection->id]['room_key'] = $roomKey;
        $joinedAt = gmdate('c');
        $this->sendJson($connection, ['type' => 'join', 'nickname' => $nickname, 'roomId' => $joinResult['roomId'], 'self' => true, 'joinedAt' => $joinedAt]);
        $this->sendJson($connection, ['type' => 'history', 'messages' => $this->rooms->history($roomKey)]);
        $this->broadcastToRoom($roomKey, ['type' => 'join', 'nickname' => $nickname, 'self' => false, 'joinedAt' => $joinedAt], $connection->id);
        $this->broadcastPresence($roomKey, 'join', $nickname);
    }

    /** @param array<string, mixed> $packet */
    private function handleChat(TcpConnection $connection, array $packet): void
    {
        $client = $this->clients[$connection->id];
        if ($client['nickname'] === null || $client['room_key'] === null) {
            $this->sendError($connection, 'join_required', '请先加入房间'); return;
        }
        if (!$this->allowConnectionChat($connection->id) || $client['rate_key'] === null || !$this->ipLimiter->allowChat($client['rate_key'])) {
            ++$this->clients[$connection->id]['rate_violations'];
            if ($this->clients[$connection->id]['rate_violations'] >= 3) {
                $this->sendErrorAndClose($connection, 'chat_rate_limited', '发送过于频繁，请稍后重试');
            } else {
                $this->sendError($connection, 'chat_rate_limited', '发送过于频繁，请稍后重试');
            }
            return;
        }

        $text = is_string($packet['text'] ?? null) ? trim($packet['text']) : '';
        if ($text === '' || !$this->isValidUtf8($text)) {
            $this->sendError($connection, 'empty_message', '消息不能为空'); return;
        }
        if ($this->unicodeLength($text) > $this->maxMessageCharacters) {
            $this->sendError($connection, 'message_too_long', '消息过长'); return;
        }
        $message = $this->rooms->addMessage($client['room_key'], $client['nickname'], $text);
        $this->broadcastToRoom($client['room_key'], array_merge(['type' => 'chat'], $message));
    }

    private function allowConnectionChat(int $connectionId): bool
    {
        $now = time();
        $times = array_values(array_filter($this->clients[$connectionId]['chat_times'], fn (int $timestamp): bool => $timestamp > $now - $this->connectionChatWindow));
        if (count($times) >= $this->connectionChatLimit) {
            $this->clients[$connectionId]['chat_times'] = $times; return false;
        }
        $times[] = $now;
        $this->clients[$connectionId]['chat_times'] = $times;
        return true;
    }

    private function broadcastPresence(string $roomKey, string $event, string $nickname): void
    {
        $this->broadcastToRoom($roomKey, ['type' => 'presence', 'event' => $event, 'nickname' => $nickname, 'count' => $this->rooms->onlineCount($roomKey)]);
    }

    /** @param array<string, mixed> $packet */
    private function broadcastToRoom(string $roomKey, array $packet, ?int $excludeConnectionId = null): void
    {
        $encoded = $this->encode($packet);
        foreach ($this->clients as $connectionId => $client) {
            if ($connectionId === $excludeConnectionId || $client['room_key'] !== $roomKey || $client['nickname'] === null) {
                continue;
            }
            try {
                if ($client['connection']->send($encoded) === false) {
                    $this->safeClose($client['connection']);
                }
            } catch (Throwable) {
                $this->diagnostic('send_failed');
                $this->safeClose($client['connection']);
            }
        }
        unset($encoded);
    }

    /** @param array<string, mixed> $packet */
    private function sendJson(TcpConnection $connection, array $packet): bool
    {
        try {
            if ($connection->send($this->encode($packet)) === false) {
                $this->safeClose($connection); return false;
            }
            return true;
        } catch (Throwable) {
            $this->diagnostic('send_failed');
            $this->safeClose($connection); return false;
        }
    }

    private function sendError(TcpConnection $connection, string $code, string $message): void
    {
        $this->sendJson($connection, ['type' => 'error', 'code' => $code, 'message' => $message]);
    }

    private function sendErrorAndClose(TcpConnection $connection, string $code, string $message): void
    {
        try {
            $connection->close($this->encode(['type' => 'error', 'code' => $code, 'message' => $message]));
        } catch (Throwable) {
            $this->safeClose($connection);
        }
    }

    private function rejectHandshake(TcpConnection $connection, int $status, string $reason, string $event): void
    {
        $this->diagnostic($event);
        $body = $status === 429 ? 'Request limit exceeded.' : 'WebSocket connection rejected.';
        $response = "HTTP/1.1 {$status} {$reason}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n{$body}";
        try {
            $connection->close($response, true);
        } catch (Throwable) {
            $this->safeClose($connection);
        }
    }

    private function safeClose(TcpConnection $connection): void
    {
        try {
            $connection->close();
        } catch (Throwable) {
            $this->diagnostic('close_failed');
        }
    }

    private function diagnostic(string $event): void
    {
        Worker::safeEcho("[private-chat] event={$event}\n");
    }

    /** @param array<string, mixed> $packet @param list<string> $allowed */
    private function hasOnlyKeys(array $packet, array $allowed): bool
    {
        foreach (array_keys($packet) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $packet */
    private function encode(array $packet): string
    {
        return json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function isValidRoomCode(string $roomCode): bool
    {
        $length = strlen($roomCode);
        return $length >= $this->roomCodeMinLength && $length <= $this->roomCodeMaxLength && preg_match('/\A[A-Za-z0-9_-]+\z/D', $roomCode) === 1;
    }

    private function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private function unicodeLength(string $value): int
    {
        $result = preg_match_all('/./us', $value, $matches);
        unset($matches);
        return $result === false ? PHP_INT_MAX : $result;
    }
}
