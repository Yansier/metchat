(() => {
  "use strict";

  const joinPanel = document.querySelector("#joinPanel");
  const chatPanel = document.querySelector("#chatPanel");
  const joinForm = document.querySelector("#joinForm");
  const joinButton = document.querySelector("#joinButton");
  const nicknameInput = document.querySelector("#nickname");
  const roomCodeInput = document.querySelector("#roomCode");
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
  const ROOM_CODE_PATTERN = /^[A-Za-z0-9_-]{8,64}$/;
  const DEFAULT_COMPOSER_HINT = "Enter 发送 · Shift + Enter 换行";

  const JOIN_ERROR_MESSAGES = {
    invalid_room_code: "房间暗号格式无效，请检查长度、字符和大小写。",
    room_capacity: "当前可用房间数量已满，请稍后再试。",
    room_full: "这个房间已经满员，请稍后再试。",
    server_capacity: "服务器连接容量已满，请稍后再试。",
    too_many_join_attempts: "加入尝试过于频繁，请稍后重新连接。",
    invalid_nickname: "请输入有效昵称。",
    nickname_too_long: "昵称不能超过 24 个字符。",
  };

  let socket = null;
  let nickname = "";
  let roomCode = "";
  let roomId = "";
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

  // IDs exist only for this page lifetime and are never persisted.
  const seenMessageIds = new Set();

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
    sendButton.disabled = !enabled;

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
      && typeof packet.sentAt === "string";
  }

  function appendChat(packet, { autoScroll = true } = {}) {
    if (!isValidMessage(packet) || seenMessageIds.has(packet.id)) {
      return false;
    }

    const shouldScroll = isNearBottom();
    const item = document.createElement("li");
    item.className = "message";
    item.dataset.messageId = packet.id;

    const meta = document.createElement("p");
    meta.className = "message__meta";

    const sender = document.createElement("span");
    sender.className = "message__nickname";
    sender.textContent = packet.nickname;
    meta.append(sender, document.createTextNode(` · ${formatTime(packet.sentAt)}`));

    const body = document.createElement("p");
    body.className = "message__body";
    body.textContent = packet.text;

    item.append(meta, body);
    messages.append(item);
    seenMessageIds.add(packet.id);
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

    socket = null;
    joined = false;
    stopHeartbeat();
    setComposerEnabled(false);
    if (roomId) {
      roomStatus.textContent = `ID ${roomId} · 已断开`;
    }

    const reconnectScheduled = scheduleReconnect();
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
        applyHistory(packet.messages);
        setComposerEnabled(true);
        setComposerFeedback();
        setStatus("online", "已连接");
        if (focusAfterHistory && window.matchMedia("(pointer: fine)").matches) {
          messageInput.focus({ preventScroll: true });
        }
        focusAfterHistory = false;
        break;
      case "chat":
        appendChat(packet);
        setComposerFeedback();
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
      send({ type: "join", nickname, roomCode });
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
    messages.querySelectorAll(".message, .system-message").forEach((item) => item.remove());
    seenMessageIds.clear();
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

    const closingSocket = socket;
    socket = null;
    joined = false;
    closingSocket?.close(1000, "user left room");

    clearRoomMessages();
    nickname = "";
    roomCode = "";
    roomId = "";
    reconnectAttempts = 0;
    focusAfterHistory = false;
    nicknameInput.value = "";
    roomCodeInput.value = "";
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
    const text = messageInput.value.trim();
    if (!text) {
      setComposerFeedback("消息不能只包含空白字符。", "error");
      return;
    }

    if (!joined || !socket || socket.readyState !== WebSocket.OPEN) {
      setComposerFeedback("连接尚未恢复，消息没有发送。", "error");
      return;
    }

    // Render only the server echo with its authoritative ID and timestamp.
    if (!send({ type: "chat", text })) {
      setComposerFeedback("发送失败，消息仍保留在输入框中。", "error");
      return;
    }

    messageInput.value = "";
    messageInput.rows = 1;
    updateMessageCount();
    setComposerFeedback("已提交，等待服务器确认。", "success");
    scrollToLatest();
    messageInput.focus({ preventScroll: true });
  });

  messageInput.addEventListener("keydown", (event) => {
    if (event.key === "Enter" && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      messageForm.requestSubmit();
    }
  });

  messageInput.addEventListener("input", () => {
    resizeMessageInput();
    updateMessageCount();
    if (composerFeedback.dataset.tone === "error") {
      setComposerFeedback();
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
    if (document.visibilityState === "visible" && socket?.readyState === WebSocket.OPEN) {
      send({ type: "ping", ts: Date.now() });
    }
  });

  window.visualViewport?.addEventListener("resize", updateViewportHeight, { passive: true });
  window.addEventListener("resize", updateViewportHeight, { passive: true });
  updateViewportHeight();

  window.addEventListener("beforeunload", () => {
    pageUnloading = true;
    clearReconnectTimer();
    stopHeartbeat();
    const closingSocket = socket;
    socket = null;
    closingSocket?.close(1000);
  });
})();
