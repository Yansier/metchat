<?php

declare(strict_types=1);

final class SecuritySocket
{
    /** @var resource|null */
    private $stream;
    private string $buffer = '';
    public readonly int $status;

    public function __construct(?string $origin = null, ?string $xRealIp = null)
    {
        $stream = stream_socket_client('tcp://127.0.0.1:2346', $code, $message, 2);
        if ($stream === false) {
            throw new RuntimeException("Connection failed ({$code}): {$message}");
        }
        $this->stream = $stream;
        stream_set_timeout($stream, 0, 300_000);
        $key = base64_encode(random_bytes(16));
        $request = "GET / HTTP/1.1\r\nHost: 127.0.0.1:2346\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n";
        if ($origin !== null) {
            $request .= "Origin: {$origin}\r\n";
        }
        if ($xRealIp !== null) {
            $request .= "X-Real-IP: {$xRealIp}\r\n";
        }
        $this->writeAll($request . "\r\n");
        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($stream, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        preg_match('/\AHTTP\/1\.1\s+(\d{3})/', $response, $match);
        $this->status = isset($match[1]) ? (int) $match[1] : 0;
        if (str_contains($response, "\r\n\r\n")) {
            [, $this->buffer] = explode("\r\n\r\n", $response, 2);
        }
    }

    /** @param array<string, mixed> $packet */
    public function send(array $packet): void
    {
        $this->sendPayload(json_encode($packet, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function sendPayload(string $payload): void
    {
        $this->sendFrame($payload, 0x1);
    }

    public function sendFrame(string $payload, int $opcode): void
    {
        if (!is_resource($this->stream)) {
            return;
        }
        $length = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0f));
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            throw new RuntimeException('Security test payload is unexpectedly large.');
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($index = 0; $index < $length; ++$index) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }
        $this->writeAll($header . $mask . $masked);
    }

    /** @return array<string, mixed>|null */
    public function receive(float $timeout = 1.0): ?array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $header = $this->readBytes(2);
            if ($header === null) {
                continue;
            }
            $opcode = ord($header[0]) & 0x0f;
            $length = ord($header[1]) & 0x7f;
            if ($length === 126) {
                $extended = $this->readBytes(2);
                if ($extended === null) return null;
                $length = unpack('nvalue', $extended)['value'];
            } elseif ($length === 127) {
                return null;
            }
            $payload = $length ? $this->readBytes($length) : '';
            if ($payload === null || $opcode === 0x8) return null;
            if ($opcode !== 0x1) continue;
            $packet = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
            return is_array($packet) ? $packet : null;
        }
        return null;
    }

    /** @return array<string, mixed> */
    public function expect(string $type, float $timeout = 2.0): array
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $packet = $this->receive(max(0.1, $deadline - microtime(true)));
            if (($packet['type'] ?? null) === $type) return $packet;
        }
        throw new RuntimeException("Expected {$type} packet was not received.");
    }

    public function close(): void
    {
        if (is_resource($this->stream)) fclose($this->stream);
        $this->stream = null;
    }

    private function readBytes(int $length): ?string
    {
        if (!is_resource($this->stream)) return null;
        while (strlen($this->buffer) < $length) {
            $chunk = fread($this->stream, max(1, $length - strlen($this->buffer)));
            if ($chunk === false || $chunk === '') return null;
            $this->buffer .= $chunk;
        }
        $result = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        return $result;
    }

    private function writeAll(string $data): void
    {
        if (!is_resource($this->stream)) throw new RuntimeException('Socket is closed.');
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = @fwrite($this->stream, substr($data, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('Socket write failed.');
            $offset += $written;
        }
    }
}

function securityCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} */
function securityJoin(SecuritySocket $client, string $nickname, string $room = 'SecurityRoom_01'): array
{
    $client->send(['type' => 'join', 'nickname' => $nickname, 'roomCode' => $room]);
    return [$client->expect('join'), $client->expect('history'), $client->expect('presence'), $client->expect('members')];
}

$mode = $argv[1] ?? 'protocol';
if ($mode === 'origin') {
    $allowed = new SecuritySocket('https://chat.example.test', '198.51.100.10');
    $invalid = new SecuritySocket('https://chat.example.test.evil.invalid', '198.51.100.11');
    $missing = new SecuritySocket(null, '198.51.100.12');
    $noProxyIp = new SecuritySocket('https://chat.example.test');
    securityCheck($allowed->status === 101, 'Allowed production Origin failed.');
    securityCheck($invalid->status === 403, 'Invalid Origin was not rejected.');
    securityCheck($missing->status === 403, 'Missing production Origin was not rejected.');
    securityCheck($noProxyIp->status === 403, 'Trusted proxy without X-Real-IP was accepted.');
    $allowed->close(); $invalid->close(); $missing->close(); $noProxyIp->close();
    echo "PASS: production handshake enforces exact Origin and trusted-proxy client IP.\n";
    exit(0);
}

if ($mode === 'connection-limit') {
    $first = new SecuritySocket();
    securityCheck($first->status === 101, 'First connection failed.');
    $first->close(); usleep(100_000);
    $second = new SecuritySocket();
    securityCheck($second->status === 101, 'Second connection failed.');
    $second->close(); usleep(100_000);
    $third = new SecuritySocket();
    securityCheck($third->status === 429, 'Reconnect reset the per-IP connection window.');
    $third->close();
    echo "PASS: per-IP connection attempts persist across reconnects.\n";
    exit(0);
}

if ($mode === 'lifecycle') {
    $silent = new SecuritySocket();
    $keeper = new SecuritySocket();
    securityCheck($silent->status === 101 && $keeper->status === 101, 'Lifecycle clients failed to connect.');
    securityJoin($silent, 'Silent');
    securityJoin($keeper, 'Keeper');
    $silent->expect('join'); $silent->expect('presence'); $silent->expect('members');
    $sawLeave = false;
    $sawMembers = false;
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline && (!$sawLeave || !$sawMembers)) {
        $keeper->send(['type' => 'ping', 'ts' => 1]);
        $packet = $keeper->receive(0.6);
        if (($packet['type'] ?? null) === 'presence' && ($packet['event'] ?? null) === 'leave' && ($packet['count'] ?? null) === 1) {
            $sawLeave = true;
        }
        if (($packet['type'] ?? null) === 'members' && ($packet['count'] ?? null) === 1) {
            $sawMembers = true;
        }
        usleep(250_000);
    }
    securityCheck($sawLeave, 'Heartbeat timeout did not release the silent room member.');
    securityCheck($sawMembers, 'Heartbeat timeout did not publish a corrected members snapshot.');
    $keeper->close(); $silent->close();
    echo "PASS: heartbeat timeout releases membership and broadcasts corrected presence and members.\n";
    exit(0);
}

if ($mode === 'bounded-load') {
    $clients = [];
    try {
        for ($index = 0; $index < 12; ++$index) {
            $client = new SecuritySocket();
            securityCheck($client->status === 101, "Load client {$index} failed to connect.");
            securityJoin($client, "Load{$index}", 'BoundedLoadRoom');
            $clients[] = $client;
        }
        for ($message = 0; $message < 120; ++$message) {
            $clients[$message % count($clients)]->send(['type' => 'chat', 'text' => "bounded-{$message}"]);
            foreach ($clients as $client) {
                $packet = $client->expect('chat', 3.0);
                securityCheck(($packet['text'] ?? null) === "bounded-{$message}", "Load message {$message} diverged.");
            }
        }
        echo "PASS: bounded load delivered 120 messages consistently to 12 clients (1,440 chat deliveries).\n";
    } finally {
        foreach ($clients as $client) $client->close();
    }
    exit(0);
}

if ($mode !== 'protocol') throw new InvalidArgumentException('Unknown security integration mode.');

$client = new SecuritySocket();
securityCheck($client->status === 101, 'Protocol client failed to connect.');
$client->send(['type' => 'chat', 'text' => 'not joined']);
securityCheck(($client->expect('error')['code'] ?? null) === 'join_required', 'Unjoined chat was accepted.');
$client->sendPayload('{broken json');
securityCheck(($client->expect('error')['code'] ?? null) === 'invalid_json', 'Invalid JSON was not rejected.');
$client->sendPayload("{\"type\":\"chat\",\"text\":\"\xff\"}");
securityCheck(($client->expect('error')['code'] ?? null) === 'invalid_json', 'Invalid UTF-8 was not rejected.');
$client->send(['type' => 'ping', 'ts' => 7]);
securityCheck(($client->expect('pong')['ts'] ?? null) === 7, 'Worker did not survive invalid protocol input.');
securityJoin($client, 'Security');
$client->send(['type' => 'chat', 'text' => 'spoof', 'id' => 'client-id']);
securityCheck(($client->expect('error')['code'] ?? null) === 'invalid_packet', 'Client-controlled message metadata was accepted.');
$client->send(['type' => 'chat', 'text' => 'one']);
$client->expect('chat');
$client->send(['type' => 'chat', 'text' => 'two']);
$client->expect('chat');
$client->send(['type' => 'chat', 'text' => 'three']);
securityCheck(($client->expect('error')['code'] ?? null) === 'chat_rate_limited', 'High-frequency chat was not limited.');
$client->sendPayload(str_repeat('x', 17_000));
securityCheck($client->receive(0.5) === null, 'Oversized WebSocket payload was not closed.');
$client->close();
$invalidOpcode = new SecuritySocket();
securityCheck($invalidOpcode->status === 101, 'Invalid-opcode test could not connect.');
$invalidOpcode->sendFrame('private-frame-must-not-be-logged', 0x3);
securityCheck($invalidOpcode->receive(0.5) === null, 'Invalid WebSocket opcode was not closed.');
$invalidOpcode->close();
$probe = new SecuritySocket();
securityCheck($probe->status === 101, 'Worker stopped after oversized input.');
$probe->send(['type' => 'ping']);
securityCheck(($probe->expect('pong')['type'] ?? null) === 'pong', 'Worker did not answer after oversized input.');
$probe->close();
echo "PASS: authorization, strict packet schema, malformed JSON/UTF-8 survival, chat throttling, and pre-application package limit.\n";
