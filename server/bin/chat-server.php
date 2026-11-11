<?php

declare(strict_types=1);

use PrivateChat\ChatRoom;
use PrivateChat\AnonymousIdentity;
use PrivateChat\ClientIpResolver;
use PrivateChat\IpRateLimiter;
use PrivateChat\OriginPolicy;
use PrivateChat\PrivacyWebsocket;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

$serverRoot = dirname(__DIR__);
$autoload = $serverRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are missing. Run: cd server && composer install\n");
    exit(1);
}
require $autoload;

/** @var array<string, mixed> $config */
$config = require $serverRoot . '/config/chat.php';
$originPolicy = new OriginPolicy($config['allowed_origins'], (bool) $config['allow_missing_origin']);
$ipResolver = new ClientIpResolver($config['trusted_proxies'], (bool) $config['require_proxy_client_ip']);
$ipLimiter = new IpRateLimiter(
    (int) $config['ip_connection_limit'], (int) $config['ip_connection_window'],
    (int) $config['ip_join_limit'], (int) $config['ip_join_window'],
    (int) $config['ip_chat_limit'], (int) $config['ip_chat_window'],
    (int) $config['ip_concurrent_limit'], (int) $config['ip_state_ttl'],
    (int) $config['max_tracked_ips'],
);
$identities = new AnonymousIdentity(
    (int) $config['identity_token_ttl_seconds'],
    (int) $config['identity_token_max_bytes'],
);

$room = new ChatRoom(
    (int) $config['max_frame_bytes'], (int) $config['max_nickname_characters'],
    (int) $config['max_message_characters'], (int) $config['heartbeat_timeout'],
    (int) $config['history_max_messages'], (int) $config['history_ttl_seconds'],
    (int) $config['ghost_grace_seconds'],
    (int) $config['max_rooms'], (int) $config['max_total_connections'],
    (int) $config['max_connections_per_room'], (int) $config['max_join_attempts_per_connection'],
    (int) $config['room_code_min_length'], (int) $config['room_code_max_length'],
    (int) $config['unjoined_timeout'], (int) $config['max_send_buffer_bytes'],
    (int) $config['connection_chat_limit'], (int) $config['connection_chat_window'],
    (int) $config['typing_ttl_seconds'], (int) $config['connection_typing_limit'],
    (int) $config['connection_typing_window'],
    $originPolicy, $ipResolver, $ipLimiter, $identities,
);

$worker = new Worker('tcp://' . $config['listen']);
$worker->protocol = PrivacyWebsocket::class;
$worker->name = 'private-ephemeral-chat';
$worker->count = (int) $config['worker_count'];

$worker->onWorkerStart = static function () use ($room, $config): void {
    Timer::add((int) $config['heartbeat_check_interval'], static fn () => $room->closeStaleConnections());
    Timer::add((int) $config['history_cleanup_interval'], static fn () => $room->pruneExpiredMessages());
    Timer::add((int) $config['room_lifecycle_cleanup_interval'], static fn () => $room->pruneRoomLifecycle());
    Timer::add((int) $config['typing_cleanup_interval'], static fn () => $room->pruneTypingStates());
};
$worker->onConnect = static fn (TcpConnection $connection) => $room->attach($connection);
$worker->onWebSocketConnect = static fn (TcpConnection $connection, Request $request) => $room->authorizeHandshake($connection, $request);
$worker->onWebSocketConnected = static fn (TcpConnection $connection) => $room->afterWebSocketConnected($connection);
$worker->onMessage = static fn (TcpConnection $connection, string $frame) => $room->handle($connection, $frame);
$worker->onClose = static fn (TcpConnection $connection) => $room->detach($connection);
$worker->onBufferFull = static fn (TcpConnection $connection) => $room->handleBufferFull($connection);
$worker->onError = static fn (TcpConnection $connection) => $room->handleTransportError($connection);
$worker->onWorkerStop = static fn () => $room->shutdown();

Worker::runAll();
