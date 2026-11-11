<?php

declare(strict_types=1);

require dirname(__DIR__) . '/server/src/MessageHistory.php';
require dirname(__DIR__) . '/server/src/RoomManager.php';

use PrivateChat\RoomManager;

function checkRoom(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = 2_000_000_000;
$clock = static function () use (&$now): int {
    return $now;
};

$rooms = new RoomManager(2, 2, 2, 100, $clock, str_repeat('K', 32));
$upperA = $rooms->join('CaseRoom_1', 1);
$upperB = $rooms->join('CaseRoom_1', 2);
$lower = $rooms->join('caseroom_1', 3);

checkRoom($upperA['status'] === RoomManager::JOINED, 'First room was not created.');
checkRoom($upperA['roomKey'] === $upperB['roomKey'], 'Equal room codes did not map to the same room.');
checkRoom($upperA['roomKey'] !== $lower['roomKey'], 'Room codes were not case-sensitive.');
checkRoom(($upperA['roomId'] ?? '') !== 'CaseRoom_1', 'Public room ID exposed the full room code.');
checkRoom(!array_key_exists('roomCode', $upperA), 'Join result returned the full room code.');

$full = $rooms->join('CaseRoom_1', 4);
checkRoom($full['status'] === RoomManager::ROOM_FULL, 'Per-room connection limit was not enforced.');

$roomLimit = $rooms->join('ThirdRoom_1', 5);
checkRoom($roomLimit['status'] === RoomManager::ROOM_LIMIT_REACHED, 'Global room limit was not enforced.');

$rooms->addMessage($upperA['roomKey'], 'SharedName', 'upper-one');
$rooms->addMessage($upperA['roomKey'], 'SharedName', 'upper-two');
$rooms->addMessage($upperA['roomKey'], 'SharedName', 'upper-three');
checkRoom(
    array_column($rooms->history($upperA['roomKey']), 'text') === ['upper-two', 'upper-three'],
    'First room did not independently retain its latest two messages.',
);

$now += 50;
$rooms->addMessage($lower['roomKey'], 'SharedName', 'lower-valid');
checkRoom(
    array_column($rooms->history($lower['roomKey']), 'text') === ['lower-valid'],
    'Second room history was contaminated by the first room.',
);

$rooms->leave($upperA['roomKey'], 1);
$rooms->leave($upperA['roomKey'], 2);
$rooms->leave($lower['roomKey'], 3);
checkRoom($rooms->roomCount() === 2, 'Rooms with valid history were released when users went offline.');

$now += 51;
$cleanup = $rooms->pruneExpired();
checkRoom($cleanup['releasedRooms'] === 1, 'Expired empty room was not released.');
checkRoom($rooms->roomCount() === 1, 'Cleanup affected the room with valid history.');
checkRoom(
    array_column($rooms->history($lower['roomKey']), 'text') === ['lower-valid'],
    'Cleanup removed another room\'s valid history.',
);

echo "PASS: case-sensitive isolation, room/member limits, independent history caps, offline retention, expiry isolation, and empty-room release.\n";

