# Private Ephemeral Chat

一个按共享暗号隔离房间、实时转发并在 PHP Workerman 进程内存中保留短期历史的轻量私人聊天室。每个房间最多保留最近 50 条、24 小时内的消息；不使用数据库、文件或浏览器持久化存储。Workerman 重启后所有房间和历史自然清空。

前端采用无框架的深色响应式界面，包含明确的连接状态、权威在线连接列表、输入中提示、引用回复、历史加载、有限重连、主动退出、消息长度提示和“回到最新消息”。服务端签发仅限当前浏览器标签页会话的匿名身份凭证，并为每个房间派生不同的公开 `senderId`，因此实时消息和恢复历史都能准确区分自己与其他人，而不依赖昵称。

## 目录

```text
private-ephemeral-chat/
├── public/                         # Nginx 唯一公开目录
│   ├── index.html
│   ├── room-code.js
│   ├── style.css
│   └── app.js
├── server/                         # 不可通过网站访问
│   ├── bin/chat-server.php
│   ├── config/chat.php
│   ├── src/ChatRoom.php
│   ├── src/AnonymousIdentity.php
│   ├── src/ClientIpResolver.php
│   ├── src/IpRateLimiter.php
│   ├── src/MessageHistory.php
│   ├── src/OriginPolicy.php
│   ├── src/PrivacyWebsocket.php
│   ├── src/RoomManager.php
│   ├── composer.json
│   ├── composer.lock               # composer install 后生成
│   └── vendor/                     # composer install 后生成
├── deploy/
│   └── nginx-private-chat.conf
├── tests/
│   ├── integration.php
│   ├── anonymous-identity.php
│   ├── message-history.php
│   ├── room-manager.php
│   ├── security-integration.php
│   ├── security-unit.php
│   └── static-checks.ps1
├── .gitignore
└── README.md
```

## 协议

所有消息均为 JSON。客户端发送：

```json
{"type":"join","nickname":"Alice","roomCode":"261110Achat"}
{"type":"join","nickname":"Alice","roomCode":"261110Achat","identityToken":"可选的服务端签发凭证"}
{"type":"chat","text":"你好"}
{"type":"chat","text":"我同意","replyToId":"32位服务端消息ID"}
{"type":"typing","active":true}
{"type":"typing","active":false}
{"type":"ping","ts":1234567890}
{"type":"pong"}
```

服务端发送 `join`、`history`、`chat`、`presence`、`members`、`typing`、`error`、`pong`。成功加入后的顺序为：

1. `join`：加入确认，包含非敏感 `roomId`、房间专属 `senderId`、续期后的 `identityToken` 和凭证到期时间；完整暗号不会回传。
2. `history`：从旧到新的有效历史快照。
3. `presence` 和带递增 `version` 的 `members`：当前房间权威在线连接快照。
4. 后续 `chat`、`typing`：当前房间实时事件。

历史和实时消息都包含服务端生成的 `id`、`nickname`、`text`、`sentAt`、`senderId`，引用消息额外包含 `replyToId`。`chat` 只向当前房间广播，并权威回显给发送者；前端按服务器消息 ID 去重，并只通过 `senderId` 判断左右气泡。旧格式消息缺少 `senderId` 时使用中性样式。

暗号规则：8–64 位，只允许英文字母、数字、下划线和连字符，区分大小写。服务端只把暗号转换为带进程随机密钥的内部哈希，不把完整暗号写入消息、URL 或日志。

加入页可以用 Web Crypto `crypto.getRandomValues()` 生成默认 24 位暗号。字符集正好兼容服务端规则，并使用拒绝采样避免取模偏差；生成操作只填入表单，不会自动加入或保存暗号。

## 匿名临时身份

- 首次加入时服务端生成 256 位随机匿名主体，并返回 HMAC-SHA-256 签名凭证。
- 凭证默认有效 7 天，每次成功加入都会续期；无效、伪造、过期凭证会安全降级为新身份。
- 签名密钥和 senderId 派生密钥均为 Worker 进程随机密钥，重启后旧凭证自动失效。
- 服务端不保存身份档案；连接内只保留当前房间必要的 `senderId`。
- 同一凭证在不同房间派生不同 `senderId`，不能跨房间关联，也不能替代房间暗号。
- `identityToken` 只在自己的 join 确认中返回，不会进入聊天、历史、presence 或其他用户的响应。
- 浏览器只在 `sessionStorage` 保存这一项凭证，不保存昵称、暗号、消息或历史；主动退出会删除凭证。
- `sessionStorage` 是标签页会话存储，不是账户隔离。浏览器“复制标签页”或带 `opener` 打开的页面可能复制初始存储，因此持有凭证者可被视为同一匿名身份。

## 在线连接、输入状态与引用

- `memberId` 是每条 WebSocket 连接独立生成的 128 位随机公开标识；相同 `senderId` 的两个标签页仍显示为两条连接。
- `members` 直接从 `ChatRoom` 当前连接状态生成，包含 `nickname`、`senderId`、`memberId`、`joinedAt` 和 `lastSeenAt`；不包含 IP、User-Agent、暗号或凭证。
- 成员加入、退出、异常断开或心跳超时后都会发布新版本快照；客户端忽略旧版本，在线数量必须等于快照数组长度。
- `typing` 只保存连接内的布尔状态和五秒到期时间，不包含实际输入内容，不进入历史；前端每三秒至多续期一次，服务端还有独立频率限制。
- 引用回复只保存并广播经过当前房间有效历史验证的 `replyToId`，不复制原正文。目标过期或被 50 条上限淘汰后，回复仍可存在，但界面只显示目标不可用提示。
- 在线成员和输入状态均为 Worker 内存数据，进程重启后全部清空。

## 本地启动（Linux）

要求 PHP 8.2 CLI、`posix`/`pcntl` 扩展与 Composer。Workerman 面向 POSIX 环境运行；本项目生产目标也是 Linux。

```bash
cd server
composer install --no-dev --optimize-autoloader
php bin/chat-server.php start
```

另开终端启动静态页面：

```bash
php -S 127.0.0.1:8080 -t public
```

浏览器打开 `http://127.0.0.1:8080`。本地页面会直接连接 `ws://127.0.0.1:2346`。

本地开发仅允许精确的 `http://127.0.0.1:8080` 与 `http://localhost:8080` Origin。若静态页面使用其他端口，必须显式设置，例如：

```bash
CHAT_ALLOWED_ORIGINS=http://127.0.0.1:9000 php bin/chat-server.php start
```

常用服务命令：

```bash
php server/bin/chat-server.php start -d
php server/bin/chat-server.php status
php server/bin/chat-server.php stop
php server/bin/chat-server.php restart
```

## 测试

服务启动后运行黑盒集成测试：

```bash
php tests/message-history.php
php tests/room-manager.php
php tests/anonymous-identity.php
php tests/security-unit.php
php tests/integration.php
```

测试覆盖凭证签名/伪造/续期/过期/重启失效、同昵称身份分离、权威成员快照、旧/新连接重叠清理、输入状态隔离/过期/限流、引用验证/恢复/过期降级、房间专属 senderId、跨房间实时/历史隔离、每房间独立 50 条历史、未加入越权、限流和容量。

验证 Workerman 重启清空历史：

```bash
php tests/integration.php seed-restart
php server/bin/chat-server.php restart
php tests/integration.php verify-restart
```

Windows 可运行不依赖 PHP 的静态约束检查：

```powershell
powershell -ExecutionPolicy Bypass -File tests/static-checks.ps1
```

手工浏览器检查：

1. 桌面浏览器打开三个窗口：A、B 使用 `261110Achat`，C 使用 `261110Bchat`。
2. A 发消息，确认 B 立即收到而 C 没有收到；C 的消息也不进入 A/B 房间。
3. 观察 A/B 房间在线人数随加入和退出变化，C 始终只显示自己房间的人数。
4. 刷新 A、C 并分别重新输入原暗号，确认只恢复各自房间历史，且没有重复消息。
5. 全部关闭后重新打开，确认两个房间尚未过期的历史仍在。
6. 确认聊天区只显示短 `roomId`，不显示完整暗号。
7. 滚到较早消息后让同房间另一窗口发送消息：页面不应强制跳到底部，并应出现“新消息”按钮。
8. 点击“退出房间”：连接关闭、页面消息清空、自动重连停止，并返回加入页。
9. 发送纯空白、超过限制的内容以及 `<img src=x onerror=alert(1)>`：空白应拒绝，HTML 应作为普通文本显示且不能执行。
10. 暂停 WebSocket 服务，观察最多三次指数退避和“重连失败”；恢复服务后使用手动重连。
11. 使用非法暗号、已满房间及服务器连接已满场景，确认分别显示明确错误。
12. 在桌面、360px Android Chrome、iPhone Safari 和常见内置浏览器检查无横向溢出；软键盘弹出后输入框、发送按钮仍可见。
13. 连续点击随机暗号并确认每次只填入 24 位合法值，不会自动提交；验证复制成功、拒绝剪贴板权限后的手动选择提示和显示/隐藏按钮。
14. 两个窗口使用相同昵称加入同一房间，确认双方消息仍分别左右显示；刷新其中一个窗口并重新加入，确认自己的历史仍靠右。
15. 点击退出后在开发者工具中确认 `sessionStorage` 的匿名凭证已删除；确认其中没有暗号或消息正文。
16. 打开在线成员面板，确认同昵称、同身份的多个连接分别显示不同短连接标识，主动退出后立即消失。
17. A、B 同房间输入文字，确认输入提示只出现在对方窗口，停止输入、发送、断线和约五秒超时后消失。
18. 回复一条消息并刷新，确认引用关系恢复；将引用目标挤出最近 50 条后只显示“原消息已过期或不在最近记录中”。
19. 在移动端点击回复、取消回复和成员入口，确认底部抽屉及软键盘不会遮挡输入框。

消息交互约定：Enter 发送，Shift+Enter 换行。客户端只显示服务端回显的消息，不做乐观伪发送，也不会自动重试未确认的消息。

## Nginx / 宝塔准备（不执行部署）

示例见 `deploy/nginx-private-chat.conf`。关键点：

- 网站运行目录必须设置为项目的 `public/`，不能是项目根目录。
- `/ws` 反向代理至 `127.0.0.1:2346`，并透传 WebSocket Upgrade 头。
- `server/`、`vendor/`、`deploy/` 都位于公开目录之外。
- HTTPS 站点由浏览器自动使用 `wss://域名/ws`。
- 生产启动必须设置 `CHAT_ENV=production` 和精确的 `CHAT_ALLOWED_ORIGINS`；缺失 Origin 会被拒绝。
- 默认只信任来自 `127.0.0.1`/`::1` 的本机 Nginx，并只读取它设置的单值 `X-Real-IP`。不要让公网直接访问 2346，也不要把客户端传入的 `X-Forwarded-For` 原样转交为可信地址。
- 多域名使用英文逗号分隔，不支持通配符或子串匹配，例如 `CHAT_ALLOWED_ORIGINS=https://chat.example.com,https://private.example.com`。
- 宝塔进程守护示例应把环境变量写入服务环境，而不是聊天日志：`CHAT_ENV=production`、`CHAT_ALLOWED_ORIGINS=https://你的域名`、`CHAT_TRUSTED_PROXIES=127.0.0.1,::1`。

生产启动示意（未在本项目中执行部署）：

```bash
cd /path/to/project/server
export CHAT_ENV=production
export CHAT_ALLOWED_ORIGINS=https://chat.example.com
export CHAT_TRUSTED_PROXIES=127.0.0.1,::1
php bin/chat-server.php start -d
```

安全限流默认值（生产）：同一 IP 每 60 秒 40 次连接尝试、每 60 秒 30 次加入尝试、最多 24 个并发连接、每 10 秒 120 条聊天；单连接每 5 秒 20 条。计数只保存在单 Worker 内存中，5 分钟无活动且无并发连接后释放，最多追踪 1024 个 IP 哈希键。可通过 `CHAT_IP_*`、`CHAT_CONNECTION_CHAT_*` 环境变量做有边界的调整。

输入状态默认每连接 5 秒最多 12 次更新，状态 5 秒未续期自动清除；可通过 `CHAT_CONNECTION_TYPING_LIMIT` 和 `CHAT_CONNECTION_TYPING_WINDOW` 调整频率边界。

## 临时历史边界

- 没有数据库、消息文件或 Redis；不使用浏览器存储保存暗号、消息或历史。
- 浏览器存储的唯一例外是 `sessionStorage` 中的服务端签名匿名身份凭证；它不包含消息或房间暗号。
- 在线成员、输入状态和成员/输入事件序列只存在 Worker 内存中，不写入消息历史。
- 房间状态只存在单 Worker 进程的 `RoomManager` 数组中。
- 每个房间拥有独立 `MessageHistory`，最多 50 条。
- 每 60 秒定时清理过期消息；每次返回历史前也会再次过滤。
- 房间无人在线但仍有有效历史时继续保留；无成员且无历史时释放房间。
- 聊天正文不写入控制台或应用日志。
- 完整暗号不进入聊天正文、服务端响应、URL 或应用日志。
- 页面刷新会销毁 DOM，重新加入后从服务器恢复有效历史。
- Workerman 进程重启会销毁全部内存历史。

默认限制：100 个房间、64 个总连接、每房间 16 人、每连接最多 5 次加入尝试。可在 `server/config/chat.php` 调整。

## 安全边界

- Origin 校验用于阻止非白名单网页从浏览器发起连接，不是用户认证；房间暗号仍只是共享访问凭证。
- 真实 IP 只在 TCP 对端属于显式可信代理时从 `X-Real-IP` 读取；其他情况下忽略转发头。
- Workerman 在业务 JSON 解析前限制 WebSocket 包大小；JSON 深度、字段集合、消息字符数还会在应用层再次校验。
- 客户端不能提交消息 ID、权威昵称、服务器时间或目标房间；带额外权威字段的聊天包会被拒绝。
- 客户端提交的 `senderId` 会被严格字段白名单拒绝；身份凭证只允许出现在 join 中，并必须通过服务端签名校验。
- 客户端不能提交 `memberId`、他人的输入状态、引用昵称或引用正文；`replyToId` 必须匹配当前房间仍有效的服务端消息。
- 发送缓冲达到 512 KiB 时关闭慢客户端，防止单连接长期积压。
- 日志只输出事件类型，不输出 IP、聊天正文、完整暗号或原始帧。`PrivacyWebsocket` 专门拦截 Workerman 会打印原始非法 opcode 帧的诊断分支。

