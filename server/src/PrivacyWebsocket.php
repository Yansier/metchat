<?php

declare(strict_types=1);

namespace PrivateChat;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

/**
 * Keeps Workerman's WebSocket implementation while suppressing its one
 * diagnostic path that otherwise includes the full raw frame in hex.
 */
final class PrivacyWebsocket extends Websocket
{
    public static function input(string $buffer, TcpConnection $connection): int
    {
        if (strlen($buffer) >= 2 && !empty($connection->context->websocketHandshake)) {
            $opcode = ord($buffer[0]) & 0x0f;
            if (!in_array($opcode, [0x0, 0x1, 0x2, 0x8, 0x9, 0xa], true)) {
                Worker::safeEcho("invalid websocket opcode={$opcode}; connection closed\n");
                $connection->close();
                return 0;
            }
        }

        return parent::input($buffer, $connection);
    }
}
