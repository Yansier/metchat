<?php

declare(strict_types=1);

namespace PrivateChat;

use Closure;
use LogicException;

/** Owns process-memory room state without retaining full room codes. */
final class RoomManager
{
    public const JOINED = 'joined';
    public const ROOM_LIMIT_REACHED = 'room_limit_reached';
    public const ROOM_FULL = 'room_full';
    public const ROOM_ALREADY_EXISTS = 'room_already_exists';
    public const MODE_NORMAL = 'normal';
    public const MODE_GHOST = 'ghost';
    public const ALLOWED_TTL_SECONDS = [600, 3_600, 86_400];

    /**
     * @var array<string, array{
     *   public_id: string, instance_id: string, mode: string, ttl_seconds: int,
     *   created_at: int, empty_since: ?int, destroy_at: ?int,
     *   members: array<int, true>, history: MessageHistory
     * }>
     */
    private array $rooms = [];
    private readonly string $roomHashKey;
    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param null|Closure(): int $clock Test-only clock override. */
    public function __construct(
        private readonly int $maxRooms,
        private readonly int $maxConnectionsPerRoom,
        private readonly int $historyMaxMessages,
        private readonly int $defaultHistoryTtlSeconds,
        ?Closure $clock = null,
        ?string $roomHashKey = null,
        private readonly int $ghostGraceSeconds = 30,
    ) {
        if (
            $maxRooms < 1
            || $maxConnectionsPerRoom < 1
            || $historyMaxMessages < 1
            || $defaultHistoryTtlSeconds < 1
            || $ghostGraceSeconds < 1
        ) {
            throw new \InvalidArgumentException('Room limits, default TTL, and ghost grace are invalid.');
        }
        $this->clock = $clock ?? static fn (): int => time();
        $this->roomHashKey = $roomHashKey ?? random_bytes(32);
    }

    /**
     * Explicit create options apply only to a new room instance.
     * @param null|array{mode: string, ttlSeconds: int} $createOptions
     * @return array{status: string, roomKey?: string, roomId?: string, roomInstanceId?: string, roomMode?: string, ttlSeconds?: int, ghostGraceSeconds?: int, created?: bool}
     */
    public function join(string $roomCode, int $connectionId, ?array $createOptions = null): array
    {
        $roomKey = $this->keyForCode($roomCode);
        $now = ($this->clock)();

        // Never revive an expired ghost instance just because cleanup was delayed.
        if (isset($this->rooms[$roomKey]) && $this->shouldDestroyGhost($roomKey, $now)) {
            unset($this->rooms[$roomKey]);
        }
        if (isset($this->rooms[$roomKey]) && $createOptions !== null) {
            return ['status' => self::ROOM_ALREADY_EXISTS];
        }

        $created = false;
        if (!isset($this->rooms[$roomKey])) {
            if (count($this->rooms) >= $this->maxRooms) {
                return ['status' => self::ROOM_LIMIT_REACHED];
            }
            $mode = $createOptions['mode'] ?? self::MODE_NORMAL;
            $ttlSeconds = $createOptions['ttlSeconds'] ?? $this->defaultHistoryTtlSeconds;
            if (!in_array($mode, [self::MODE_NORMAL, self::MODE_GHOST], true)) {
                throw new \InvalidArgumentException('Invalid room mode.');
            }
            if ($createOptions !== null && !in_array($ttlSeconds, self::ALLOWED_TTL_SECONDS, true)) {
                throw new \InvalidArgumentException('Invalid room TTL.');
            }
            $this->rooms[$roomKey] = [
                'public_id' => strtoupper(substr($roomKey, 0, 8)),
                'instance_id' => bin2hex(random_bytes(16)),
                'mode' => $mode,
                'ttl_seconds' => $ttlSeconds,
                'created_at' => $now,
                'empty_since' => null,
                'destroy_at' => null,
                'members' => [],
                'history' => new MessageHistory($this->historyMaxMessages, $ttlSeconds, $this->clock),
            ];
            $created = true;
        }

        if (
            !isset($this->rooms[$roomKey]['members'][$connectionId])
            && count($this->rooms[$roomKey]['members']) >= $this->maxConnectionsPerRoom
        ) {
            return ['status' => self::ROOM_FULL];
        }
        $this->rooms[$roomKey]['members'][$connectionId] = true;
        $this->rooms[$roomKey]['empty_since'] = null;
        $this->rooms[$roomKey]['destroy_at'] = null;
        $room = $this->rooms[$roomKey];

        return [
            'status' => self::JOINED,
            'roomKey' => $roomKey,
            'roomId' => $room['public_id'],
            'roomInstanceId' => $room['instance_id'],
            'roomMode' => $room['mode'],
            'ttlSeconds' => $room['ttl_seconds'],
            'ghostGraceSeconds' => $this->ghostGraceSeconds,
            'created' => $created,
        ];
    }

    public function leave(string $roomKey, int $connectionId): void
    {
        if (!isset($this->rooms[$roomKey])) {
            return;
        }
        unset($this->rooms[$roomKey]['members'][$connectionId]);
        if ($this->rooms[$roomKey]['members'] !== []) {
            return;
        }
        if ($this->rooms[$roomKey]['mode'] === self::MODE_GHOST) {
            $now = ($this->clock)();
            $this->rooms[$roomKey]['empty_since'] = $now;
            $this->rooms[$roomKey]['destroy_at'] = $now + $this->ghostGraceSeconds;
            return;
        }
        $this->releaseNormalRoomIfEmpty($roomKey);
    }

    /** @return array{id: string, nickname: string, text: string, sentAt: string, expiresAt: string, senderId: string, replyToId?: string} */
    public function addMessage(string $roomKey, string $nickname, string $text, string $senderId, ?string $replyToId = null): array
    {
        return $this->room($roomKey)['history']->add($nickname, $text, $senderId, $replyToId);
    }

    /** @return list<array{id: string, nickname: string, text: string, sentAt: string, expiresAt: string, senderId: string, replyToId?: string}> */
    public function history(string $roomKey): array
    {
        return $this->room($roomKey)['history']->all();
    }

    /** @return null|array{id: string, nickname: string, text: string, sentAt: string, expiresAt: string, senderId: string, replyToId?: string} */
    public function message(string $roomKey, string $messageId): ?array
    {
        return $this->room($roomKey)['history']->find($messageId);
    }

    public function onlineCount(string $roomKey): int
    {
        return isset($this->rooms[$roomKey]) ? count($this->rooms[$roomKey]['members']) : 0;
    }

    public function roomCount(): int
    {
        return count($this->rooms);
    }

    /** @return array{expiredMessages: int, releasedRooms: int, destroyedGhostRooms: int} */
    public function pruneExpired(): array
    {
        $expiredMessages = 0;
        $releasedRooms = 0;
        $destroyedGhostRooms = 0;
        $now = ($this->clock)();
        foreach (array_keys($this->rooms) as $roomKey) {
            $expiredMessages += $this->rooms[$roomKey]['history']->pruneExpired();
            if ($this->shouldDestroyGhost($roomKey, $now)) {
                unset($this->rooms[$roomKey]);
                ++$destroyedGhostRooms;
                ++$releasedRooms;
                continue;
            }
            if ($this->releaseNormalRoomIfEmpty($roomKey)) {
                ++$releasedRooms;
            }
        }
        return compact('expiredMessages', 'releasedRooms', 'destroyedGhostRooms');
    }

    private function keyForCode(string $roomCode): string
    {
        return hash_hmac('sha256', $roomCode, $this->roomHashKey);
    }

    /** @return array{public_id: string, instance_id: string, mode: string, ttl_seconds: int, created_at: int, empty_since: ?int, destroy_at: ?int, members: array<int, true>, history: MessageHistory} */
    private function room(string $roomKey): array
    {
        if (!isset($this->rooms[$roomKey])) {
            throw new LogicException('Room state does not exist.');
        }
        return $this->rooms[$roomKey];
    }

    private function shouldDestroyGhost(string $roomKey, int $now): bool
    {
        if (!isset($this->rooms[$roomKey])) {
            return false;
        }
        $room = $this->rooms[$roomKey];
        return $room['mode'] === self::MODE_GHOST
            && $room['members'] === []
            && $room['destroy_at'] !== null
            && $room['destroy_at'] <= $now;
    }

    private function releaseNormalRoomIfEmpty(string $roomKey): bool
    {
        if (!isset($this->rooms[$roomKey])) {
            return false;
        }
        $room = $this->rooms[$roomKey];
        if ($room['mode'] === self::MODE_NORMAL && $room['members'] === [] && $room['history']->count() === 0) {
            unset($this->rooms[$roomKey]);
            return true;
        }
        return false;
    }
}
