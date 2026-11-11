# Phase 5B.1 部署与回滚

此文档仅说明后续人工部署顺序。本交付包不会修改生产 Nginx、systemd 单元、服务环境变量或运行中的 Workerman 内存状态。

## 部署前

1. 核对 ZIP 的 SHA-256 与交付记录一致，并在临时目录解压检查清单。
2. 备份当前应用目录或记录当前发布目录的不可变副本；不要覆盖 systemd 单元和生产环境配置。
3. 确认 `server/composer.lock` 存在。包内 `vendor/` 与该锁文件一致；若目标机策略要求重建依赖，应在新发布目录执行 `composer install --no-dev --classmap-authoritative`，不要更新依赖版本。
4. 先运行 `php -l`、静态检查与必要的本地/预发布 WebSocket 测试。

## 人工部署顺序

1. 将 `public/`、`server/` 和必要文档放入新的发布目录；保留生产 systemd 环境变量与 Nginx 配置，暂时继续提供旧 `public/`。
2. 先让既有 systemd 服务指向新 `server/` 并执行一次受控重启，例如 `sudo systemctl restart <现有服务名>`；不要改写单元中的生产变量。
3. 用 `systemctl status`/`journalctl` 检查非敏感启动诊断，并确认 WebSocket 加入及 `chat_result` 正常。
4. 后端健康后，再原子切换网站公开根目录到新 `public/`。新服务端兼容不带 `requestId` 的旧前端；新前端若先遇到旧服务端，只能把发送结果标为未知。
5. 禁止手动运行第二个 `php server/bin/chat-server.php start`；监听地址仍是 `127.0.0.1:2346`。
6. 最后完成 Origin、加入、发送确认、历史、跨房间隔离和断线草稿保护冒烟测试。

Workerman 重启会按设计清空全部内存消息、房间、成员、输入状态和匿名身份签名密钥。部署窗口前应明确告知这一临时记录边界。

## 回滚

1. 将应用目录或发布软链接切回部署前备份。
2. 使用同一个 systemd 服务再次受控重启。
3. 重做加入、聊天、历史隔离和 Origin 冒烟测试。

回滚同样会重启 Worker，因此不能恢复部署前的内存消息。不要用旧 `server.zip` 覆盖本次源码；本阶段交付包固定命名为 `metchat-phase5b1-deploy.zip`。
