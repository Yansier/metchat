<?php

declare(strict_types=1);

$environment = strtolower(getenv('CHAT_ENV') ?: 'development');
if (!in_array($environment, ['development', 'test', 'production'], true)) {
    throw new RuntimeException('CHAT_ENV must be development, test, or production.');
}

$envInt = static function (string $name, int $default, int $minimum = 1): int {
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < $minimum) {
        throw new RuntimeException("{$name} must be an integer greater than or equal to {$minimum}.");
    }
    return (int) $value;
};

$envList = static function (string $name, array $default): array {
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        return $default;
    }
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
};

$isProduction = $environment === 'production';
$allowedOrigins = $envList('CHAT_ALLOWED_ORIGINS', $isProduction ? [] : [
    'http://127.0.0.1:8080',
    'http://localhost:8080',
]);
$trustedProxies = $envList('CHAT_TRUSTED_PROXIES', $isProduction ? ['127.0.0.1', '::1'] : []);

return [
    'environment' => $environment,
    'listen' => '127.0.0.1:2346',
    'worker_count' => 1,
    'allowed_origins' => $allowedOrigins,
    'allow_missing_origin' => !$isProduction,
    'trusted_proxies' => $trustedProxies,
    'require_proxy_client_ip' => $isProduction,
    'heartbeat_check_interval' => $envInt('CHAT_HEARTBEAT_CHECK_INTERVAL', 10),
    'heartbeat_timeout' => $envInt('CHAT_HEARTBEAT_TIMEOUT', 75),
    'unjoined_timeout' => $envInt('CHAT_UNJOINED_TIMEOUT', 15),
    'history_cleanup_interval' => $envInt('CHAT_HISTORY_CLEANUP_INTERVAL', 60),
    'history_ttl_seconds' => $envInt('CHAT_HISTORY_TTL_SECONDS', 86_400),
    'ghost_grace_seconds' => $envInt('CHAT_GHOST_GRACE_SECONDS', 30),
    'room_lifecycle_cleanup_interval' => $envInt('CHAT_ROOM_LIFECYCLE_CLEANUP_INTERVAL', 1),
    'history_max_messages' => 50,
    'max_rooms' => 100,
    'max_total_connections' => 64,
    'max_connections_per_room' => 16,
    'max_join_attempts_per_connection' => 5,
    'room_code_min_length' => 8,
    'room_code_max_length' => 64,
    'max_frame_bytes' => 16_384,
    'max_send_buffer_bytes' => 524_288,
    'max_nickname_characters' => 24,
    'max_message_characters' => 2_000,
    'identity_token_ttl_seconds' => 604_800,
    'identity_token_max_bytes' => 512,
    'typing_ttl_seconds' => 5,
    'typing_cleanup_interval' => 1,
    'connection_typing_limit' => $envInt('CHAT_CONNECTION_TYPING_LIMIT', 12),
    'connection_typing_window' => $envInt('CHAT_CONNECTION_TYPING_WINDOW', 5),
    'ip_connection_limit' => $envInt('CHAT_IP_CONNECTION_LIMIT', $isProduction ? 40 : 1_000),
    'ip_connection_window' => $envInt('CHAT_IP_CONNECTION_WINDOW', 60),
    'ip_join_limit' => $envInt('CHAT_IP_JOIN_LIMIT', $isProduction ? 30 : 1_000),
    'ip_join_window' => $envInt('CHAT_IP_JOIN_WINDOW', 60),
    'ip_chat_limit' => $envInt('CHAT_IP_CHAT_LIMIT', $isProduction ? 120 : 1_000),
    'ip_chat_window' => $envInt('CHAT_IP_CHAT_WINDOW', 10),
    'ip_concurrent_limit' => $envInt('CHAT_IP_CONCURRENT_LIMIT', $isProduction ? 24 : 64),
    'ip_state_ttl' => $envInt('CHAT_IP_STATE_TTL', 300),
    'max_tracked_ips' => $envInt('CHAT_MAX_TRACKED_IPS', 1_024),
    'connection_chat_limit' => $envInt('CHAT_CONNECTION_CHAT_LIMIT', $isProduction ? 20 : 1_000),
    'connection_chat_window' => $envInt('CHAT_CONNECTION_CHAT_WINDOW', 5),
];
