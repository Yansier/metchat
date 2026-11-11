# Phase 5C 部署与回滚

本文档只供收到明确上线指令后的人工操作参考。本地交付不会修改生产 Nginx、systemd、SSL、环境变量或运行中的内存状态。

## 部署前

1. 核对 `metchat-phase5c-deploy.zip` 的 SHA-256，并在独立临时目录解压。
2. 备份线上当前稳定版的 `public/`、`server/` 与部署说明；记录当前发布版本或软链接目标。
3. 保留现有 Nginx、systemd 单元和生产环境变量，不从 ZIP 覆盖这些机器专属配置。
4. 确认包内 `server/composer.lock` 与 `server/vendor/` 齐全；也可按既有策略执行 `composer install --no-dev --classmap-authoritative`，不得更新锁定版本。
5. 在预发布或维护窗口执行 PHP 语法、Origin、join、创建恢复、跨房间隔离和聊天确认测试。

## 推荐上线顺序

1. 解压到新的发布目录，不直接覆盖当前稳定目录。
2. 先部署兼容旧 join/chat 客户端的新 `server/`。
3. 使用现有服务执行 `sudo systemctl restart metchat.service`；不得手动启动第二个 Workerman 实例。
4. 通过 `systemctl status metchat.service` 和非敏感日志确认单 Worker 正常监听 `127.0.0.1:2346`。
5. 后端验证通过后，原子切换网站公开目录到新 `public/`。
6. 冒烟验证普通房间、幽灵房间、自定义 TTL、创建确认丢失恢复、历史、成员、输入状态、引用及发送确认。

新服务端兼容不带 `createOptions`、身份凭证或 chat `requestId` 的旧客户端。部署重启会按设计清空全部内存房间、消息、成员、输入状态和进程签名密钥，应提前接受这一临时数据边界。

## 回滚

1. 将发布目录或软链接切回部署前备份。
2. 使用同一个 `metchat.service` 执行受控重启。
3. 恢复旧 `public/` 后重新检查 Origin、加入、实时聊天和历史隔离。

回滚同样会清空当前 Worker 内存，不能恢复重启前的临时消息。不要使用根目录旧 `server.zip` 覆盖任一版本。
