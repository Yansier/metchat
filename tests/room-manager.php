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

$rooms->addMessage($upperA['roomKey'], 'SharedName', 'upper-one', 'sender-upper');
$rooms->addMessage($upperA['roomKey'], 'SharedName', 'upper-two', 'sender-upper');
$rooms->addMessage($upperA['roomKey'], 'SharedName', 'upper-three', 'sender-upper');
checkRoom(
    array_column($rooms->history($upperA['roomKey']), 'text') === ['upper-two', 'upper-three'],
    'First room did not independently retain its latest two messages.',
);
$upperHistory = $rooms->history($upperA['roomKey']);
checkRoom(
    $rooms->message($upperA['roomKey'], $upperHistory[1]['id'])['text'] === 'upper-three',
    'Room-scoped message lookup did not find a valid reply target.',
);

$now += 50;
$rooms->addMessage($lower['roomKey'], 'SharedName', 'lower-valid', 'sender-lower');
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

$lifecycleNow = 2_100_000_000;
$lifecycleClock = static function () use (&$lifecycleNow): int {
    return $lifecycleNow;
};
$lifecycle = new RoomManager(5, 3, 50, 86_400, $lifecycleClock, str_repeat('G', 32), 30);

$creationRequestId = str_repeat('a', 32);
$normal = $lifecycle->join('NormalRoom_1', 10, ['mode' => 'normal', 'ttlSeconds' => 600, 'requestId' => $creationRequestId]);
checkRoom($normal['status'] === RoomManager::JOINED && $normal['created'] === true, 'Explicit normal room creation failed.');
checkRoom($normal['roomMode'] === 'normal' && $normal['ttlSeconds'] === 600, 'Normal room configuration was not authoritative.');
checkRoom(preg_match('/\A[a-f0-9]{32}\z/D', $normal['roomInstanceId']) === 1, 'Room instance ID format is invalid.');
$normalConflict = $lifecycle->join('NormalRoom_1', 11, ['mode' => 'ghost', 'ttlSeconds' => 3_600]);
checkRoom($normalConflict['status'] === RoomManager::ROOM_ALREADY_EXISTS, 'Existing room settings were overwritten by explicit creation.');
$normalRecovered = $lifecycle->join('NormalRoom_1', 12, ['mode' => 'normal', 'ttlSeconds' => 600, 'requestId' => $creationRequestId]);
checkRoom($normalRecovered['status'] === RoomManager::JOINED, 'Same creation request could not recover after a lost acknowledgement.');
checkRoom($normalRecovered['roomInstanceId'] === $normal['roomInstanceId'], 'Creation recovery produced a different room instance.');
$normalJoin = $lifecycle->join('NormalRoom_1', 11);
checkRoom($normalJoin['roomInstanceId'] === $normal['roomInstanceId'], 'Ordinary join did not use the existing instance.');
checkRoom($normalJoin['roomMode'] === 'normal' && $normalJoin['ttlSeconds'] === 600, 'Ordinary join changed room settings.');
$normalMessage = $lifecycle->addMessage($normal['roomKey'], 'Normal', 'ten-minute-message', 'normal-sender');
checkRoom($normalMessage['expiresAt'] === gmdate('c', $lifecycleNow + 600), 'Per-room TTL was not applied to a message.');
$lifecycle->leave($normal['roomKey'], 10);
$lifecycle->leave($normal['roomKey'], 11);
$lifecycle->leave($normal['roomKey'], 12);
checkRoom(count($lifecycle->history($normal['roomKey'])) === 1, 'Normal room history did not survive all members leaving.');

$ghost = $lifecycle->join('GhostRoom_01', 20, ['mode' => 'ghost', 'ttlSeconds' => 3_600]);
$lifecycle->addMessage($ghost['roomKey'], 'Ghost', 'ghost-history', 'ghost-sender');
$lifecycleNow += 100;
$lifecycle->pruneExpired();
checkRoom($lifecycle->roomCount() === 2, 'Active ghost room was destroyed.');
$lifecycle->leave($ghost['roomKey'], 20);
$lifecycleNow += 29;
$ghostReturn = $lifecycle->join('GhostRoom_01', 21);
checkRoom($ghostReturn['roomInstanceId'] === $ghost['roomInstanceId'], 'Ghost room was destroyed before grace elapsed.');
checkRoom(array_column($lifecycle->history($ghost['roomKey']), 'text') === ['ghost-history'], 'Ghost history was lost during grace.');
$lifecycle->leave($ghost['roomKey'], 21);
$lifecycleNow += 30;
$ghostRecreated = $lifecycle->join('GhostRoom_01', 22);
checkRoom($ghostRecreated['roomInstanceId'] !== $ghost['roomInstanceId'], 'Expired ghost instance was revived at the join boundary.');
checkRoom($ghostRecreated['roomMode'] === 'normal' && $ghostRecreated['ttlSeconds'] === 86_400, 'Legacy join did not recreate an expired ghost as the default room.');
checkRoom($lifecycle->history($ghostRecreated['roomKey']) === [], 'Recreated room exposed destroyed ghost history.');

$ghostAgain = $lifecycle->join('GhostRoom_02', 30, ['mode' => 'ghost', 'ttlSeconds' => 600]);
$lifecycle->leave($ghostAgain['roomKey'], 30);
$lifecycleNow += 30;
$ghostCleanup = $lifecycle->pruneExpired();
checkRoom($ghostCleanup['destroyedGhostRooms'] === 1, 'Periodic cleanup did not destroy an empty ghost room.');

echo "PASS: isolation, limits, per-room TTL, immutable configuration, normal retention, ghost grace, boundary destruction, and instance renewal.\n";

