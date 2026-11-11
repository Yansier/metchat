<?php

declare(strict_types=1);

final class TestWebSocket
{
    /** @var resource */
    private $stream;

    private string $buffer = '';

    public function __construct()
    {
        $stream = stream_socket_client('tcp://127.0.0.1:2346', $errorCode, $errorMessage, 2);
        if ($stream === false) {
            throw new RuntimeException("Connection failed ({$errorCode}): {$errorMessage}");
        }

        $this->stream = $stream;
        stream_set_timeout($this->stream, 0, 250_000);
        $key = base64_encode(random_bytes(16));
        $request = "GET / HTTP/1.1\r\n"
            . "Host: 127.0.0.1:2346\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
        $this->writeAll($request);

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($this->stream, 1024);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('WebSocket handshake timed out.');
            }
            $response .= $chunk;
        }

        [$headers, $this->buffer] = explode("\r\n\r\n", $response, 2);
        if (!str_starts_with($headers, 'HTTP/1.1 101')) {
            throw new RuntimeException('WebSocket handshake was rejected.');
        }
    }

    /** @param array<string, mixed> $packet */
    public function send(array $packet): void
    {
        $payload = json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $length = strlen($payload);
        $header = chr(0x81);

        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            throw new RuntimeException('Test frame is too large.');
        }

        $mask = random_bytes(4);
        $masked = '';
        for ($index = 0; $index < $length; ++$index) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }

        $this->writeAll($header . $mask . $masked);
    }

    /** @return array<string, mixed>|null */
    public function receive(float $timeoutSeconds = 1.0): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $header = $this->readBytes(2);
            if ($header === null) {
                continue;
            }

            $opcode = ord($header[0]) & 0x0f;
            $length = ord($header[1]) & 0x7f;
            if ($length === 126) {
                $extended = $this->readBytes(2);
                if ($extended === null) {
                    return null;
                }
                $length = unpack('nlength', $extended)['length'];
            } elseif ($length === 127) {
                throw new RuntimeException('Unexpected 64-bit test frame.');
            }

            $payload = $length > 0 ? $this->readBytes($length) : '';
            if ($payload === null) {
                return null;
            }

            if ($opcode === 0x8) {
                return null;
            }
            if ($opcode !== 0x1) {
                continue;
            }

            $packet = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            return is_array($packet) ? $packet : null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function expect(string $type, float $timeoutSeconds = 2.0): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $packet = $this->receive(max(0.1, $deadline - microtime(true)));
            if ($packet !== null && ($packet['type'] ?? null) === $type) {
                return $packet;
            }
        }

        throw new RuntimeException("Expected packet type '{$type}' was not received.");
    }

    public function assertNoType(string $type, float $timeoutSeconds = 0.35): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $packet = $this->receive(max(0.1, $deadline - microtime(true)));
            if ($packet !== null && ($packet['type'] ?? null) === $type) {
                throw new RuntimeException("Unexpected packet type '{$type}' was received.");
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function readBytes(int $length): ?string
    {
        while (strlen($this->buffer) < $length) {
            $chunk = fread($this->stream, max(1, $length - strlen($this->buffer)));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $this->buffer .= $chunk;
        }

        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $result;
    }

    private function writeAll(string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write a complete WebSocket frame.');
            }
            $offset += $written;
        }
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return array{history: list<array<string, mixed>>, roomId: string, senderId: string, identityToken: string, identityRestored: bool}
 */
function joinClient(TestWebSocket $client, string $nickname, string $roomCode, ?string $identityToken = null): array
{
    $packet = ['type' => 'join', 'nickname' => $nickname, 'roomCode' => $roomCode];
    if ($identityToken !== null) {
        $packet['identityToken'] = $identityToken;
    }
    $client->send($packet);
    unset($packet);
    $join = $client->expect('join');
    check(($join['self'] ?? false) === true, "{$nickname} did not receive a join acknowledgement.");
    check(is_string($join['roomId'] ?? null), "{$nickname} did not receive a safe room identifier.");
    check(!array_key_exists('roomCode', $join), 'Join response exposed a full room code.');
    check(is_string($join['senderId'] ?? null) && $join['senderId'] !== '', "{$nickname} did not receive a sender ID.");
    check(is_string($join['identityToken'] ?? null) && $join['identityToken'] !== '', "{$nickname} did not receive an identity credential.");
    check(is_bool($join['identityRestored'] ?? null), "{$nickname} did not receive identity restoration state.");
    $historyPacket = $client->expect('history');
    $client->expect('presence');
    $history = $historyPacket['messages'] ?? null;
    check(is_array($history), "{$nickname} did not receive a history array.");

    return [
        'history' => $history,
        'roomId' => $join['roomId'],
        'senderId' => $join['senderId'],
        'identityToken' => $join['identityToken'],
        'identityRestored' => $join['identityRestored'],
    ];
}

/** @param list<array<string, mixed>> $history */
function historyContainsText(array $history, string $text): bool
{
    foreach ($history as $message) {
        if (($message['text'] ?? null) === $text) {
            return true;
        }
    }
    return false;
}

/** @param TestWebSocket[] $clients */
function closeAll(array $clients): void
{
    foreach ($clients as $client) {
        $client->close();
    }
}

$mode = $argv[1] ?? 'full';
$roomA = '261110Achat';
$roomB = '261110Bchat';
$restartMarkerA = 'phase-1.2-restart-room-a';
$restartMarkerB = 'phase-1.2-restart-room-b';

if ($mode === 'seed-restart') {
    $clients = [new TestWebSocket(), new TestWebSocket()];
    try {
        joinClient($clients[0], 'RestartA', $roomA);
        joinClient($clients[1], 'RestartB', $roomB);
        $clients[0]->send(['type' => 'chat', 'text' => $restartMarkerA]);
        $clients[1]->send(['type' => 'chat', 'text' => $restartMarkerB]);
        check(($clients[0]->expect('chat')['text'] ?? null) === $restartMarkerA, 'Room A restart marker failed.');
        check(($clients[1]->expect('chat')['text'] ?? null) === $restartMarkerB, 'Room B restart marker failed.');
        echo "PASS: restart markers stored in two rooms. Restart Workerman, then run verify-restart.\n";
    } finally {
        closeAll($clients);
    }
    exit(0);
}

if ($mode === 'verify-restart') {
    $clients = [new TestWebSocket(), new TestWebSocket()];
    try {
        $historyA = joinClient($clients[0], 'VerifyA', $roomA)['history'];
        $historyB = joinClient($clients[1], 'VerifyB', $roomB)['history'];
        check(!historyContainsText($historyA, $restartMarkerA), 'Room A history survived restart.');
        check(!historyContainsText($historyB, $restartMarkerB), 'Room B history survived restart.');
        echo "PASS: Workerman restart cleared all room histories.\n";
    } finally {
        closeAll($clients);
    }
    exit(0);
}

if ($mode === 'identity-restart') {
    $client = new TestWebSocket();
    try {
        $before = joinClient($client, 'RestartIdentity', $roomA);
        $client->close();
        echo "READY: restart Workerman, then continue this test.\n";
        if (fgets(STDIN) === false) {
            throw new RuntimeException('Identity restart test was not resumed.');
        }

        $afterClient = new TestWebSocket();
        try {
            $after = joinClient($afterClient, 'RestartIdentity', $roomA, $before['identityToken']);
            check(!$after['identityRestored'], 'A credential issued by the previous Worker process was restored.');
            check($after['senderId'] !== $before['senderId'], 'Room sender ID survived a Worker signing-key restart.');
            echo "PASS: Workerman restart invalidated the old identity credential without persisting it.\n";
        } finally {
            $afterClient->close();
        }
    } finally {
        $client->close();
    }
    exit(0);
}

if ($mode !== 'full') {
    throw new InvalidArgumentException('Mode must be full, seed-restart, verify-restart, or identity-restart.');
}

$clients = [];
$token = bin2hex(random_bytes(4));
$aText = "room-a-{$token}";
$bText = "room-b-{$token}";
$bAfterExitText = "room-a-after-exit-{$token}";

try {
    $alice = $clients[] = new TestWebSocket();
    $aliceJoin = joinClient($alice, 'Admin', $roomA);

    $bob = $clients[] = new TestWebSocket();
    $bobJoin = joinClient($bob, 'Admin', $roomA);
    check($aliceJoin['roomId'] === $bobJoin['roomId'], 'Equal room codes returned different room IDs.');
    check($aliceJoin['senderId'] !== $bobJoin['senderId'], 'Same-name clients were assigned the same identity.');
    $bobBroadcast = $alice->expect('join');
    check(!array_key_exists('identityToken', $bobBroadcast), 'Another client received a private identity credential.');
    $alice->expect('presence');

    $carol = $clients[] = new TestWebSocket();
    $carolJoin = joinClient($carol, 'Admin', $roomB, $aliceJoin['identityToken']);
    check($carolJoin['roomId'] !== $aliceJoin['roomId'], 'Different room codes returned the same room ID.');
    check($carolJoin['senderId'] !== $aliceJoin['senderId'], 'One identity was linkable across rooms.');
    check($carolJoin['identityRestored'], 'A valid identity credential was not restored in another room.');

    $alice->send(['type' => 'chat', 'text' => $aText]);
    $aliceMessage = $alice->expect('chat');
    $bobMessage = $bob->expect('chat');
    check(($aliceMessage['id'] ?? null) === ($bobMessage['id'] ?? null), 'Room A clients received inconsistent IDs.');
    check(($aliceMessage['senderId'] ?? null) === $aliceJoin['senderId'], 'Sender echo did not use Alice server identity.');
    check(($bobMessage['senderId'] ?? null) === $aliceJoin['senderId'], 'Room peer saw a different sender identity.');
    $carol->assertNoType('chat');

    $carol->send(['type' => 'chat', 'text' => $bText]);
    check(($carol->expect('chat')['text'] ?? null) === $bText, 'Room B sender echo failed.');
    $alice->assertNoType('chat');
    $bob->assertNoType('chat');

    $alice->close();
    $bob->expect('presence');
    $bob->send(['type' => 'chat', 'text' => $bAfterExitText]);
    check(($bob->expect('chat')['text'] ?? null) === $bAfterExitText, 'Remaining room member could not continue chatting.');

    $aliceReloaded = $clients[] = new TestWebSocket();
    $aliceReloadedJoin = joinClient($aliceReloaded, 'Admin Renamed', $roomA, $aliceJoin['identityToken']);
    $restoredA = $aliceReloadedJoin['history'];
    check($aliceReloadedJoin['identityRestored'], 'Refresh/reconnect credential was not restored.');
    check($aliceReloadedJoin['senderId'] === $aliceJoin['senderId'], 'Changing nickname changed the room identity.');
    check(historyContainsText($restoredA, $aText), 'Room A message was not restored.');
    check(historyContainsText($restoredA, $bAfterExitText), 'Room A post-exit message was not restored.');
    check(!historyContainsText($restoredA, $bText), 'Room A history exposed Room B content.');
    $restoredAliceMessages = array_values(array_filter($restoredA, static fn (array $message): bool => ($message['text'] ?? null) === $aText));
    check(($restoredAliceMessages[0]['senderId'] ?? null) === $aliceJoin['senderId'], 'Restored history lost Alice message ownership.');

    $forged = $clients[] = new TestWebSocket();
    $forgedToken = substr($aliceJoin['identityToken'], 0, -1) . (str_ends_with($aliceJoin['identityToken'], 'A') ? 'B' : 'A');
    $forgedJoin = joinClient($forged, 'Admin', $roomA, $forgedToken);
    check(!$forgedJoin['identityRestored'], 'A forged identity credential was accepted.');
    check($forgedJoin['senderId'] !== $aliceJoin['senderId'], 'A forged credential impersonated Alice.');
    $forged->close();

    $carol->close();
    $carolReloaded = $clients[] = new TestWebSocket();
    $restoredB = joinClient($carolReloaded, 'Admin', $roomB)['history'];
    check(historyContainsText($restoredB, $bText), 'Room B message was not restored.');
    check(!historyContainsText($restoredB, $aText), 'Room B history exposed Room A content.');

    // Both rooms independently roll over at 50 messages.
    for ($index = 1; $index <= 55; ++$index) {
        $textA = sprintf('cap-a-%s-%02d', $token, $index);
        $textB = sprintf('cap-b-%s-%02d', $token, $index);
        $aliceReloaded->send(['type' => 'chat', 'text' => $textA]);
        $carolReloaded->send(['type' => 'chat', 'text' => $textB]);
        check(($aliceReloaded->expect('chat')['text'] ?? null) === $textA, "Room A echo mismatch at {$index}.");
        check(($carolReloaded->expect('chat')['text'] ?? null) === $textB, "Room B echo mismatch at {$index}.");
    }
    $aliceReloaded->assertNoType('chat');
    $carolReloaded->assertNoType('chat');

    $readerA = $clients[] = new TestWebSocket();
    $historyA = joinClient($readerA, 'Reader', $roomA)['history'];
    $readerB = $clients[] = new TestWebSocket();
    $historyB = joinClient($readerB, 'Reader', $roomB)['history'];
    check(count($historyA) === 50 && count($historyB) === 50, 'Rooms did not independently cap at 50 messages.');
    check(($historyA[0]['text'] ?? null) === "cap-a-{$token}-06", 'Room A cap removed the wrong messages.');
    check(($historyB[0]['text'] ?? null) === "cap-b-{$token}-06", 'Room B cap removed the wrong messages.');
    check(!historyContainsText($historyA, "cap-b-{$token}-55"), 'Room A cap history contains Room B data.');
    check(!historyContainsText($historyB, "cap-a-{$token}-55"), 'Room B cap history contains Room A data.');

    // An unjoined connection cannot chat or read history.
    $unjoined = $clients[] = new TestWebSocket();
    $unjoined->send(['type' => 'chat', 'text' => 'must-be-rejected']);
    check(($unjoined->expect('error')['code'] ?? null) === 'join_required', 'Unjoined chat was not rejected.');
    $unjoined->send(['type' => 'history']);
    check(($unjoined->expect('error')['code'] ?? null) === 'unsupported_type', 'Client-triggered history request was not rejected.');

    // Empty/invalid codes fail, and repeated join attempts are bounded.
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        $unjoined->send(['type' => 'join', 'nickname' => 'Invalid', 'roomCode' => $attempt === 1 ? '' : 'bad code']);
        check(($unjoined->expect('error')['code'] ?? null) === 'invalid_room_code', 'Invalid room code was accepted.');
    }
    $unjoined->send(['type' => 'join', 'nickname' => 'Invalid', 'roomCode' => 'bad code']);
    check(($unjoined->expect('error')['code'] ?? null) === 'too_many_join_attempts', 'Join attempt limit was not enforced.');

    closeAll($clients);
    $clients = [];
    usleep(250_000);

    // A room accepts 16 connections and rejects the seventeenth.
    $roomCapacityCode = "Capacity_{$token}";
    for ($index = 1; $index <= 16; ++$index) {
        $member = $clients[] = new TestWebSocket();
        joinClient($member, "Member{$index}", $roomCapacityCode);
    }
    $overflow = $clients[] = new TestWebSocket();
    $overflow->send(['type' => 'join', 'nickname' => 'Overflow', 'roomCode' => $roomCapacityCode]);
    check(($overflow->expect('error')['code'] ?? null) === 'room_full', 'Per-room connection limit was not enforced.');

    closeAll($clients);
    $clients = [];
    usleep(250_000);

    // Hold all 64 allowed connections open; the next handshake receives a safe rejection.
    for ($index = 0; $index < 64; ++$index) {
        $clients[] = new TestWebSocket();
    }
    $capacityRejected = false;
    try {
        new TestWebSocket();
    } catch (RuntimeException) {
        // Workerman may close before the test client reads the complete HTTP
        // rejection, so either an explicit non-101 response or EOF is valid.
        $capacityRejected = true;
    }
    check($capacityRejected, 'Total connection limit was not enforced at the handshake boundary.');

    echo "PASS: room/history isolation, anonymous identity restore, same-name separation, room-scoped sender IDs, forgery rejection, independent caps, offline recovery, authorization checks, join throttling, and capacities.\n";
} finally {
    closeAll($clients);
}

