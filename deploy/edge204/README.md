# 204 营销退订入口

公开入口为 `https://zmx.xyz.hr/marketing/unsubscribe`。普通 GET 只展示确认页面；确认 POST 与邮件客户端 One-Click POST 写入同一份退订记录。已退订页面提供“重新订阅”按钮，进入 `https://zmx.xyz.hr/marketing/resubscribe` 再由客户确认 POST。两个操作都需要客户原邮件中的随机令牌；数据库仅保存其 SHA-256，不在 URL 放邮箱或客户资料。

数据保存在 `/var/lib/yxhk-unsubscribe/records.sqlite`，SQLite WAL 与事务保证持久性。请将整个状态目录纳入现有备份，避免只复制正在写入的主数据库文件。凭据在 `/etc/yxhk-unsubscribe/config.json`，不进入 Git。

`POST /internal/prepare` 返回邮箱是否退订；未退订时签发公开链接。`POST /internal/check` 在 SMTP 投递前再次确认。`GET /internal/events?after=<cursor>` 返回该租户的持久退订或重新订阅事件，最多 200 条；YXHK 按顺序同步 DNC 后才保存游标，失败重试不会丢事件。重新订阅只清除标记为“客户通过 204 退订营销邮件”的 DNC，保留退信和管理员限制。

内部接口经 204 Tailscale 网卡 `100.105.178.111:3192`，Authorization Bearer 密钥决定租户，调用者不能指定其他租户。公网 Nginx 仅发布退订、重新订阅和邮件追踪图片路径。不要把内部接口加入公网转发。

公网 Nginx 另发布 `GET /marketing/track/<追踪哈希>.gif`，只把邮件打开追踪图片转发到工作站 `100.123.30.26:3034` 的 Mautic `/email/<追踪哈希>.gif`；不开放后台、其他 Mautic 路由或内部退订 API。工作站需要保持 Tailscale 在线。YXHK 的邮件队列仅为新发 HTML 邮件改写图片地址，旧邮件中的 localhost 图片无法追溯修复。

YXHK 租户运行目录 `mail-queue/unsubscribe.json` 需要 `internal_url` 与 `api_key`，例如内部地址 `http://100.105.178.111:3192`。每个租户分配独立密钥；退订覆盖该租户所有营销批次和发件账号，保留邮箱大小写归一化规则，不擅自删除点号或加号。

未配置、同步失败或检查失败时不投递营销邮件；暂停后由管理员恢复。每 5 秒同步，发送前另查一次。已交给 SMTP 的邮件不能撤回，退订与并发投递之间有最后查询到 SMTP 接收的短暂时间窗口。

部署：`deploy/edge204/Deploy-Unsubscribe204.ps1` 发布当前提交的独立服务，不重启 APIsub。YXHK 使用 PXYOPS 的规范工作站发布和激活入口。运行 `python -m unittest discover -s deploy/edge204 -p test_unsubscribe.py` 验证确认、One-Click、幂等、租户隔离与持久记录。
