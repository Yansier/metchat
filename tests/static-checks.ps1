$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$serverSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/ChatRoom.php')
$historySource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/MessageHistory.php')
$roomSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/RoomManager.php')
$originSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/OriginPolicy.php')
$ipResolverSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/ClientIpResolver.php')
$ipLimiterSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/IpRateLimiter.php')
$privacyProtocolSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/PrivacyWebsocket.php')
$identitySource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/src/AnonymousIdentity.php')
$serverConfig = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/config/chat.php')
$serverEntry = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'server/bin/chat-server.php')
$clientSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'public/app.js')
$roomCodeSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'public/room-code.js')
$htmlSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'public/index.html')
$styleSource = Get-Content -Raw -LiteralPath (Join-Path $projectRoot 'public/style.css')

$failures = [System.Collections.Generic.List[string]]::new()

function Assert-Source {
    param(
        [bool]$Condition,
        [string]$Message
    )

    if (-not $Condition) {
        $failures.Add($Message)
    }
}

Assert-Source ($serverSource -match "'type' => 'join'") 'Server does not implement join.'
Assert-Source ($serverSource -match "'type' => 'chat'") 'Server does not implement chat.'
Assert-Source ($serverSource -match "'type' => 'history'") 'Server does not implement history.'
Assert-Source ($serverSource -match "'type' => 'presence'") 'Server does not implement presence.'
Assert-Source ($serverSource -match "'type' => 'members'") 'Server does not implement authoritative members snapshots.'
Assert-Source ($serverSource -match "case 'typing'") 'Server does not implement typing packets.'
Assert-Source ($serverSource -match "'type' => 'error'") 'Server does not implement error.'
Assert-Source ($serverSource -match "'type' => 'pong'") 'Server does not implement pong.'
Assert-Source (($serverSource + $historySource + $roomSource + $identitySource) -notmatch '(?i)file_put_contents|fopen|PDO|SQLite|Redis|error_log') 'Potential persistence, database, or body logging API found.'
Assert-Source ($serverConfig -match '''history_cleanup_interval''\s*=>\s*\$envInt\(''CHAT_HISTORY_CLEANUP_INTERVAL'', 60\)') 'History cleanup default is not 60 seconds.'
Assert-Source ($serverConfig -match '''history_ttl_seconds''\s*=>\s*\$envInt\(''CHAT_HISTORY_TTL_SECONDS'', 86_400\)') 'History TTL default is not 24 hours.'
Assert-Source ($serverConfig -match "'history_max_messages'\s*=>\s*50") 'History cap is not 50 messages.'
Assert-Source ($serverConfig -match "'typing_ttl_seconds'\s*=>\s*5") 'Typing state TTL is not five seconds.'
Assert-Source ($serverConfig -match "'typing_cleanup_interval'\s*=>\s*1") 'Typing cleanup interval is missing.'
Assert-Source ($serverConfig -match "'worker_count'\s*=>\s*1") 'Worker count is not fixed at one.'
Assert-Source ($serverConfig -match "'max_rooms'\s*=>\s*100") 'Global room limit is missing.'
Assert-Source ($serverConfig -match "'max_total_connections'\s*=>\s*64") 'Total connection limit is missing.'
Assert-Source ($serverConfig -match "'max_connections_per_room'\s*=>\s*16") 'Per-room connection limit is missing.'
Assert-Source ($serverConfig -match "'max_join_attempts_per_connection'\s*=>\s*5") 'Join-attempt limit is missing.'
Assert-Source ($serverEntry -match "history_cleanup_interval") 'History cleanup timer is not registered.'
Assert-Source ($serverEntry -match "onWebSocketConnected") 'Post-handshake capacity rejection is not registered.'
Assert-Source ($serverEntry -match "onWebSocketConnect") 'Pre-handshake Origin validation is not registered.'
Assert-Source ($serverEntry -match "onBufferFull") 'Slow-client send-buffer handling is not registered.'
Assert-Source ($serverEntry -match "PrivacyWebsocket::class") 'Privacy-preserving WebSocket protocol wrapper is not active.'
Assert-Source ($serverSource -match 'maxPackageSize') 'Workerman maxPackageSize is not configured per connection.'
Assert-Source ($serverSource -match 'unjoinedTimeout') 'Unjoined connection timeout is missing.'
Assert-Source ($originSource -match "str_contains\(\`$origin, '\*'\)") 'Origin policy does not reject wildcard configuration/input.'
Assert-Source ($originSource -match 'isset\(\$this->allowed\[\$normalized\]\)') 'Origin policy is not using exact membership matching.'
Assert-Source ($ipResolverSource -match 'trustedProxies') 'Trusted proxy enforcement is missing.'
Assert-Source ($ipResolverSource -notmatch 'x-forwarded-for') 'X-Forwarded-For should not be trusted by the application.'
Assert-Source ($ipLimiterSource -match 'maxTrackedIps') 'IP limiter state has no global bound.'
Assert-Source ($privacyProtocolSource -notmatch 'bin2hex|\$buffer.*safeEcho') 'Privacy WebSocket protocol may log raw frames.'
Assert-Source ($identitySource -match "hash_hmac\('sha256'") 'Anonymous credentials are not HMAC signed.'
Assert-Source ($identitySource -match 'hash_equals') 'Anonymous credential signatures are not timing-safe compared.'
Assert-Source ($identitySource -match 'random_bytes\(self::SUBJECT_BYTES\)') 'Anonymous identity subjects are not securely generated.'
Assert-Source ($identitySource -match 'senderId') 'Room-scoped sender ID derivation is missing.'
Assert-Source ($serverSource -match "\['type', 'nickname', 'roomCode', 'identityToken'\]") 'Join does not strictly allow the optional identity credential.'
Assert-Source ($serverSource -match '''identityToken'' => \$identity\[''token''\]') 'Join acknowledgement does not return the renewed identity credential.'
Assert-Source ($serverSource -match '''senderId'' => \$senderId') 'Join acknowledgement does not return the room-scoped sender ID.'
Assert-Source ($serverSource -match '''memberId'' => \$this->clients\[\$connection->id\]\[''member_id''\]') 'Join acknowledgement does not return a server member ID.'
Assert-Source ($serverSource -match 'bin2hex\(random_bytes\(16\)\)') 'Member IDs are not generated with 128 random bits.'
Assert-Source ($serverSource -match 'sendMembersSnapshot') 'Membership changes do not publish snapshots.'
Assert-Source ($serverSource -match '''selfMemberId''') 'Members snapshot does not identify the current connection.'
Assert-Source ($serverSource -match "\['type', 'text', 'replyToId', 'requestId'\]") 'Chat does not strictly allow the optional reply target and request ID.'
Assert-Source ($serverSource -match 'reply_target_unavailable') 'Unavailable reply targets are not rejected.'
Assert-Source ($serverSource -match "'type' => 'chat_result'") 'Server does not return correlated chat results.'
Assert-Source ($serverSource -match '''messageId'' => \$message\[''id''\]') 'Successful chat results do not identify the authoritative message.'
Assert-Source ($serverSource -notmatch "array_merge\(\['type' => 'chat'.*requestId") 'Private request IDs may leak into room broadcasts.'
Assert-Source ($serverSource -match 'clearTyping\(\$connection->id, true\)') 'Sending chat does not clear typing state.'
Assert-Source ($historySource -match '''replyToId''') 'History does not retain the reply target ID.'
Assert-Source ($historySource -match 'function find') 'History cannot validate a room-local reply target.'
Assert-Source ($historySource -match '''senderId'' => \$message\[''senderId''\]') 'History does not return the authoritative sender ID.'
Assert-Source ($historySource -match '''expiresAt'' => gmdate') 'History does not expose server-authoritative expiry metadata.'
Assert-Source ($historySource -match 'bin2hex\(random_bytes\(16\)\)') 'History does not generate server-side unique IDs.'
Assert-Source ($historySource -match 'generateUniqueId') 'History does not guard against in-memory ID collisions.'
Assert-Source ($roomSource -match "hash_hmac\('sha256'") 'Room codes are not converted to keyed internal hashes.'
Assert-Source ($roomSource -match 'releaseRoomIfEmpty') 'Empty room state is not released.'
Assert-Source ($serverSource -match 'broadcastToRoom') 'Server does not use room-scoped broadcasting.'
Assert-Source ($serverSource -match "preg_match\('/\\A\[A-Za-z0-9_-\]\+\\z/D'") 'Server room-code character validation is missing.'
Assert-Source ($clientSource -notmatch '(?i)localStorage|indexedDB|document\.cookie') 'Forbidden browser persistence API found in client.'
Assert-Source (([regex]::Matches($clientSource, 'window\.sessionStorage\.(getItem|setItem|removeItem)')).Count -eq 3) 'sessionStorage use is not limited to one credential lifecycle helper.'
Assert-Source ($clientSource -match 'metchat\.anonymousIdentityToken') 'Anonymous credential storage key is missing.'
Assert-Source ($clientSource -notmatch 'sessionStorage.*(roomCode|message|history)') 'Sensitive room or message data may be written to sessionStorage.'
Assert-Source ($clientSource -notmatch 'console\.(log|info|debug|warn|error)') 'Console logging found in client.'
Assert-Source ($clientSource -notmatch '\.innerHTML\s*=') 'Unsafe innerHTML rendering found in client.'
Assert-Source ($clientSource -match 'body\.textContent = packet\.text') 'Chat body is not rendered with textContent.'
Assert-Source ($clientSource -match 'seenMessageIds') 'Client does not de-duplicate by server message ID.'
Assert-Source ($clientSource -match 'MAX_RECONNECT_ATTEMPTS = 3') 'Automatic reconnect is not bounded.'
Assert-Source ($clientSource -match 'const joinPacket = \{ type: "join", nickname, roomCode \}') 'Client join packet does not include the room code.'
Assert-Source ($clientSource -match 'joinPacket\.identityToken = identityToken') 'Client does not attach its server-issued identity credential.'
Assert-Source ($clientSource -notmatch '(?i)searchParams|location\.search|document\.cookie') 'Client may expose or persist the room code through URL/cookies.'
Assert-Source ($clientSource -notmatch 'packet\.nickname\s*===\s*nickname') 'Client still treats equal nicknames as a reliable user identity.'
Assert-Source ($clientSource -match 'packet\.senderId === currentSenderId') 'Message ownership is not based on the authoritative sender ID.'
Assert-Source ($clientSource -match 'function applyMembers') 'Client does not apply authoritative members snapshots.'
Assert-Source ($clientSource -match 'packet\.version <= membersVersion') 'Client does not reject stale members snapshots.'
Assert-Source ($clientSource -match 'TYPING_REFRESH_INTERVAL_MS = 3_000') 'Client typing refresh is not throttled.'
Assert-Source ($clientSource -match 'REMOTE_TYPING_FALLBACK_MS = 5_500') 'Client typing state has no bounded fallback expiry.'
Assert-Source ($clientSource -match 'chatPacket\.replyToId = replyTargetId') 'Client does not submit the selected reply target.'
Assert-Source ($clientSource -match 'referencedText\.textContent') 'Reply previews are not rendered with safe textContent.'
Assert-Source ($clientSource -match 'MAX_RENDERED_MESSAGES = 50') 'Rendered messages are not bounded to the server history cap.'
Assert-Source ($clientSource -match 'populateReplyContext\(replyContext') 'Reply summaries are not refreshed after target eviction.'
Assert-Source ($clientSource -match 'CHAT_CONFIRM_TIMEOUT_MS') 'Client chat confirmation timeout is missing.'
Assert-Source ($clientSource -match 'crypto\.getRandomValues\(bytes\)') 'Client request IDs are not generated with Web Crypto.'
Assert-Source ($clientSource -match 'pendingChat') 'Client does not retain a pending chat request.'
Assert-Source ($clientSource -match 'handleChatResult') 'Client does not process correlated chat results.'
Assert-Source ($clientSource -match 'messageInput\.value === completed\.draftValue') 'Confirmed sends can clear a subsequently edited draft.'
Assert-Source ($clientSource -match 'markPendingChatUnknown') 'Unknown send outcomes are not handled without retrying.'
Assert-Source ($clientSource -match 'hadPendingChat && reconnectScheduled') 'Reconnect status can overwrite an unknown chat outcome.'
Assert-Source ($clientSource -match 'if \(unknownSendOutcome\)') 'A restored connection can hide an unknown chat outcome.'
Assert-Source ($clientSource -notmatch 'send\(pendingChat') 'Pending chat requests may be automatically retried.'
Assert-Source ($clientSource -match 'MESSAGE_EXPIRY_CHECK_INTERVAL_MS = 60_000') 'Client has no periodic message lifecycle sweep.'
Assert-Source ($clientSource -match 'estimatedServerNow') 'Client expiry does not use a server-time anchor.'
Assert-Source ($clientSource -match 'pruneExpiredRenderedMessages\(\)') 'Client does not prune expired rendered messages.'
Assert-Source ($clientSource -match 'seenMessageIds\.delete\(messageId\)') 'Expiry cleanup does not synchronize the de-duplication set.'
Assert-Source ($clientSource -match 'messagePackets\.delete\(messageId\)') 'Expiry cleanup does not synchronize message packets.'
Assert-Source ($clientSource -match 'messageElements\.delete\(messageId\)') 'Expiry cleanup does not synchronize message elements.'
Assert-Source ($clientSource -match 'clearIdentityToken\(\)') 'Active room exit does not clear the temporary credential.'
Assert-Source ($clientSource -match 'leaveButton\.addEventListener') 'Active room exit behavior is missing.'
Assert-Source ($clientSource -match 'suppressReconnect = true') 'Active room exit does not suppress reconnect.'
Assert-Source ($clientSource -match 'latestButton\.addEventListener') 'Back-to-latest interaction is missing.'
Assert-Source ($clientSource -match 'window\.visualViewport') 'Visual Viewport handling is missing.'
Assert-Source ($clientSource -match 'messageInput\.focus\(\{ preventScroll: true \}\)') 'Composer focus preservation is missing.'
Assert-Source ($htmlSource -match 'type="password"') 'Room code input is not masked.'
Assert-Source ($htmlSource -match 'id="generateRoomCodeButton"') 'Secure room-code generation control is missing.'
Assert-Source ($htmlSource -match 'id="copyRoomCodeButton"') 'Room-code copy control is missing.'
Assert-Source ($htmlSource -match 'id="toggleRoomCodeButton"') 'Room-code visibility control is missing.'
Assert-Source ($htmlSource -match 'id="leaveButton"') 'Room exit control is missing.'
Assert-Source ($htmlSource -match 'id="membersPanel"') 'Members panel is missing.'
Assert-Source ($htmlSource -match 'id="typingIndicator"') 'Typing indicator is missing.'
Assert-Source ($htmlSource -match 'id="replyPreview"') 'Reply composer preview is missing.'
Assert-Source ($htmlSource -match 'id="historyLoading"') 'History loading state is missing.'
Assert-Source ($htmlSource -match 'id="latestButton"') 'Back-to-latest control is missing.'
Assert-Source ($htmlSource -match 'id="messageCount"') 'Message length indicator is missing.'
Assert-Source ($htmlSource -match "style-src 'self'; script-src 'self'") 'Content Security Policy allows non-self styles or scripts.'
Assert-Source ($htmlSource -notmatch "'unsafe-inline'") 'Content Security Policy permits unsafe inline content.'
Assert-Source ($clientSource -notmatch 'messageInput\.style') 'Composer resizing depends on an inline style that conflicts with CSP.'
Assert-Source ($styleSource -match 'env\(safe-area-inset-bottom\)') 'Mobile safe-area bottom handling is missing.'
Assert-Source ($styleSource -match '@media \(max-width: 760px\)') 'Mobile layout breakpoint is missing.'
Assert-Source ($styleSource -match 'overflow-x:\s*hidden') 'Horizontal overflow protection is missing.'
Assert-Source ($styleSource -match 'font-size:\s*16px') 'Mobile input zoom protection is missing.'
Assert-Source ($styleSource -match 'prefers-reduced-motion') 'Reduced-motion fallback is missing.'
Assert-Source ($styleSource -match '\.message--own') 'Own-message alignment style is missing.'
Assert-Source ($styleSource -match '\.message--neutral') 'Neutral fallback message style is missing.'
Assert-Source ($styleSource -match '\.members-panel') 'Members panel styles are missing.'
Assert-Source ($styleSource -match '\.typing-indicator') 'Typing indicator styles are missing.'
Assert-Source ($styleSource -match '\.reply-preview') 'Reply preview styles are missing.'
Assert-Source ($roomCodeSource -match 'crypto\.getRandomValues') 'Room codes do not use Web Crypto.'
Assert-Source ($roomCodeSource -notmatch 'Math\.random') 'Room codes use Math.random.'
Assert-Source ($roomCodeSource -match 'DEFAULT_LENGTH = 24') 'Random room-code default length is not 24.'
Assert-Source ($roomCodeSource -match '256 - \(256 % ALPHABET\.length\)') 'Room-code generation does not avoid modulo bias.'
Assert-Source (Test-Path -LiteralPath (Join-Path $projectRoot 'deploy/nginx-private-chat.conf')) 'Nginx configuration is missing.'
Assert-Source (-not (Test-Path -LiteralPath (Join-Path $projectRoot 'public/server'))) 'Server files are inside the public directory.'

if ($failures.Count -gt 0) {
    $failures | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Host 'PASS: room isolation, HMAC identity, authoritative members, bounded typing, ID-only replies, safe rendering, constrained storage, reconnect, and responsive safeguards.'

