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
 * @return array{history: list<array<string, mixed>>, roomId: string, senderId: string, memberId: string, identityToken: string, identityRestored: bool, membersVersion: int, members: list<array<string, mixed>>}
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
    check(is_string($join['memberId'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $join['memberId']) === 1, "{$nickname} did not receive a member ID.");
    check(is_string($join['identityToken'] ?? null) && $join['identityToken'] !== '', "{$nickname} did not receive an identity credential.");
    check(is_bool($join['identityRestored'] ?? null), "{$nickname} did not receive identity restoration state.");
    $historyPacket = $client->expect('history');
    $client->expect('presence');
    $membersPacket = $client->expect('members');
    $history = $historyPacket['messages'] ?? null;
    check(is_array($history), "{$nickname} did not receive a history array.");
    $members = $membersPacket['members'] ?? null;
    check(is_array($members), "{$nickname} did not receive a members array.");
    check(($membersPacket['count'] ?? null) === count($members), 'Members count did not match its snapshot.');
    check(($membersPacket['selfMemberId'] ?? null) === $join['memberId'], 'Members snapshot did not identify the current connection.');

    return [
        'history' => $history,
        'roomId' => $join['roomId'],
        'senderId' => $join['senderId'],
        'memberId' => $join['memberId'],
        'identityToken' => $join['identityToken'],
        'identityRestored' => $join['identityRestored'],
        'membersVersion' => $membersPacket['version'],
        'members' => $members,
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
        $joinA = joinClient($clients[0], 'VerifyA', $roomA);
        $joinB = joinClient($clients[1], 'VerifyB', $roomB);
        $historyA = $joinA['history'];
        $historyB = $joinB['history'];
        check(!historyContainsText($historyA, $restartMarkerA), 'Room A history survived restart.');
        check(!historyContainsText($historyB, $restartMarkerB), 'Room B history survived restart.');
        check(count($joinA['members']) === 1 && count($joinB['members']) === 1, 'Member state survived Workerman restart.');
        echo "PASS: Workerman restart cleared all room histories and member state.\n";
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
$replyText = "room-a-reply-{$token}";

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
    $aliceMembers = $alice->expect('members');
    check(($aliceMembers['count'] ?? null) === 2 && count($bobJoin['members']) === 2, 'Same-room members snapshot did not contain two connections.');
    check(
        count(array_unique(array_column($aliceMembers['members'], 'memberId'))) === 2,
        'Same-name connections did not receive distinct member IDs.',
    );

    $carol = $clients[] = new TestWebSocket();
    $carolJoin = joinClient($carol, 'Admin', $roomB, $aliceJoin['identityToken']);
    check($carolJoin['roomId'] !== $aliceJoin['roomId'], 'Different room codes returned the same room ID.');
    check($carolJoin['senderId'] !== $aliceJoin['senderId'], 'One identity was linkable across rooms.');
    check($carolJoin['identityRestored'], 'A valid identity credential was not restored in another room.');
    check(count($carolJoin['members']) === 1, 'Different-room member snapshot exposed another room.');

    $aliceTwin = $clients[] = new TestWebSocket();
    $aliceTwinJoin = joinClient($aliceTwin, 'Admin', $roomA, $aliceJoin['identityToken']);
    check($aliceTwinJoin['senderId'] === $aliceJoin['senderId'], 'Same identity did not retain its room sender ID across connections.');
    check($aliceTwinJoin['memberId'] !== $aliceJoin['memberId'], 'Two connections shared one member ID.');
    foreach ([$alice, $bob] as $existingMember) {
        $existingMember->expect('join');
        $existingMember->expect('presence');
        $snapshot = $existingMember->expect('members');
        check(($snapshot['count'] ?? null) === 3, 'New same-room connection was not synchronized to existing members.');
        $sameIdentityConnections = array_filter(
            $snapshot['members'],
            static fn (array $member): bool => ($member['senderId'] ?? null) === $aliceJoin['senderId'],
        );
        check(count($sameIdentityConnections) === 2, 'Same sender identity connections were incorrectly merged.');
    }

    $alice->send(['type' => 'typing', 'active' => true]);
    $bobTyping = $bob->expect('typing');
    $twinTyping = $aliceTwin->expect('typing');
    check(($bobTyping['memberId'] ?? null) === $aliceJoin['memberId'], 'Typing state did not use the authoritative member ID.');
    check(($twinTyping['nickname'] ?? null) === 'Admin', 'Typing state lost the authoritative nickname.');
    $carol->assertNoType('typing');
    $bob->send(['type' => 'typing', 'active' => true]);
    check(($alice->expect('typing')['active'] ?? null) === true, 'Second member typing state was not broadcast.');
    check(($aliceTwin->expect('typing')['active'] ?? null) === true, 'Observer did not receive both concurrent typing states.');
    $bob->send(['type' => 'typing', 'active' => false]);
    check(($alice->expect('typing')['active'] ?? null) === false, 'Explicit typing stop was not broadcast.');
    $aliceTwin->expect('typing');
    $alice->send(['type' => 'typing', 'active' => false]);
    check(($bob->expect('typing')['active'] ?? null) === false, 'First member typing state did not clear.');
    $aliceTwin->expect('typing');

    $aliceTwin->close();
    foreach ([$alice, $bob] as $existingMember) {
        $existingMember->expect('presence');
        check(($existingMember->expect('members')['count'] ?? null) === 2, 'Closed member remained in the members snapshot.');
    }

    $alice->send(['type' => 'typing', 'active' => true]);
    check(($bob->expect('typing')['active'] ?? null) === true, 'Typing state did not start before chat.');
    $alice->send(['type' => 'chat', 'text' => $aText]);
    $aliceMessage = $alice->expect('chat');
    check(($bob->expect('typing')['active'] ?? null) === false, 'Sending chat did not clear typing state.');
    $bobMessage = $bob->expect('chat');
    check(($aliceMessage['id'] ?? null) === ($bobMessage['id'] ?? null), 'Room A clients received inconsistent IDs.');
    check(($aliceMessage['senderId'] ?? null) === $aliceJoin['senderId'], 'Sender echo did not use Alice server identity.');
    check(($bobMessage['senderId'] ?? null) === $aliceJoin['senderId'], 'Room peer saw a different sender identity.');
    $carol->assertNoType('chat');

    $bob->send(['type' => 'chat', 'text' => $replyText, 'replyToId' => $aliceMessage['id']]);
    $aliceReply = $alice->expect('chat');
    $bobReply = $bob->expect('chat');
    check(($aliceReply['replyToId'] ?? null) === $aliceMessage['id'], 'Live reply lost its target ID.');
    check(($bobReply['id'] ?? null) === ($aliceReply['id'] ?? null), 'Reply sender and peer received inconsistent messages.');

    $carol->send(['type' => 'chat', 'text' => 'cross-room-reply', 'replyToId' => $aliceMessage['id']]);
    check(($carol->expect('error')['code'] ?? null) === 'reply_target_unavailable', 'Cross-room reply target was accepted.');
    $carol->assertNoType('chat');
    $bob->send(['type' => 'chat', 'text' => 'missing-reply', 'replyToId' => str_repeat('f', 32)]);
    check(($bob->expect('error')['code'] ?? null) === 'reply_target_unavailable', 'Missing reply target was accepted.');
    $alice->assertNoType('chat');
    $bob->send(['type' => 'chat', 'text' => 'bad-reply', 'replyToId' => 'not-a-message-id']);
    check(($bob->expect('error')['code'] ?? null) === 'invalid_reply_target', 'Malformed reply target was accepted.');
    $alice->assertNoType('chat');

    $carol->send(['type' => 'chat', 'text' => $bText]);
    check(($carol->expect('chat')['text'] ?? null) === $bText, 'Room B sender echo failed.');
    $alice->assertNoType('chat');
    $bob->assertNoType('chat');

    $alice->close();
    $bob->expect('presence');
    check(($bob->expect('members')['count'] ?? null) === 1, 'Active exit did not immediately remove the member.');
    $bob->send(['type' => 'chat', 'text' => $bAfterExitText]);
    check(($bob->expect('chat')['text'] ?? null) === $bAfterExitText, 'Remaining room member could not continue chatting.');

    $aliceReloaded = $clients[] = new TestWebSocket();
    $aliceReloadedJoin = joinClient($aliceReloaded, 'Admin Renamed', $roomA, $aliceJoin['identityToken']);
    $restoredA = $aliceReloadedJoin['history'];
    check($aliceReloadedJoin['identityRestored'], 'Refresh/reconnect credential was not restored.');
    check($aliceReloadedJoin['senderId'] === $aliceJoin['senderId'], 'Changing nickname changed the room identity.');
    check(historyContainsText($restoredA, $aText), 'Room A message was not restored.');
    check(historyContainsText($restoredA, $replyText), 'Room A reply was not restored.');
    check(historyContainsText($restoredA, $bAfterExitText), 'Room A post-exit message was not restored.');
    check(!historyContainsText($restoredA, $bText), 'Room A history exposed Room B content.');
    $restoredAliceMessages = array_values(array_filter($restoredA, static fn (array $message): bool => ($message['text'] ?? null) === $aText));
    check(($restoredAliceMessages[0]['senderId'] ?? null) === $aliceJoin['senderId'], 'Restored history lost Alice message ownership.');
    $restoredReplies = array_values(array_filter($restoredA, static fn (array $message): bool => ($message['text'] ?? null) === $replyText));
    check(($restoredReplies[0]['replyToId'] ?? null) === $aliceMessage['id'], 'Restored reply lost its target ID.');

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
    $unjoined->send(['type' => 'members']);
    check(($unjoined->expect('error')['code'] ?? null) === 'unsupported_type', 'Client-triggered members request was not rejected.');
    $unjoined->send(['type' => 'typing', 'active' => true]);
    check(($unjoined->expect('error')['code'] ?? null) === 'join_required', 'Unjoined typing state was not rejected.');
    $unjoined->send(['type' => 'typing', 'active' => true, 'memberId' => str_repeat('a', 32)]);
    check(($unjoined->expect('error')['code'] ?? null) === 'invalid_packet', 'Client-forged typing member ID was accepted.');

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

    // Simulate old nicknames x/k2 and a resumed x connection overlapping the old socket.
    $diagnosticRoom = "Diagnostic_{$token}";
    $oldX = $clients[] = new TestWebSocket();
    $oldXJoin = joinClient($oldX, 'x', $diagnosticRoom);
    $k2 = $clients[] = new TestWebSocket();
    joinClient($k2, 'k2', $diagnosticRoom);
    $oldX->expect('join');
    $oldX->expect('presence');
    check(($oldX->expect('members')['count'] ?? null) === 2, 'x/k2 diagnostic room did not show two connections.');

    $resumedX = $clients[] = new TestWebSocket();
    $resumedXJoin = joinClient($resumedX, 'x', $diagnosticRoom, $oldXJoin['identityToken']);
    check($resumedXJoin['senderId'] === $oldXJoin['senderId'], 'Resumed x identity changed sender ID.');
    check($resumedXJoin['memberId'] !== $oldXJoin['memberId'], 'Resumed x connection reused the old member ID.');
    foreach ([$oldX, $k2] as $observer) {
        $observer->expect('join');
        $observer->expect('presence');
        $overlap = $observer->expect('members');
        check(($overlap['count'] ?? null) === 3, 'Old and resumed x connections were not both visible during overlap.');
    }

    $oldX->close();
    foreach ([$k2, $resumedX] as $observer) {
        $observer->expect('presence');
        check(($observer->expect('members')['count'] ?? null) === 2, 'Old x connection became a ghost member after close.');
    }
    $k2->send(['type' => 'typing', 'active' => true]);
    check(($resumedX->expect('typing')['active'] ?? null) === true, 'Typing state did not start in the diagnostic room.');
    sleep(7);
    check(($resumedX->expect('typing', 2.0)['active'] ?? null) === false, 'Typing state did not expire automatically.');
    for ($typingAttempt = 1; $typingAttempt <= 13; ++$typingAttempt) {
        $k2->send(['type' => 'typing', 'active' => true]);
    }
    check(($resumedX->expect('typing')['active'] ?? null) === true, 'Rate test did not broadcast the initial typing state.');
    check(($k2->expect('error')['code'] ?? null) === 'typing_rate_limited', 'High-frequency typing updates were not limited.');
    $k2->close();
    $resumedX->expect('presence');
    $afterK2 = $resumedX->expect('members');
    check(($afterK2['count'] ?? null) === 1 && ($afterK2['members'][0]['nickname'] ?? null) === 'x', 'k2 remained after its connection closed.');

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

    echo "PASS: room/history isolation, authoritative member snapshots, overlap cleanup, typing isolation, ID-only replies, anonymous identity, caps, authorization, throttling, and capacities.\n";
} finally {
    closeAll($clients);
}

