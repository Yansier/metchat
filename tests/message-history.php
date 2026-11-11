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

$first = $history->add('Alice', 'kept-for-24-hours');
checkHistory(strlen($first['id']) === 32, 'Server message ID is not a 128-bit hexadecimal value.');
checkHistory($first['sentAt'] === gmdate('c', $now), 'Message did not use server time.');

$now += 600;
checkHistory(count($history->all()) === 1, 'A ten-minute-old message was removed too early.');

$now += 85_800;
checkHistory($history->all() === [], 'A message at the 24-hour expiry boundary was returned.');

$now = 1_800_000_000;
$capped = new MessageHistory(50, 86_400, static function () use (&$now): int {
    return $now;
});

for ($index = 1; $index <= 55; ++$index) {
    $capped->add('LoadTest', sprintf('message-%02d', $index));
    ++$now;
}

$messages = $capped->all();
$ids = array_column($messages, 'id');
checkHistory(count($messages) === 50, 'History did not retain exactly the latest 50 messages.');
checkHistory($messages[0]['text'] === 'message-06', 'The oldest overflow messages were not removed.');
checkHistory($messages[49]['text'] === 'message-55', 'The newest message was not retained.');
checkHistory(count(array_unique($ids)) === 50, 'Generated message IDs are not unique.');

echo "PASS: server time, 10-minute retention, 24-hour expiry, 50-message cap, ordering, and unique IDs.\n";

