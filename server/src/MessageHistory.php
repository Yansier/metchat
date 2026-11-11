<?php

declare(strict_types=1);

namespace PrivateChat;

use Closure;

/**
 * Process-memory-only message history for the single chat room.
 *
 * No persistence adapter is intentionally exposed by this class.
 */
final class MessageHistory
{
    /**
     * @var list<array{id: string, nickname: string, text: string, sentAt: string, senderId: string, replyToId?: string, expires_at: int}>
     */
    private array $messages = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param null|Closure(): int $clock Test-only clock override; production uses server time().
     */
    public function __construct(
        private readonly int $maxMessages,
        private readonly int $ttlSeconds,
        ?Closure $clock = null,
    ) {
        if ($maxMessages < 1 || $ttlSeconds < 1) {
            throw new \InvalidArgumentException('History limits must be positive integers.');
        }

        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array{id: string, nickname: string, text: string, sentAt: string, expiresAt: string, senderId: string, replyToId?: string} */
    public function add(string $nickname, string $text, string $senderId, ?string $replyToId = null): array
    {
        $now = ($this->clock)();
        $message = [
            'id' => $this->generateUniqueId(),
            'nickname' => $nickname,
            'text' => $text,
            'sentAt' => gmdate('c', $now),
            'senderId' => $senderId,
            'expires_at' => $now + $this->ttlSeconds,
        ];
        if ($replyToId !== null) {
            $message['replyToId'] = $replyToId;
        }

        $this->messages[] = $message;

        if (count($this->messages) > $this->maxMessages) {
            $this->messages = array_slice($this->messages, -$this->maxMessages);
        }

        return $this->toPublicMessage($message);
    }

    /**
     * Expired messages are filtered immediately before every history read.
     *
     * @return list<array{id: string, nickname: string, text: string, sentAt: string, expiresAt: string, senderId: string, replyToId?: string}>
     */
    public function all(): array
    {
        $this->pruneExpired();

        return array_map($this->toPublicMessage(...), $this->messages);
    }

    public function pruneExpired(): int
    {
        $before = count($this->messages);
        $now = ($this->clock)();

        $this->messages = array_values(array_filter(
            $this->messages,
            static fn (array $message): bool => $message['expires_at'] > $now,
        ));

        return $before - count($this->messages);
    }

    public function count(): int
    {
        $this->pruneExpired();

        return count($this->messages);
    }

    /** @return null|array{id: string, nickname: string, text: string, sentAt: string, expiresAt: string, senderId: string, replyToId?: string} */
    public function find(string $messageId): ?array
    {
        $this->pruneExpired();

        foreach ($this->messages as $message) {
            if ($message['id'] === $messageId) {
                return $this->toPublicMessage($message);
            }
        }

        return null;
    }

    /**
     * @param array{id: string, nickname: string, text: string, sentAt: string, senderId: string, replyToId?: string, expires_at: int} $message
     * @return array{id: string, nickname: string, text: string, sentAt: string, expiresAt: string, senderId: string, replyToId?: string}
     */
    private function toPublicMessage(array $message): array
    {
        $publicMessage = [
            'id' => $message['id'],
            'nickname' => $message['nickname'],
            'text' => $message['text'],
            'sentAt' => $message['sentAt'],
            'expiresAt' => gmdate('c', $message['expires_at']),
            'senderId' => $message['senderId'],
        ];
        if (isset($message['replyToId'])) {
            $publicMessage['replyToId'] = $message['replyToId'];
        }

        return $publicMessage;
    }

    private function generateUniqueId(): string
    {
        do {
            $id = bin2hex(random_bytes(16));
        } while (in_array($id, array_column($this->messages, 'id'), true));

        return $id;
    }
}

