# Private Ephemeral Chat

一个按共享暗号隔离房间、实时转发并在 PHP Workerman 进程内存中保留短期历史的轻量私人聊天室。每个房间最多保留最近 50 条、24 小时内的消息；不使用数据库、文件或浏览器持久化存储。Workerman 重启后所有房间和历史自然清空。

前端采用无框架的深色响应式界面，包含明确的连接状态、历史加载状态、有限重连、主动退出、消息长度提示和“回到最新消息”。由于当前协议没有稳定用户 ID，消息统一使用中性气泡，不依据相同昵称推断消息归属。

## 目录

```text
private-ephemeral-chat/
├── public/                         # Nginx 唯一公开目录
│   ├── index.html
│   ├── style.css
│   └── app.js
├── server/                         # 不可通过网站访问
│   ├── bin/chat-server.php
│   ├── config/chat.php
│   ├── src/ChatRoom.php
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
{"type":"chat","text":"你好"}
{"type":"ping","ts":1234567890}
{"type":"pong"}
```

服务端发送 `join`、`history`、`chat`、`presence`、`error`、`pong`。成功加入后的顺序为：

1. `join`：加入确认，包含非敏感 `roomId`，不回传完整暗号。
2. `history`：从旧到新的有效历史快照。
3. 后续 `chat`：实时消息。

历史和实时消息都包含服务端生成的 `id`、`nickname`、`text`、`sentAt`。`chat` 只向当前房间广播，并权威回显给发送者；前端按服务器消息 ID 去重。

暗号规则：8–64 位，只允许英文字母、数字、下划线和连字符，区分大小写。服务端只把暗号转换为带进程随机密钥的内部哈希，不把完整暗号写入消息、URL 或日志。

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
php tests/security-unit.php
php tests/integration.php
```

测试覆盖暗号大小写、跨房间实时/历史隔离、同昵称跨房间、每房间独立 50 条历史、过期房间清理、全员离线恢复、未加入越权、加入频率、房间/连接容量和进程重启清空。

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

## 临时历史边界

- 没有数据库、消息文件、Redis 或浏览器存储 API。
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
- 发送缓冲达到 512 KiB 时关闭慢客户端，防止单连接长期积压。
- 日志只输出事件类型，不输出 IP、聊天正文、完整暗号或原始帧。`PrivacyWebsocket` 专门拦截 Workerman 会打印原始非法 opcode 帧的诊断分支。

