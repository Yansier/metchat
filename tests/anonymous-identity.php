<?php

declare(strict_types=1);

require dirname(__DIR__) . '/server/src/AnonymousIdentity.php';

use PrivateChat\AnonymousIdentity;

function checkIdentity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = 2_100_000_000;
$clock = static function () use (&$now): int {
    return $now;
};
$signingKey = str_repeat('S', 32);
$senderKey = str_repeat('R', 32);
$identities = new AnonymousIdentity(86_400, 512, $clock, $signingKey, $senderKey);

$first = $identities->resolve(null);
checkIdentity(!$first['restored'], 'A first join unexpectedly restored an identity.');
checkIdentity(strlen($first['subject']) === 32, 'Anonymous subject does not contain 256 random bits.');
checkIdentity(!str_contains($first['token'], $first['subject']), 'Credential exposed a raw binary subject.');

$senderA = $identities->senderId($first['subject'], 'room-key-a');
$restored = $identities->resolve($first['token']);
checkIdentity($restored['restored'], 'A valid credential did not restore its identity.');
checkIdentity(hash_equals($first['subject'], $restored['subject']), 'Restored identity subject changed.');
checkIdentity($identities->senderId($restored['subject'], 'room-key-a') === $senderA, 'Sender ID changed in the same room.');
checkIdentity($identities->senderId($restored['subject'], 'room-key-b') !== $senderA, 'Sender ID was linkable across rooms.');

$tamperedToken = substr($first['token'], 0, -1) . (str_ends_with($first['token'], 'A') ? 'B' : 'A');
$tampered = $identities->resolve($tamperedToken);
checkIdentity(!$tampered['restored'], 'A forged credential was accepted.');
checkIdentity(!hash_equals($first['subject'], $tampered['subject']), 'A forged credential impersonated the original identity.');

$now += 86_399;
$renewed = $identities->resolve($first['token']);
checkIdentity($renewed['restored'], 'Credential failed just before its expiry boundary.');
$now += 2;
$afterOriginalExpiry = $identities->resolve($renewed['token']);
checkIdentity($afterOriginalExpiry['restored'], 'Renewal did not extend a continuously active identity.');
$expiredOriginal = $identities->resolve($first['token']);
checkIdentity(!$expiredOriginal['restored'], 'An expired original credential remained valid.');

$restarted = new AnonymousIdentity(86_400, 512, $clock, str_repeat('N', 32), str_repeat('M', 32));
$afterRestart = $restarted->resolve($renewed['token']);
checkIdentity(!$afterRestart['restored'], 'A previous-process credential survived signing-key rotation.');
checkIdentity(
    $restarted->senderId($afterRestart['subject'], 'room-key-a') !== $senderA,
    'A previous-process public sender ID survived restart.',
);

echo "PASS: anonymous credential signing, forgery rejection, renewal, expiry, restart invalidation, and room-scoped sender IDs.\n";
