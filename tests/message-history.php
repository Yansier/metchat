<?php

declare(strict_types=1);

require dirname(__DIR__) . '/server/src/MessageHistory.php';

use PrivateChat\MessageHistory;

function checkHistory(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = 1_700_000_000;
$history = new MessageHistory(50, 86_400, static function () use (&$now): int {
    return $now;
});

$first = $history->add('Alice', 'kept-for-24-hours', 'sender-alice');
checkHistory(strlen($first['id']) === 32, 'Server message ID is not a 128-bit hexadecimal value.');
checkHistory($first['sentAt'] === gmdate('c', $now), 'Message did not use server time.');
checkHistory($first['expiresAt'] === gmdate('c', $now + 86_400), 'Message did not expose its server expiry time.');
checkHistory($first['senderId'] === 'sender-alice', 'History did not retain the server sender ID.');

$now += 600;
checkHistory(count($history->all()) === 1, 'A ten-minute-old message was removed too early.');

$now += 85_800;
checkHistory($history->all() === [], 'A message at the 24-hour expiry boundary was returned.');

$now = 1_800_000_000;
$capped = new MessageHistory(50, 86_400, static function () use (&$now): int {
    return $now;
});

for ($index = 1; $index <= 55; ++$index) {
    $capped->add('LoadTest', sprintf('message-%02d', $index), 'sender-load');
    ++$now;
}

$messages = $capped->all();
$ids = array_column($messages, 'id');
checkHistory(count($messages) === 50, 'History did not retain exactly the latest 50 messages.');
checkHistory($messages[0]['text'] === 'message-06', 'The oldest overflow messages were not removed.');
checkHistory($messages[49]['text'] === 'message-55', 'The newest message was not retained.');
checkHistory(count(array_unique($ids)) === 50, 'Generated message IDs are not unique.');

$replyNow = 1_900_000_000;
$replies = new MessageHistory(50, 100, static function () use (&$replyNow): int {
    return $replyNow;
});
$replyTarget = $replies->add('Alice', 'reply-target', 'sender-a');
$replyNow += 50;
$reply = $replies->add('Bob', 'reply-body', 'sender-b', $replyTarget['id']);
checkHistory($reply['replyToId'] === $replyTarget['id'], 'Reply target ID was not retained.');
checkHistory($reply['expiresAt'] === gmdate('c', $replyNow + 100), 'Reply did not receive an independent expiry time.');
checkHistory($replies->find($replyTarget['id'])['text'] === 'reply-target', 'Valid reply target was not found.');
$replyNow += 51;
$remaining = $replies->all();
checkHistory(count($remaining) === 1 && $remaining[0]['id'] === $reply['id'], 'Reply did not outlive its older target independently.');
checkHistory($remaining[0]['replyToId'] === $replyTarget['id'], 'Expired target ID was removed from the valid reply.');
checkHistory($replies->find($replyTarget['id']) === null, 'Expired reply target remained readable.');

$evicted = new MessageHistory(2, 100);
$evictedTarget = $evicted->add('Alice', 'oldest', 'sender-a');
$evicted->add('Bob', 'middle', 'sender-b');
$evictedReply = $evicted->add('Carol', 'newest-reply', 'sender-c', $evictedTarget['id']);
checkHistory($evicted->find($evictedTarget['id']) === null, 'Capped reply target remained in history.');
checkHistory($evictedReply['replyToId'] === $evictedTarget['id'], 'Reply lost its reference when the target was capped.');

echo "PASS: server time, expiry, 50-message cap, ordering, unique IDs, and ID-only reply retention.\n";

