(() => {
  "use strict";

  const joinPanel = document.querySelector("#joinPanel");
  const chatPanel = document.querySelector("#chatPanel");
  const joinForm = document.querySelector("#joinForm");
  const joinButton = document.querySelector("#joinButton");
  const nicknameInput = document.querySelector("#nickname");
  const roomCodeInput = document.querySelector("#roomCode");
  const toggleRoomCodeButton = document.querySelector("#toggleRoomCodeButton");
  const generateRoomCodeButton = document.querySelector("#generateRoomCodeButton");
  const copyRoomCodeButton = document.querySelector("#copyRoomCodeButton");
  const roomCodeFeedback = document.querySelector("#roomCodeFeedback");
  const joinError = document.querySelector("#joinError");
  const messageForm = document.querySelector("#messageForm");
  const messageInput = document.querySelector("#messageInput");
  const sendButton = document.querySelector("#sendButton");
  const reconnectButton = document.querySelector("#reconnectButton");
  const leaveButton = document.querySelector("#leaveButton");
  const messages = document.querySelector("#messages");
  const historyLoading = document.querySelector("#historyLoading");
  const emptyState = document.querySelector("#emptyState");
  const latestButton = document.querySelector("#latestButton");
  const latestButtonLabel = document.querySelector("#latestButtonLabel");
  const onlineCount = document.querySelector("#onlineCount");
  const membersButton = document.querySelector("#membersButton");
  const membersBackdrop = document.querySelector("#membersBackdrop");
  const membersPanel = document.querySelector("#membersPanel");
  const membersCloseButton = document.querySelector("#membersCloseButton");
  const membersSummary = document.querySelector("#membersSummary");
  const membersList = document.querySelector("#membersList");
  const typingIndicator = document.querySelector("#typingIndicator");
  const replyPreview = document.querySelector("#replyPreview");
  const replyPreviewNickname = document.querySelector("#replyPreviewNickname");
  const replyPreviewText = document.querySelector("#replyPreviewText");
  const cancelReplyButton = document.querySelector("#cancelReplyButton");
  const currentNickname = document.querySelector("#currentNickname");
  const roomStatus = document.querySelector("#roomStatus");
  const composerFeedback = document.querySelector("#composerFeedback");
  const messageCount = document.querySelector("#messageCount");
  const connection = document.querySelector(".connection");
  const connectionStatus = document.querySelector("#connectionStatus");

  const HEARTBEAT_INTERVAL_MS = 20_000;
  const HEARTBEAT_TIMEOUT_MS = 60_000;
  const MAX_RECONNECT_ATTEMPTS = 3;
  const RECONNECT_BASE_DELAY_MS = 1_000;
  const BOTTOM_THRESHOLD_PX = 96;
  const MAX_MESSAGE_CHARACTERS = 2_000;
  const MAX_RENDERED_MESSAGES = 50;
  const ROOM_CODE_PATTERN = /^[A-Za-z0-9_-]{8,64}$/;
  const IDENTITY_TOKEN_STORAGE_KEY = "metchat.anonymousIdentityToken";
  const MAX_IDENTITY_TOKEN_BYTES = 512;
  const TYPING_IDLE_DELAY_MS = 2_000;
  const TYPING_REFRESH_INTERVAL_MS = 3_000;
  const REMOTE_TYPING_FALLBACK_MS = 5_500;
  const CHAT_CONFIRM_TIMEOUT_MS = 10_000;
  const MESSAGE_EXPIRY_CHECK_INTERVAL_MS = 60_000;
  const REPLY_EXCERPT_CHARACTERS = 72;
  const DEFAULT_COMPOSER_HINT = "Enter 发送 · Shift + Enter 换行";

  const JOIN_ERROR_MESSAGES = {
    invalid_room_code: "房间暗号格式无效，请检查长度、字符和大小写。",
    room_capacity: "当前可用房间数量已满，请稍后再试。",
    room_full: "这个房间已经满员，请稍后再试。",
    server_capacity: "服务器连接容量已满，请稍后再试。",
    too_many_join_attempts: "加入尝试过于频繁，请稍后重新连接。",
    invalid_nickname: "请输入有效昵称。",
    nickname_too_long: "昵称不能超过 24 个字符。",
    invalid_reply_target: "引用消息标识无效，请重新选择回复对象。",
    reply_target_unavailable: "原消息已过期或不在最近记录中。",
    typing_rate_limited: "输入状态更新过于频繁，聊天消息仍可正常发送。",
  };

  let socket = null;
  let nickname = "";
  let roomCode = "";
  let roomId = "";
  let identityToken = readIdentityToken();
  let currentSenderId = "";
  let currentMemberId = "";
  let membersVersion = -1;
  let currentMembers = [];
  let joined = false;
  let heartbeatTimer = null;
  let reconnectTimer = null;
  let reconnectAttempts = 0;
  let lastPongAt = 0;
  let hasLoadedHistory = false;
  let pageUnloading = false;
  let suppressReconnect = false;
  let focusAfterHistory = false;
  let unseenMessageCount = 0;
  let viewportFrame = null;
  let rootStyleRule = null;
  let replyTargetId = "";
  let localTypingActive = false;
  let typingIdleTimer = null;
  let typingRefreshTimer = null;
  let isComposing = false;
  let pendingChat = null;
  let expiryTimer = null;
  let serverClockAnchor = null;
  let unknownSendOutcome = false;

  // IDs exist only for this page lifetime and are never persisted.
  const seenMessageIds = new Set();
  const messagePackets = new Map();
  const messageElements = new Map();
  const remoteTyping = new Map();
  const typingSequences = new Map();

  function readIdentityToken() {
    try {
      const value = window.sessionStorage.getItem(IDENTITY_TOKEN_STORAGE_KEY);
      return typeof value === "string" && value.length <= MAX_IDENTITY_TOKEN_BYTES ? value : "";
    } catch {
      return "";
    }
  }

  function storeIdentityToken(token) {
    identityToken = token;
    try {
      window.sessionStorage.setItem(IDENTITY_TOKEN_STORAGE_KEY, token);
    } catch {
      // The in-memory value still preserves identity during this page lifetime.
    }
  }

  function clearIdentityToken() {
    identityToken = "";
    try {
      window.sessionStorage.removeItem(IDENTITY_TOKEN_STORAGE_KEY);
    } catch {
      // Storage may be unavailable in a restricted browsing context.
    }
  }

  function websocketUrl() {
    const isLocal = location.hostname === "127.0.0.1" || location.hostname === "localhost";
    if (isLocal && location.port !== "2346") {
      return `ws://${location.hostname}:2346`;
    }

    const scheme = location.protocol === "https:" ? "wss:" : "ws:";
    return `${scheme}//${location.host}/ws`;
  }

  function setStatus(state, label) {
    connection.dataset.state = state;
    connectionStatus.textContent = label;
  }

  function setComposerFeedback(message = DEFAULT_COMPOSER_HINT, tone = "neutral") {
    composerFeedback.textContent = message;
    if (tone === "neutral") {
      composerFeedback.removeAttribute("data-tone");
    } else {
      composerFeedback.dataset.tone = tone;
    }
  }

  function setComposerEnabled(enabled) {
    messageInput.disabled = !enabled;
    sendButton.disabled = !enabled || pendingChat !== null;

    if (!enabled && !joined) {
      setComposerFeedback("连接恢复后才能发送消息");
    }
  }

  function formatTime(isoValue) {
    const date = isoValue ? new Date(isoValue) : null;
    if (!date || Number.isNaN(date.getTime())) {
      return "时间未知";
    }

    return new Intl.DateTimeFormat("zh-CN", {
      hour: "2-digit",
      minute: "2-digit",
    }).format(date);
  }

  function updateServerClock(serverTime) {
    const serverTimeMs = typeof serverTime === "string" ? Date.parse(serverTime) : Number.NaN;
    if (!Number.isFinite(serverTimeMs)) {
      return false;
    }

    serverClockAnchor = {
      serverTimeMs,
      clientMonotonicMs: performance.now(),
    };
    return true;
  }

  function estimatedServerNow() {
    if (!serverClockAnchor) {
      return null;
    }

    return serverClockAnchor.serverTimeMs + (performance.now() - serverClockAnchor.clientMonotonicMs);
  }

  function isExpiredMessage(packet) {
    if (typeof packet?.expiresAt !== "string") {
      // Older servers did not provide lifecycle metadata. Keep those messages
      // until a server history refresh rather than inventing an expiry time.
      return false;
    }
    const expiresAt = Date.parse(packet.expiresAt);
    const serverNow = estimatedServerNow();
    return Number.isFinite(expiresAt) && serverNow !== null && expiresAt <= serverNow;
  }

  function excerpt(value, maximum = REPLY_EXCERPT_CHARACTERS) {
    const normalized = String(value ?? "").replace(/\s+/gu, " ").trim();
    const characters = Array.from(normalized);
    return characters.length > maximum ? `${characters.slice(0, maximum).join("")}…` : normalized;
  }

  function renderMembers() {
    membersList.replaceChildren();
    membersSummary.textContent = currentMembers.length > 0
      ? `${currentMembers.length} 个在线连接`
      : "当前没有在线连接";
    membersButton.textContent = currentMembers.length > 0
      ? `在线成员 ${currentMembers.length}`
      : "在线成员";

    for (const member of currentMembers) {
      const item = document.createElement("li");
      item.className = "member-card";

      const name = document.createElement("span");
      name.className = "member-card__name";
      name.textContent = member.nickname;
      item.append(name);

      if (member.memberId === currentMemberId) {
        const self = document.createElement("span");
        self.className = "member-card__self";
        self.textContent = "当前连接";
        item.append(self);
      }

      const meta = document.createElement("span");
      meta.className = "member-card__meta";
      meta.textContent = `连接 ${member.memberId.slice(0, 8)} · 加入 ${formatTime(member.joinedAt)}`;
      item.append(meta);
      membersList.append(item);
    }
  }

  function closeMembersPanel() {
    membersBackdrop.hidden = true;
    membersButton.setAttribute("aria-expanded", "false");
  }

  function resetMembers() {
    membersVersion = -1;
    currentMemberId = "";
    currentMembers = [];
    membersButton.disabled = true;
    onlineCount.textContent = "0";
    renderMembers();
    closeMembersPanel();
  }

  function clearRemoteTyping() {
    for (const state of remoteTyping.values()) {
      window.clearTimeout(state.timer);
    }
    remoteTyping.clear();
    typingSequences.clear();
    typingIndicator.textContent = "";
  }

  function renderTypingIndicator() {
    const activeStates = [...remoteTyping.values()];
    if (activeStates.length === 0) {
      typingIndicator.textContent = "";
    } else if (activeStates.length <= 2) {
      typingIndicator.textContent = `${activeStates.map((state) => state.nickname).join("、")} 正在输入…`;
    } else {
      typingIndicator.textContent = `${activeStates.length} 个连接正在输入…`;
    }
  }

  function applyMembers(packet) {
    if (!Number.isInteger(packet.version) || packet.version <= membersVersion || !Array.isArray(packet.members)) {
      return;
    }

    const seenMemberIds = new Set();
    const membersSnapshot = [];
    for (const member of packet.members.slice(0, 64)) {
      if (
        !member
        || typeof member.memberId !== "string"
        || !/^[a-f0-9]{32}$/.test(member.memberId)
        || seenMemberIds.has(member.memberId)
        || typeof member.nickname !== "string"
        || typeof member.senderId !== "string"
        || typeof member.joinedAt !== "string"
        || typeof member.lastSeenAt !== "string"
      ) {
        return;
      }
      seenMemberIds.add(member.memberId);
      membersSnapshot.push(member);
    }
    if (
      packet.count !== membersSnapshot.length
      || typeof packet.selfMemberId !== "string"
      || !seenMemberIds.has(packet.selfMemberId)
    ) {
      return;
    }

    membersVersion = packet.version;
    currentMemberId = packet.selfMemberId;
    currentMembers = membersSnapshot;
    membersButton.disabled = false;
    onlineCount.textContent = String(membersSnapshot.length);
    renderMembers();

    for (const [memberId, state] of remoteTyping) {
      if (!seenMemberIds.has(memberId)) {
        window.clearTimeout(state.timer);
        remoteTyping.delete(memberId);
        typingSequences.delete(memberId);
      }
    }
    for (const memberId of typingSequences.keys()) {
      if (!seenMemberIds.has(memberId)) {
        typingSequences.delete(memberId);
      }
    }
    renderTypingIndicator();
  }

  function handleTypingPacket(packet) {
    if (
      typeof packet.memberId !== "string"
      || typeof packet.nickname !== "string"
      || typeof packet.senderId !== "string"
      || typeof packet.active !== "boolean"
      || !Number.isInteger(packet.sequence)
      || packet.memberId === currentMemberId
    ) {
      return;
    }

    const previousSequence = typingSequences.get(packet.memberId) ?? -1;
    if (packet.sequence <= previousSequence) {
      return;
    }
    typingSequences.set(packet.memberId, packet.sequence);
    const previous = remoteTyping.get(packet.memberId);
    if (previous) {
      window.clearTimeout(previous.timer);
      remoteTyping.delete(packet.memberId);
    }
    if (!packet.active) {
      renderTypingIndicator();
      return;
    }

    const timer = window.setTimeout(() => {
      const current = remoteTyping.get(packet.memberId);
      if (current?.sequence === packet.sequence) {
        remoteTyping.delete(packet.memberId);
        renderTypingIndicator();
      }
    }, REMOTE_TYPING_FALLBACK_MS);
    remoteTyping.set(packet.memberId, { nickname: packet.nickname, sequence: packet.sequence, timer });
    renderTypingIndicator();
  }

  function stopLocalTyping(notifyServer = true) {
    if (typingIdleTimer !== null) {
      window.clearTimeout(typingIdleTimer);
      typingIdleTimer = null;
    }
    if (typingRefreshTimer !== null) {
      window.clearInterval(typingRefreshTimer);
      typingRefreshTimer = null;
    }
    if (localTypingActive && notifyServer) {
      send({ type: "typing", active: false });
    }
    localTypingActive = false;
  }

  function noteLocalTyping() {
    if (!joined || !messageInput.value || !socket || socket.readyState !== WebSocket.OPEN) {
      stopLocalTyping(true);
      return;
    }
    if (!localTypingActive) {
      if (!send({ type: "typing", active: true })) {
        return;
      }
      localTypingActive = true;
      typingRefreshTimer = window.setInterval(() => {
        if (localTypingActive) {
          send({ type: "typing", active: true });
        }
      }, TYPING_REFRESH_INTERVAL_MS);
    }
    if (typingIdleTimer !== null) {
      window.clearTimeout(typingIdleTimer);
    }
    typingIdleTimer = window.setTimeout(() => stopLocalTyping(true), TYPING_IDLE_DELAY_MS);
  }

  function clearReply() {
    replyTargetId = "";
    replyPreview.hidden = true;
    replyPreviewNickname.textContent = "";
    replyPreviewText.textContent = "";
  }

  function markReplyUnavailable(messageId, feedback = true) {
    if (replyTargetId !== messageId) {
      return;
    }
    replyPreviewNickname.textContent = "引用消息";
    replyPreviewText.textContent = "原消息已过期或不在最近记录中。";
    replyPreview.hidden = false;
    if (feedback) {
      setComposerFeedback("引用目标已失效；可取消引用后保留正文并发送普通消息。", "error");
    }
  }

  function beginReply(messageId) {
    const target = messagePackets.get(messageId);
    if (!target) {
      setComposerFeedback("原消息已过期或不在最近记录中。", "error");
      return;
    }
    replyTargetId = messageId;
    replyPreviewNickname.textContent = target.nickname;
    replyPreviewText.textContent = excerpt(target.text);
    replyPreview.hidden = false;
    messageInput.focus({ preventScroll: true });
  }

  function focusReferencedMessage(messageId) {
    const target = messageElements.get(messageId);
    if (!target) {
      setComposerFeedback("原消息已过期或不在最近记录中。", "error");
      return;
    }
    target.scrollIntoView({ block: "center", behavior: "smooth" });
    target.classList.add("message--highlight");
    window.setTimeout(() => target.classList.remove("message--highlight"), 1_600);
  }

  function populateReplyContext(replyContext, replyToId) {
    const referenced = messagePackets.get(replyToId);
    const referencedName = document.createElement("strong");
    const referencedText = document.createElement("span");
    if (referenced) {
      referencedName.textContent = `回复 ${referenced.nickname}`;
      referencedText.textContent = excerpt(referenced.text);
    } else {
      referencedName.textContent = "引用消息";
      referencedText.textContent = "原消息已过期或不在最近记录中。";
    }
    replyContext.replaceChildren(referencedName, referencedText);
  }

  function refreshReplyContexts() {
    for (const replyContext of messages.querySelectorAll(".message__reply-context")) {
      populateReplyContext(replyContext, replyContext.dataset.replyToId || "");
    }
  }

  function removeRenderedMessage(messageId, reason) {
    const element = messageElements.get(messageId);
    element?.remove();
    seenMessageIds.delete(messageId);
    messagePackets.delete(messageId);
    messageElements.delete(messageId);
    if (replyTargetId === messageId) {
      markReplyUnavailable(messageId, true);
    }
    if (reason === "expired") {
      refreshReplyContexts();
    }
  }

  function enforceRenderedMessageLimit() {
    const renderedMessages = [...messages.querySelectorAll(".message")];
    for (const item of renderedMessages.slice(0, -MAX_RENDERED_MESSAGES)) {
      const removedId = item.dataset.messageId;
      if (removedId) {
        removeRenderedMessage(removedId, "capped");
      } else {
        item.remove();
      }
    }
    refreshReplyContexts();
  }

  function pruneExpiredRenderedMessages() {
    let removed = false;
    for (const [messageId, packet] of [...messagePackets]) {
      if (isExpiredMessage(packet)) {
        removeRenderedMessage(messageId, "expired");
        removed = true;
      }
    }
    if (removed) {
      refreshReplyContexts();
      updateEmptyState();
      updateLatestNotice();
    }
  }

  function isNearBottom() {
    return messages.scrollHeight - messages.scrollTop - messages.clientHeight <= BOTTOM_THRESHOLD_PX;
  }

  function scrollToLatest(behavior = "smooth") {
    messages.scrollTo({ top: messages.scrollHeight, behavior });
    unseenMessageCount = 0;
    latestButton.hidden = true;
  }

  function showLatestNotice() {
    unseenMessageCount += 1;
    latestButtonLabel.textContent = unseenMessageCount === 1
      ? "1 条新消息"
      : `${unseenMessageCount} 条新消息`;
    latestButton.hidden = false;
  }

  function updateLatestNotice() {
    if (isNearBottom()) {
      unseenMessageCount = 0;
      latestButton.hidden = true;
    }
  }

  function updateEmptyState() {
    emptyState.hidden = !hasLoadedHistory || messages.querySelector(".message") !== null;
  }

  function isValidMessage(packet) {
    return packet
      && typeof packet.id === "string"
      && packet.id.length > 0
      && typeof packet.nickname === "string"
      && typeof packet.text === "string"
      && typeof packet.sentAt === "string"
      && (
        !Object.prototype.hasOwnProperty.call(packet, "expiresAt")
        || (typeof packet.expiresAt === "string" && Number.isFinite(Date.parse(packet.expiresAt)))
      )
      && (
        !Object.prototype.hasOwnProperty.call(packet, "replyToId")
        || (typeof packet.replyToId === "string" && /^[a-f0-9]{32}$/.test(packet.replyToId))
      );
  }

  function messageOwnership(packet) {
    if (typeof packet.senderId !== "string" || !packet.senderId || !currentSenderId) {
      return "neutral";
    }

    return packet.senderId === currentSenderId ? "own" : "other";
  }

  function appendChat(packet, { autoScroll = true } = {}) {
    if (!isValidMessage(packet) || isExpiredMessage(packet) || seenMessageIds.has(packet.id)) {
      return false;
    }

    const shouldScroll = isNearBottom();
    const item = document.createElement("li");
    item.className = `message message--${messageOwnership(packet)}`;
    item.dataset.messageId = packet.id;
    messagePackets.set(packet.id, packet);
    messageElements.set(packet.id, item);

    const meta = document.createElement("p");
    meta.className = "message__meta";

    const sender = document.createElement("span");
    sender.className = "message__nickname";
    sender.textContent = packet.nickname;
    meta.append(sender, document.createTextNode(` · ${formatTime(packet.sentAt)}`));

    const body = document.createElement("p");
    body.className = "message__body";
    body.textContent = packet.text;

    item.append(meta);
    if (typeof packet.replyToId === "string") {
      const replyContext = document.createElement("button");
      replyContext.className = "message__reply-context";
      replyContext.type = "button";
      replyContext.dataset.replyToId = packet.replyToId;
      populateReplyContext(replyContext, packet.replyToId);
      replyContext.addEventListener("click", () => focusReferencedMessage(packet.replyToId));
      item.append(replyContext);
    }
    item.append(body);

    const actions = document.createElement("div");
    actions.className = "message__actions";
    const replyButton = document.createElement("button");
    replyButton.className = "message__reply-action";
    replyButton.type = "button";
    replyButton.textContent = "回复";
    replyButton.setAttribute("aria-label", `回复 ${packet.nickname} 的消息`);
    replyButton.addEventListener("click", () => beginReply(packet.id));
    actions.append(replyButton);
    item.append(actions);
    messages.append(item);
    seenMessageIds.add(packet.id);
    enforceRenderedMessageLimit();
    updateEmptyState();

    if (autoScroll && shouldScroll) {
      scrollToLatest();
    } else if (autoScroll) {
      showLatestNotice();
    }

    return true;
  }

  function appendSystem(text) {
    const shouldScroll = isNearBottom();
    const item = document.createElement("li");
    item.className = "system-message";
    item.textContent = text;
    messages.append(item);

    if (shouldScroll) {
      scrollToLatest();
    }
  }

  function applyHistory(history) {
    const firstLoad = !hasLoadedHistory;
    const shouldScroll = firstLoad || isNearBottom();
    const safeHistory = Array.isArray(history) ? history.slice(-50) : [];

    historyLoading.hidden = true;
    for (const message of safeHistory) {
      appendChat(message, { autoScroll: false });
    }

    pruneExpiredRenderedMessages();

    hasLoadedHistory = true;
    updateEmptyState();

    if (shouldScroll) {
      scrollToLatest("auto");
    }
  }

  function send(payload) {
    if (!socket || socket.readyState !== WebSocket.OPEN) {
      return false;
    }

    try {
      socket.send(JSON.stringify(payload));
      return true;
    } catch {
      return false;
    }
  }

  function generateRequestId() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return [...bytes].map((value) => value.toString(16).padStart(2, "0")).join("");
  }

  function clearPendingChatTimer() {
    if (pendingChat?.timer !== null) {
      window.clearTimeout(pendingChat.timer);
    }
  }

  function markPendingChatUnknown(message) {
    if (!pendingChat) {
      return;
    }
    clearPendingChatTimer();
    pendingChat = null;
    unknownSendOutcome = true;
    setComposerEnabled(joined && socket?.readyState === WebSocket.OPEN);
    setComposerFeedback(message, "error");
  }

  function handleChatResult(packet) {
    if (
      !pendingChat
      || typeof packet.requestId !== "string"
      || packet.requestId !== pendingChat.requestId
      || typeof packet.ok !== "boolean"
    ) {
      return;
    }

    updateServerClock(packet.serverTime);
    clearPendingChatTimer();
    const completed = pendingChat;
    pendingChat = null;
    unknownSendOutcome = false;
    setComposerEnabled(joined && socket?.readyState === WebSocket.OPEN);

    if (packet.ok) {
      if (typeof packet.messageId !== "string" || !/^[a-f0-9]{32}$/.test(packet.messageId)) {
        setComposerFeedback("服务器确认格式异常；草稿已保留且不会自动重发。", "error");
        return;
      }
      const composerUnchanged = messageInput.value === completed.draftValue
        && replyTargetId === completed.replyToId;
      if (composerUnchanged) {
        messageInput.value = "";
        messageInput.rows = 1;
        clearReply();
        updateMessageCount();
        setComposerFeedback("消息已由服务器确认。", "success");
      } else {
        setComposerFeedback("消息已确认发送；你后续编辑的新草稿已保留。", "success");
      }
      scrollToLatest();
      messageInput.focus({ preventScroll: true });
      return;
    }

    const errorMessage = joinErrorMessage(packet);
    if (packet.code === "reply_target_unavailable" || packet.code === "invalid_reply_target") {
      markReplyUnavailable(completed.replyToId, false);
    }
    setComposerFeedback(`${errorMessage} 正文和引用状态已保留。`, "error");
  }

  function stopHeartbeat() {
    if (heartbeatTimer !== null) {
      window.clearInterval(heartbeatTimer);
      heartbeatTimer = null;
    }
  }

  function clearReconnectTimer() {
    if (reconnectTimer !== null) {
      window.clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }
  }

  function startHeartbeat() {
    stopHeartbeat();
    lastPongAt = Date.now();
    heartbeatTimer = window.setInterval(() => {
      if (!socket || socket.readyState !== WebSocket.OPEN) {
        return;
      }

      if (Date.now() - lastPongAt > HEARTBEAT_TIMEOUT_MS) {
        socket.close(4000, "heartbeat timeout");
        return;
      }

      send({ type: "ping", ts: Date.now() });
    }, HEARTBEAT_INTERVAL_MS);
  }

  function scheduleReconnect() {
    if (pageUnloading || suppressReconnect || !nickname || !roomCode) {
      return false;
    }

    if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
      setStatus("offline", "重连失败");
      setComposerFeedback("自动重连已停止，可手动重试。", "error");
      reconnectButton.hidden = chatPanel.hidden;
      if (chatPanel.hidden) {
        joinError.textContent = "连接未恢复，请检查网络或服务状态后重试。";
      }
      return false;
    }

    const delay = RECONNECT_BASE_DELAY_MS * (2 ** reconnectAttempts);
    reconnectAttempts += 1;
    focusAfterHistory = false;
    setStatus("reconnecting", `断线重连中 ${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS}`);
    setComposerFeedback(`${delay / 1000} 秒后尝试恢复连接`);
    reconnectButton.hidden = chatPanel.hidden;
    reconnectTimer = window.setTimeout(() => {
      reconnectTimer = null;
      connect();
    }, delay);
    return true;
  }

  function finishDisconnect(closedSocket) {
    if (socket !== closedSocket) {
      return;
    }

    const hadPendingChat = pendingChat !== null;
    socket = null;
    joined = false;
    stopHeartbeat();
    stopLocalTyping(false);
    clearRemoteTyping();
    resetMembers();
    setComposerEnabled(false);
    markPendingChatUnknown("连接在服务器确认前中断；发送结果暂时未知，草稿已保留且不会自动重发。");
    if (roomId) {
      roomStatus.textContent = `ID ${roomId} · 已断开`;
    }

    const reconnectScheduled = scheduleReconnect();
    if (hadPendingChat && reconnectScheduled) {
      setComposerFeedback("发送结果暂时未知，草稿已保留且不会自动重发；正在恢复连接。", "error");
    }
    joinButton.disabled = reconnectScheduled;
    if (!reconnectScheduled && !pageUnloading && !suppressReconnect) {
      setStatus("offline", reconnectAttempts >= MAX_RECONNECT_ATTEMPTS ? "重连失败" : "连接已断开");
      reconnectButton.hidden = chatPanel.hidden;
    }
  }

  function joinErrorMessage(packet) {
    if (typeof packet.code === "string" && JOIN_ERROR_MESSAGES[packet.code]) {
      return JOIN_ERROR_MESSAGES[packet.code];
    }

    return typeof packet.message === "string" ? packet.message : "无法加入房间。";
  }

  function handleServerMessage(event, sourceSocket) {
    if (socket !== sourceSocket) {
      return;
    }

    let packet;
    try {
      packet = JSON.parse(event.data);
    } catch {
      return;
    }

    if (!packet || typeof packet.type !== "string") {
      return;
    }

    switch (packet.type) {
      case "join":
        if (packet.self === true) {
          currentSenderId = typeof packet.senderId === "string" ? packet.senderId : "";
          currentMemberId = typeof packet.memberId === "string" ? packet.memberId : "";
          if (
            typeof packet.identityToken === "string"
            && packet.identityToken.length > 0
            && packet.identityToken.length <= MAX_IDENTITY_TOKEN_BYTES
          ) {
            storeIdentityToken(packet.identityToken);
          }
          joined = true;
          reconnectAttempts = 0;
          suppressReconnect = false;
          joinPanel.hidden = true;
          chatPanel.hidden = false;
          reconnectButton.hidden = true;
          joinButton.disabled = false;
          roomId = typeof packet.roomId === "string" ? packet.roomId : "";
          currentNickname.textContent = nickname;
          roomStatus.textContent = roomId ? `ID ${roomId}` : "私人房间";
          historyLoading.hidden = hasLoadedHistory;
          setComposerEnabled(false);
          setStatus("connecting", "正在恢复消息");
        } else if (typeof packet.nickname === "string") {
          appendSystem(`${packet.nickname} 加入了房间`);
        }
        break;
      case "history":
        if (!joined) {
          break;
        }
        updateServerClock(packet.serverTime);
        applyHistory(packet.messages);
        setComposerEnabled(true);
        if (unknownSendOutcome) {
          setComposerFeedback("连接已恢复；上一条发送结果仍未知，草稿已保留且不会自动重发。", "error");
        } else {
          setComposerFeedback();
        }
        setStatus("online", "已连接");
        if (focusAfterHistory && window.matchMedia("(pointer: fine)").matches) {
          messageInput.focus({ preventScroll: true });
        }
        focusAfterHistory = false;
        break;
      case "chat":
        appendChat(packet);
        if (!pendingChat) {
          setComposerFeedback();
        }
        break;
      case "chat_result":
        handleChatResult(packet);
        break;
      case "members":
        if (joined) {
          applyMembers(packet);
        }
        break;
      case "typing":
        if (joined) {
          handleTypingPacket(packet);
        }
        break;
      case "presence":
        if (Number.isInteger(packet.count) && packet.count >= 0) {
          onlineCount.textContent = String(packet.count);
        }
        if (packet.event === "leave" && typeof packet.nickname === "string") {
          appendSystem(`${packet.nickname} 离开了房间`);
        }
        break;
      case "pong":
        lastPongAt = Date.now();
        break;
      case "error": {
        const errorMessage = joinErrorMessage(packet);
        if (!joined) {
          suppressReconnect = true;
          setStatus("offline", packet.code === "room_full" ? "房间已满" : "连接被拒绝");
          if (chatPanel.hidden) {
            joinError.textContent = errorMessage;
          } else {
            setComposerFeedback(errorMessage, "error");
            reconnectButton.hidden = false;
          }
          joinButton.disabled = false;
          sourceSocket.close();
        } else {
          setComposerFeedback(errorMessage, "error");
        }
        break;
      }
      default:
        break;
    }
  }

  function connect() {
    if (
      !nickname
      || !roomCode
      || (socket && [WebSocket.CONNECTING, WebSocket.OPEN].includes(socket.readyState))
    ) {
      return;
    }

    if (!("WebSocket" in window)) {
      joinError.textContent = "当前浏览器不支持 WebSocket，请更换现代浏览器。";
      setStatus("offline", "浏览器不支持");
      joinButton.disabled = false;
      return;
    }

    clearReconnectTimer();
    joinError.textContent = "";
    joinButton.disabled = true;
    reconnectButton.hidden = true;
    const reconnecting = hasLoadedHistory || reconnectAttempts > 0;
    setStatus(reconnecting ? "reconnecting" : "connecting", reconnecting ? "正在重新连接" : "正在连接");

    const nextSocket = new WebSocket(websocketUrl());
    socket = nextSocket;
    nextSocket.addEventListener("open", () => {
      if (socket !== nextSocket) {
        return;
      }
      startHeartbeat();
      const joinPacket = { type: "join", nickname, roomCode };
      if (identityToken) {
        joinPacket.identityToken = identityToken;
      }
      send(joinPacket);
    });
    nextSocket.addEventListener("message", (event) => handleServerMessage(event, nextSocket));
    nextSocket.addEventListener("close", () => finishDisconnect(nextSocket));
    nextSocket.addEventListener("error", () => {
      if (socket !== nextSocket) {
        return;
      }
      if (chatPanel.hidden) {
        joinError.textContent = "无法连接聊天服务，正在尝试恢复。";
      } else {
        setComposerFeedback("网络连接异常，消息发送已暂停。", "error");
      }
    });
  }

  function clearRoomMessages() {
    clearPendingChatTimer();
    pendingChat = null;
    unknownSendOutcome = false;
    messages.querySelectorAll(".message, .system-message").forEach((item) => item.remove());
    seenMessageIds.clear();
    messagePackets.clear();
    messageElements.clear();
    clearReply();
    hasLoadedHistory = false;
    historyLoading.hidden = false;
    emptyState.hidden = true;
    unseenMessageCount = 0;
    latestButton.hidden = true;
  }

  function leaveRoom() {
    suppressReconnect = true;
    clearReconnectTimer();
    stopHeartbeat();
    stopLocalTyping(true);
    clearRemoteTyping();

    const closingSocket = socket;
    socket = null;
    joined = false;
    closingSocket?.close(1000, "user left room");

    clearRoomMessages();
    nickname = "";
    roomCode = "";
    roomId = "";
    currentSenderId = "";
    resetMembers();
    clearIdentityToken();
    reconnectAttempts = 0;
    focusAfterHistory = false;
    nicknameInput.value = "";
    roomCodeInput.value = "";
    roomCodeInput.type = "password";
    toggleRoomCodeButton.textContent = "显示";
    toggleRoomCodeButton.setAttribute("aria-pressed", "false");
    toggleRoomCodeButton.setAttribute("aria-label", "显示房间暗号");
    copyRoomCodeButton.disabled = true;
    roomCodeFeedback.textContent = "";
    roomCodeFeedback.removeAttribute("data-tone");
    messageInput.value = "";
    messageInput.rows = 1;
    messageCount.textContent = `0 / ${MAX_MESSAGE_CHARACTERS}`;
    currentNickname.textContent = "未加入";
    roomStatus.textContent = "等待加入";
    onlineCount.textContent = "0";
    chatPanel.hidden = true;
    joinPanel.hidden = false;
    reconnectButton.hidden = true;
    joinButton.disabled = false;
    joinError.textContent = "";
    setComposerEnabled(false);
    setStatus("idle", "尚未连接");
    setComposerFeedback();

    if (window.matchMedia("(pointer: fine)").matches) {
      nicknameInput.focus({ preventScroll: true });
    }
  }

  function updateMessageCount() {
    const length = Array.from(messageInput.value).length;
    messageCount.textContent = `${length} / ${MAX_MESSAGE_CHARACTERS}`;
  }

  function resizeMessageInput() {
    messageInput.rows = 1;
    const computed = window.getComputedStyle(messageInput);
    const lineHeight = Number.parseFloat(computed.lineHeight) || 23;
    const verticalPadding = Number.parseFloat(computed.paddingTop) + Number.parseFloat(computed.paddingBottom);
    const contentHeight = Math.max(lineHeight, messageInput.scrollHeight - verticalPadding);
    messageInput.rows = Math.min(6, Math.max(1, Math.ceil(contentHeight / lineHeight)));
  }

  function findRootStyleRule() {
    for (const styleSheet of document.styleSheets) {
      try {
        for (const rule of styleSheet.cssRules) {
          if (rule.selectorText === ":root") {
            return rule;
          }
        }
      } catch {
        // Ignore inaccessible third-party stylesheets; this app does not load any.
      }
    }

    return null;
  }

  function updateViewportHeight() {
    if (viewportFrame !== null) {
      window.cancelAnimationFrame(viewportFrame);
    }

    viewportFrame = window.requestAnimationFrame(() => {
      const height = window.visualViewport?.height || window.innerHeight;
      rootStyleRule ??= findRootStyleRule();
      rootStyleRule?.style.setProperty("--app-height", `${Math.round(height)}px`);
      viewportFrame = null;
    });
  }

  function setRoomCodeFeedback(message = "", tone = "neutral") {
    roomCodeFeedback.textContent = message;
    if (tone === "neutral") {
      roomCodeFeedback.removeAttribute("data-tone");
    } else {
      roomCodeFeedback.dataset.tone = tone;
    }
  }

  function revealAndSelectRoomCode() {
    roomCodeInput.type = "text";
    toggleRoomCodeButton.textContent = "隐藏";
    toggleRoomCodeButton.setAttribute("aria-pressed", "true");
    toggleRoomCodeButton.setAttribute("aria-label", "隐藏房间暗号");
    roomCodeInput.focus({ preventScroll: true });
    roomCodeInput.select();
    roomCodeInput.setSelectionRange(0, roomCodeInput.value.length);
  }

  toggleRoomCodeButton.addEventListener("click", () => {
    const shouldReveal = roomCodeInput.type === "password";
    roomCodeInput.type = shouldReveal ? "text" : "password";
    toggleRoomCodeButton.textContent = shouldReveal ? "隐藏" : "显示";
    toggleRoomCodeButton.setAttribute("aria-pressed", String(shouldReveal));
    toggleRoomCodeButton.setAttribute("aria-label", shouldReveal ? "隐藏房间暗号" : "显示房间暗号");
    roomCodeInput.focus({ preventScroll: true });
  });

  generateRoomCodeButton.addEventListener("click", () => {
    try {
      const generated = window.MetChatRoomCode.generate();
      roomCodeInput.value = generated;
      copyRoomCodeButton.disabled = false;
      joinError.textContent = "";
      setRoomCodeFeedback("已生成 24 位随机暗号，请复制后安全地发送给朋友。", "success");
      roomCodeInput.focus({ preventScroll: true });
    } catch {
      setRoomCodeFeedback("当前浏览器无法生成安全随机暗号，请手动输入。", "error");
    }
  });

  copyRoomCodeButton.addEventListener("click", async () => {
    const value = roomCodeInput.value;
    if (!ROOM_CODE_PATTERN.test(value)) {
      setRoomCodeFeedback("请先输入或生成有效暗号。", "error");
      roomCodeInput.focus({ preventScroll: true });
      return;
    }

    try {
      if (!navigator.clipboard || typeof navigator.clipboard.writeText !== "function") {
        throw new Error("Clipboard API unavailable");
      }
      await navigator.clipboard.writeText(value);
      setRoomCodeFeedback("暗号已复制。", "success");
    } catch {
      revealAndSelectRoomCode();
      setRoomCodeFeedback("浏览器未允许复制，暗号已选中，请按 Ctrl/Cmd+C 手动复制。", "error");
    }
  });

  roomCodeInput.addEventListener("input", () => {
    copyRoomCodeButton.disabled = roomCodeInput.value.length === 0;
    setRoomCodeFeedback();
  });

  joinForm.addEventListener("submit", (event) => {
    event.preventDefault();
    const candidate = nicknameInput.value.trim();
    const roomCandidate = roomCodeInput.value;

    if (!candidate) {
      joinError.textContent = "请输入昵称。";
      nicknameInput.focus();
      return;
    }

    if (Array.from(candidate).length > 24) {
      joinError.textContent = "昵称不能超过 24 个字符。";
      nicknameInput.focus();
      return;
    }

    if (!ROOM_CODE_PATTERN.test(roomCandidate)) {
      joinError.textContent = "暗号需为 8–64 位英文字母、数字、下划线或连字符，并区分大小写。";
      roomCodeInput.focus();
      return;
    }

    nickname = candidate;
    roomCode = roomCandidate;
    reconnectAttempts = 0;
    suppressReconnect = false;
    focusAfterHistory = true;
    connect();
  });

  messageForm.addEventListener("submit", (event) => {
    event.preventDefault();
    if (pendingChat) {
      setComposerFeedback("上一条消息仍在等待服务器确认，请勿重复提交。", "error");
      return;
    }

    const draftValue = messageInput.value;
    const text = messageInput.value.trim();
    if (!text) {
      setComposerFeedback("消息不能只包含空白字符。", "error");
      return;
    }

    if (!joined || !socket || socket.readyState !== WebSocket.OPEN) {
      setComposerFeedback("连接尚未恢复，消息没有发送。", "error");
      return;
    }

    let requestId;
    try {
      requestId = generateRequestId();
    } catch {
      setComposerFeedback("浏览器无法生成安全发送标识，消息没有发送。", "error");
      return;
    }

    const chatPacket = { type: "chat", text, requestId };
    if (replyTargetId) {
      chatPacket.replyToId = replyTargetId;
    }

    stopLocalTyping(true);
    pendingChat = {
      requestId,
      draftValue,
      replyToId: replyTargetId,
      timer: null,
    };
    unknownSendOutcome = false;
    setComposerEnabled(true);
    // Render only the server echo with its authoritative ID and timestamp.
    if (!send(chatPacket)) {
      pendingChat = null;
      setComposerEnabled(true);
      setComposerFeedback("发送失败，消息仍保留在输入框中。", "error");
      return;
    }

    pendingChat.timer = window.setTimeout(() => {
      if (pendingChat?.requestId === requestId) {
        markPendingChatUnknown("服务器确认超时；发送结果暂时未知，草稿已保留且不会自动重发。");
      }
    }, CHAT_CONFIRM_TIMEOUT_MS);
    setComposerFeedback("正在等待服务器确认…");
  });

  messageInput.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && !event.shiftKey && !event.isComposing && !isComposing) {
      event.preventDefault();
      messageForm.requestSubmit();
    }
  });

  messageInput.addEventListener("input", () => {
    resizeMessageInput();
    updateMessageCount();
    noteLocalTyping();
    if (composerFeedback.dataset.tone === "error" && !unknownSendOutcome) {
      setComposerFeedback();
    }
  });

  messageInput.addEventListener("compositionstart", () => {
    isComposing = true;
    noteLocalTyping();
  });
  messageInput.addEventListener("compositionend", () => {
    isComposing = false;
    noteLocalTyping();
  });
  messageInput.addEventListener("blur", () => stopLocalTyping(true));

  cancelReplyButton.addEventListener("click", () => {
    clearReply();
    messageInput.focus({ preventScroll: true });
  });

  membersButton.addEventListener("click", () => {
    membersBackdrop.hidden = false;
    membersButton.setAttribute("aria-expanded", "true");
    membersPanel.focus({ preventScroll: true });
  });
  membersCloseButton.addEventListener("click", () => {
    closeMembersPanel();
    membersButton.focus({ preventScroll: true });
  });
  membersBackdrop.addEventListener("click", (event) => {
    if (event.target === membersBackdrop) {
      closeMembersPanel();
      membersButton.focus({ preventScroll: true });
    }
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !membersBackdrop.hidden) {
      closeMembersPanel();
      membersButton.focus({ preventScroll: true });
    }
  });

  messages.addEventListener("scroll", updateLatestNotice, { passive: true });
  latestButton.addEventListener("click", () => scrollToLatest());

  reconnectButton.addEventListener("click", () => {
    clearReconnectTimer();
    reconnectAttempts = 0;
    suppressReconnect = false;
    focusAfterHistory = false;
    connect();
  });

  leaveButton.addEventListener("click", leaveRoom);

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
      pruneExpiredRenderedMessages();
      if (socket?.readyState === WebSocket.OPEN) {
        send({ type: "ping", ts: Date.now() });
      }
    }
  });

  expiryTimer = window.setInterval(pruneExpiredRenderedMessages, MESSAGE_EXPIRY_CHECK_INTERVAL_MS);

  window.visualViewport?.addEventListener("resize", updateViewportHeight, { passive: true });
  window.addEventListener("resize", updateViewportHeight, { passive: true });
  updateViewportHeight();

  window.addEventListener("beforeunload", () => {
    pageUnloading = true;
    clearReconnectTimer();
    stopHeartbeat();
    if (expiryTimer !== null) {
      window.clearInterval(expiryTimer);
      expiryTimer = null;
    }
    clearPendingChatTimer();
    stopLocalTyping(false);
    clearRemoteTyping();
    const closingSocket = socket;
    socket = null;
    closingSocket?.close(1000);
  });
})();
