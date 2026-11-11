<?php

declare(strict_types=1);

namespace PrivateChat;

use Closure;
use JsonException;

/**
 * Issues stateless, process-bound anonymous identity credentials.
 *
 * The signing and sender-ID keys are random per Worker lifetime. A restart
 * therefore invalidates every previously issued credential without retaining
 * an identity registry or writing identity data to disk.
 */
final class AnonymousIdentity
{
    private const TOKEN_VERSION = 1;
    private const SUBJECT_BYTES = 32;
    private const SIGNATURE_BYTES = 32;

    /** @var Closure(): int */
    private readonly Closure $clock;
    private readonly string $signingKey;
    private readonly string $senderKey;

    /**
     * @param null|Closure(): int $clock Test-only server clock override.
     */
    public function __construct(
        private readonly int $ttlSeconds,
        private readonly int $maxTokenBytes = 512,
        ?Closure $clock = null,
        ?string $signingKey = null,
        ?string $senderKey = null,
    ) {
        if ($ttlSeconds < 86_400 || $maxTokenBytes < 128) {
            throw new \InvalidArgumentException('Identity TTL or token limit is too small.');
        }

        $this->clock = $clock ?? static fn (): int => time();
        $this->signingKey = $signingKey ?? random_bytes(32);
        $this->senderKey = $senderKey ?? random_bytes(32);

        if (strlen($this->signingKey) < 32 || strlen($this->senderKey) < 32) {
            throw new \InvalidArgumentException('Identity keys must contain at least 256 bits.');
        }
    }

    /**
     * A valid credential is renewed on every successful join. Invalid,
     * expired, or previous-process credentials safely become a new identity.
     *
     * @return array{subject: string, token: string, restored: bool, expiresAt: string}
     */
    public function resolve(?string $token): array
    {
        $now = ($this->clock)();
        $subject = $this->verify($token, $now);
        $restored = $subject !== null;
        $subject ??= random_bytes(self::SUBJECT_BYTES);
        $expiresAt = $now + $this->ttlSeconds;

        return [
            'subject' => $subject,
            'token' => $this->issue($subject, $now, $expiresAt),
            'restored' => $restored,
            'expiresAt' => gmdate('c', $expiresAt),
        ];
    }

    /**
     * Derives an unlinkable public identifier for one verified identity in one
     * room. The room key and private identity subject are never exposed.
     */
    public function senderId(string $subject, string $roomKey): string
    {
        if (strlen($subject) !== self::SUBJECT_BYTES || $roomKey === '') {
            throw new \InvalidArgumentException('Invalid identity subject or room key.');
        }

        return self::base64UrlEncode(substr(
            hash_hmac('sha256', $roomKey . "\0" . $subject, $this->senderKey, true),
            0,
            18,
        ));
    }

    private function issue(string $subject, int $issuedAt, int $expiresAt): string
    {
        $payload = json_encode([
            'v' => self::TOKEN_VERSION,
            'sub' => self::base64UrlEncode($subject),
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $encodedPayload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->signingKey, true);

        return $encodedPayload . '.' . self::base64UrlEncode($signature);
    }

    private function verify(?string $token, int $now): ?string
    {
        if ($token === null || $token === '' || strlen($token) > $this->maxTokenBytes) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$encodedPayload, $encodedSignature] = $parts;
        $signature = self::base64UrlDecode($encodedSignature);
        if ($signature === null || strlen($signature) !== self::SIGNATURE_BYTES) {
            return null;
        }

        $expected = hash_hmac('sha256', $encodedPayload, $this->signingKey, true);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($encodedPayload);
        if ($payloadJson === null || strlen($payloadJson) > 256) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            !is_array($payload)
            || array_is_list($payload)
            || count($payload) !== 4
            || ($payload['v'] ?? null) !== self::TOKEN_VERSION
            || !is_string($payload['sub'] ?? null)
            || !is_int($payload['iat'] ?? null)
            || !is_int($payload['exp'] ?? null)
        ) {
            return null;
        }

        $issuedAt = $payload['iat'];
        $expiresAt = $payload['exp'];
        if (
            $issuedAt > $now + 60
            || $expiresAt <= $now
            || $expiresAt <= $issuedAt
            || $expiresAt - $issuedAt > $this->ttlSeconds
        ) {
            return null;
        }

        $subject = self::base64UrlDecode($payload['sub']);
        return $subject !== null && strlen($subject) === self::SUBJECT_BYTES ? $subject : null;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false || self::base64UrlEncode($decoded) !== $value) {
            return null;
        }

        return $decoded;
    }
}
