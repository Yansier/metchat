<?php

declare(strict_types=1);

namespace PrivateChat;

use Closure;
use LogicException;

/**
 * Owns all in-memory room state. Full room codes are converted to keyed hashes
 * and are never retained as room keys, returned to clients, or logged.
 */
final class RoomManager
{
    public const JOINED = 'joined';
    public const ROOM_LIMIT_REACHED = 'room_limit_reached';
    public const ROOM_FULL = 'room_full';

    /**
     * @var array<string, array{
     *     public_id: string,
     *     members: array<int, true>,
     *     history: MessageHistory
     * }>
     */
    private array $rooms = [];

    private readonly string $roomHashKey;

    /** @var null|Closure(): int */
    private readonly ?Closure $clock;

    /**
     * @param null|Closure(): int $clock Test-only clock override.
     */
    public function __construct(
        private readonly int $maxRooms,
        private readonly int $maxConnectionsPerRoom,
        private readonly int $historyMaxMessages,
        private readonly int $historyTtlSeconds,
        ?Closure $clock = null,
        ?string $roomHashKey = null,
    ) {
        if (
            $maxRooms < 1
            || $maxConnectionsPerRoom < 1
            || $historyMaxMessages < 1
            || $historyTtlSeconds < 1
        ) {
            throw new \InvalidArgumentException('Room and history limits must be positive integers.');
        }

        $this->clock = $clock;
        $this->roomHashKey = $roomHashKey ?? random_bytes(32);
    }

    /**
     * @return array{status: string, roomKey?: string, roomId?: string}
     */
    public function join(string $roomCode, int $connectionId): array
    {
        $roomKey = $this->keyForCode($roomCode);

        if (!isset($this->rooms[$roomKey])) {
            if (count($this->rooms) >= $this->maxRooms) {
                return ['status' => self::ROOM_LIMIT_REACHED];
            }

            $this->rooms[$roomKey] = [
                'public_id' => strtoupper(substr($roomKey, 0, 8)),
                'members' => [],
                'history' => new MessageHistory(
                    $this->historyMaxMessages,
                    $this->historyTtlSeconds,
                    $this->clock,
                ),
            ];
        }

        if (
            !isset($this->rooms[$roomKey]['members'][$connectionId])
            && count($this->rooms[$roomKey]['members']) >= $this->maxConnectionsPerRoom
        ) {
            return ['status' => self::ROOM_FULL];
        }

        $this->rooms[$roomKey]['members'][$connectionId] = true;

        return [
            'status' => self::JOINED,
            'roomKey' => $roomKey,
            'roomId' => $this->rooms[$roomKey]['public_id'],
        ];
    }

    public function leave(string $roomKey, int $connectionId): void
    {
        if (!isset($this->rooms[$roomKey])) {
            return;
        }

        unset($this->rooms[$roomKey]['members'][$connectionId]);
        $this->releaseRoomIfEmpty($roomKey);
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

    /** @return array{expiredMessages: int, releasedRooms: int} */
    public function pruneExpired(): array
    {
        $expiredMessages = 0;
        $releasedRooms = 0;

        foreach (array_keys($this->rooms) as $roomKey) {
            $expiredMessages += $this->rooms[$roomKey]['history']->pruneExpired();
            if ($this->releaseRoomIfEmpty($roomKey)) {
                ++$releasedRooms;
            }
        }

        return [
            'expiredMessages' => $expiredMessages,
            'releasedRooms' => $releasedRooms,
        ];
    }

    private function keyForCode(string $roomCode): string
    {
        return hash_hmac('sha256', $roomCode, $this->roomHashKey);
    }

    /**
     * @return array{public_id: string, members: array<int, true>, history: MessageHistory}
     */
    private function room(string $roomKey): array
    {
        if (!isset($this->rooms[$roomKey])) {
            throw new LogicException('Room state does not exist.');
        }

        return $this->rooms[$roomKey];
    }

    private function releaseRoomIfEmpty(string $roomKey): bool
    {
        if (!isset($this->rooms[$roomKey])) {
            return false;
        }

        if ($this->rooms[$roomKey]['members'] === [] && $this->rooms[$roomKey]['history']->count() === 0) {
            unset($this->rooms[$roomKey]);
            return true;
        }

        return false;
    }
}

