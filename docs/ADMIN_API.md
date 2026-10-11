# 设备管理 API

Vue 3 管理端部署在 `checkin.voxel.cn`，从同源 `/runtime-config.json` 自动获取部署中的一个或多个正式后端。一个后端自动连接，多个后端自动生成切换项；浏览器通过对应 `/backends/bN/admin-api/` 访问 Nginx。当前部署配置为 B0 `https://x.b0.01xr.com` 和 B1 `https://x.b1.01xr.com`。设备来自所选后端现有 v2 `device` 表，设备游戏映射到 `setup.scene_id`，游戏列表来自 A1 `/v1/server/checkin?expand=verse_id,name` 的 `verse_id` 和 `name`。

## 登录与配置

默认管理员用户名为 `dirui`。初始密码采用用户提供的密码，代码只保存其 `password_hash` 散列。可通过服务器环境变量覆盖：

- `ADMIN_USERNAME`：管理员用户名。
- `ADMIN_PASSWORD_HASH`：PHP `password_hash()` 生成的密码散列，不能设置成明文密码。

PHP 可从标准输入读取新密码并输出散列：

```sh
php -r 'echo password_hash(rtrim(fgets(STDIN), "\r\n"), PASSWORD_DEFAULT), PHP_EOL;'
```

把得到的完整散列作为 `ADMIN_PASSWORD_HASH` 设置在服务器环境中。Shell 中设置包含 `$` 的散列时应使用单引号，防止变量替换。

后台使用独立 Session 标记，登录不会修改微信用户身份。登录和退出都会轮换 Session ID，登录有效期为 8 小时。所有修改请求保留 Yii CSRF 验证。客户端先获取 `/admin-api/session`，保存返回的 `csrfToken`，通过 `X-CSRF-Token` 请求头发送。登录、退出返回新的 Token，前端应立即替换。请求使用 `credentials: 'same-origin'`；生产环境应通过 HTTPS 同源访问。

同一客户端 IP 在 15 分钟内最多允许 5 次失败的登录尝试，之后返回 HTTP 429 及 `Retry-After`。登录节流和场景短缓存使用 `api/runtime` 下的文件缓存，不要求 Redis 可用。`/admin-api` 路由在 debug/gii 提前初始化时同样跳过可选 Redis 路由缓存。Session 存储路径及 `api/runtime` 必须对 PHP 进程可写。Bootstrap 已提前启动 Session 时，后台会保存并重新打开 Session，确保 HttpOnly、SameSite=Lax、HTTPS Secure 和 8 小时时限生效。

## 微信小程序扫码登录

网页二维码由“不加班”小程序内部扫描：打开小程序“我的”页，点击“扫码登录设备后台”，扫描网页二维码并确认目标后端。此入口不依赖微信开放平台 PC OAuth；普通微信“扫一扫”不会直接打开本次确认页。密码登录继续可用。

每个正式 Yii 后端分别配置：

| 变量 | 含义 |
| --- | --- |
| `ADMIN_SCAN_LOGIN_ORIGIN` | 该后端的规范 HTTPS origin，例如 B0 的 `https://x.b0.01xr.com` 或 B1 的 `https://x.b1.01xr.com`；不包含路径、查询或用户凭据 |

未配置有效的规范 origin 时扫码登录关闭，`GET /admin-api/session` 的 `scanLoginEnabled` 为 false。**只有网页账号密码登录是全量管理员**，默认账号为 `dirui`；微信扫码固定返回 `role: device_manager`，只能读取及修改 `control.user_id` 关联的设备。微信用户的 `manager`、`root` 角色不能提升此权限。无关联设备可登录但返回空列表，撤销关联后下一次读写立即失去该设备权限，删除微信用户后会话失效。不设置微信管理员名单或配置界面。

发布前执行新增迁移 `m261011_120000_create_admin_scan_login_ticket_table`。部署环境先运行 `php yii migrate/new` 核对待执行迁移，再按既有数据库发布流程执行 `php yii migrate --interactive=0`；此命令会执行所有待运行迁移，必须确认清单和数据库备份。配置 `ADMIN_SCAN_LOGIN_ORIGIN` 的后端需要为同一个 `bujiaban-mp` AppID 配置微信 code 交换凭据。若 B0/B1 共库，票据表迁移仅需在该库执行一次，两个服务仍各自配置规范 origin 并保留独立浏览器 Session；2026-10-11 已核对 Portainer 的运行栈：B0/B1 以及 x.4mr.cn 都路由到同一 wechat 服务，使用同一数据库，因此本部署只迁移一次、使用一个规范扫码 origin；浏览器代理路径仍隔离 Session。

扫码版本的后端发布范围包括新增票据服务、迁移和 `v2/WebLoginController`，以及管理路由、`AdminAuthService`、v1 模块身份入口、v1/v2 User 与刷新控制器的认证修复；这些文件按同一版本发布，保持签发和认证的身份来源规则一致。Web 和独立小程序仓库分别构建发布。

登录票据有效期五分钟，数据库只保存批准 secret 的哈希，并绑定创建浏览器和服务端规范 origin。小程序只负责批准；状态轮询不建立登录会话，浏览器另发带 CSRF 的消费请求。刷新、取消、成功密码登录和退出会使相应旧票据失效，消费只能成功一次。扫码成功建立八小时设备管理 Session，并轮换 Session ID 和 CSRF。二维码 secret 只出现在二维码协议字段及批准请求中，不进入页面导航 URL、日志或埋点；微信 Token 和 Session ID 同样不进入这些位置。

| 方法 | 路径 | 凭据与返回 |
| --- | --- | --- |
| POST | `/admin-api/scan-login/tickets` | 浏览器 Session + CSRF；返回 `{ticketId, qrText, expiresAt}` |
| GET | `/admin-api/scan-login/tickets/<id>` | 仅创建浏览器；返回 `{status, expiresAt}` |
| POST | `/admin-api/scan-login/tickets/<id>/consume` | 创建浏览器 + CSRF；返回 `{user, csrfToken}` |
| POST | `/admin-api/scan-login/tickets/<id>/cancel` | 创建浏览器 + CSRF；返回 `{status, expiresAt}`，保留已过期或已消费的终态 |
| POST | `/v2/web-login/tickets/<id>/approve` | 目标后端 Bearer + `{secret}`；有效微信用户批准自己的设备管理登录，沿用 v2 业务响应包装 |

`expiresAt` 为 ISO 8601 时间；票据状态为 `pending`、`approved`、`consumed`、`cancelled` 或 `expired`。已批准但尚未兑换的票据同样受原截止时间约束。网页消费期间锁定环境切换和登录方式切换，避免迟到的 Cookie 响应影响另一轮登录。

登录响应的 `user` 为 `{username, role}`：密码登录的 `role` 为 `admin`，扫码登录为 `device_manager`；角色由服务端 Session 来源派生，不接受请求或客户端提供的角色。设备范围在服务层执行，页面隐藏不能替代鉴权。

本次同时修复 v1/v2 刷新 Token 和身份查询对签名、有效期的验证。两套身份共用签名密钥，现通过签名内的 `identityDomain` 区分 `v1-player` 与 `v2-user`，防止不同用户表中的同一数字 ID 相互冒用。**发布后，旧的无身份来源标记的 v1/v2 Token 将失效，用户需要重新登录**；刷新接口不能将旧 Token 或其他身份域转换成管理员凭据。v1 刷新接口同时停止记录包含 Token 的请求内容。微信登录没有 unionid 时仅允许按微信服务端返回的 openid 匹配已有用户，无法匹配时明确失败；不接受客户端自报微信身份。

微信 Bearer 对原 `/v2/devices` 的列表、详情、管理列表和更新，以及 `/v2/setups` 的 CRUD，同样按实时 `control.user_id` 限制。Setup 更新禁止重绑 `device_id`。设备创建、删除、assign/unassign 和 Root/Manager 分配入口一律拒绝微信身份，包括 root；小程序已移除相关全局管理入口。v2 不接受网页管理员 Session 代替 Bearer。

## 接口

所有成功响应均直接返回 JSON，省略 `success` / `data` 包装。响应带 `Cache-Control: no-store`。

| 方法 | 路径 | 请求 | 返回 |
| --- | --- | --- | --- |
| GET | `/admin-api/session` | 无 | `{user: {username, role} \| null, csrfToken, scanLoginEnabled}` |
| POST | `/admin-api/login` | `{username: string, password: string}` | `{user: {username, role}, csrfToken}` |
| POST | `/admin-api/logout` | 无 | `{user: null, csrfToken}` |
| GET | `/admin-api/devices` | 必须登录 | 设备数组 |
| GET | `/admin-api/games` | 必须登录 | 游戏数组 |
| PUT | `/admin-api/devices/<id>/game` | `{gameId: number}`，必须登录 | 更新后的单个设备 |

`POST` 和 `PUT` 请求应带 `X-CSRF-Token`；JSON 请求应带 `Content-Type: application/json`。

设备格式示例：

```json
{
  "id": 1,
  "uuid": "device-a",
  "name": "大厅一号",
  "ip": "192.168.1.10",
  "status": "unknown",
  "updatedAt": "2026-10-11 12:30:00",
  "gameId": 638
}
```

`name` 优先使用设备 `tag`，其次为 `uuid`。没有 `setup` 或未配置 `scene_id` 时 `gameId` 为 `null`。现有模型没有可信的在线心跳字段，因此 `status` 始终为 `unknown`。`updatedAt` 优先取配置更新时间，其次为设备更新时间，并沿用数据库的时间格式。设备总数是返回数组长度。读取设备列表不会创建缺失的 `setup`。

游戏格式示例：

```json
{"id": 638, "name": "星际漫游", "description": "", "category": ""}
```

示例名称用于说明格式。真实目录为空时 API 返回 `[]`，管理端显示没有可分配的游戏，不生成或混入示例游戏。实时目录内容取决于 A1 服务；演示模式的数据仅保存在演示环境中。

`id` 对应 A1 的 `verse_id`，未提供的描述、分类为空字符串。A1 直接数组及 `data`、`items`、`results` 包装均可归一化。游戏列表缓存 30 秒，A1 失败返回 503。设置设备游戏前会检查编号属于当前游戏列表，再在数据库事务中创建或更新 `setup`；已有金额、文案、图片、拍照次数等字段保持原值。首次创建配置时会填充现有设备协议的默认标题、图片、金额、口号、缩略图和拍照次数，随后覆盖选中的游戏编号。保存响应中的 `updatedAt` 从数据库回读，与后续设备查询保持一致。

A1 目录通过固定服务地址及明确的 `page` / `per-page=15` 参数读取全部分页，使用响应分页头确定页数，不跟随 `Link` 中的任意 URL。整个读取限制为 15 秒、最多 100 页；超限、任一后续页失败、JSON 无效或分页总数不一致都会返回 503，不返回或缓存部分目录。

## 错误

失败通过 HTTP 状态码和 JSON `message` 表达。前端不应依赖 Yii 的其他错误字段。

| 状态码 | 含义 |
| --- | --- |
| 400 | CSRF 缺失或失效、JSON 格式错误 |
| 401 | 未登录、登录已过期、用户名或密码错误 |
| 404 | 设备不存在或路由不存在 |
| 405 | 请求方法不允许 |
| 422 | 请求字段无效、游戏编号不属于场景列表、模型验证失败 |
| 429 | 登录尝试过于频繁 |
| 503 | 数据库或 A1 场景源暂时不可用 |

## 本地验证

无需部署专用的 `api/config`，使用独立 Yii Application 和 SQLite 内存库运行后台测试：

```sh
php api/vendor/bin/phpunit --stderr --bootstrap api/tests/_bootstrap.php --filter 'AdminApiTest|AdminSceneFetcherTest|AdminScanLoginTest' api/tests/unit/components
```

测试覆盖登录、Session 轮换和过期、提前启动 Session 的实际 PHP Cookie 参数、环境变量覆盖、无 Redis 登录、真实 debug/gii 路由初始化、CSRF、真实错误处理器 JSON 响应、后台与微信身份隔离、只读设备查询、场景归一化与分页、有效游戏校验、字段保留、首次配置的真实设备协议默认值、时间回读一致性和事务失败回滚。测试中的独立账号不使用生产密码。

真实数据联调需要现有数据库、已执行的 v2 设备和配置表迁移、可写 PHP Session 存储，以及能访问 A1 的服务器网络。扫码登录另需上述票据表迁移、规范 origin、微信凭据和小程序发布。

## 前端与正式后端部署

`checkin.voxel.cn` 只运行静态 Vue 页面和 Nginx，不运行 PHP、不连接数据库。`dockerfile/FrontendDockerfile` 使用官方 Nginx Alpine 镜像，复制预构建的 `frontend/dist`、代理模板和启动脚本。`dockerfile/admin.compose.yaml` 仅连接现有 `proxy` 网络，通过 Traefik 的 `websecure` / `letsencrypt` 提供 HTTPS，不发布宿主机端口，不配置数据库凭据、Cookie key 或持久化卷。

前端以根路径构建：`npm run build -- --base=/`。Portainer 的最小构建 tar 应包含以下路径，将 `dockerfile/FrontendDockerfile` 在 tar 根目录命名为 `Dockerfile`，其余路径保持不变：

- `frontend/dist/`
- `Dockerfile`（源文件为 `dockerfile/FrontendDockerfile`）
- `dockerfile/frontend-nginx.conf.template`
- `dockerfile/40-configure-frontends.sh`

构建包不能携带仓库根 `.dockerignore`，因为通用构建忽略规则会排除这次预构建的 `frontend/dist`。镜像不包含后端源码、Composer vendor 或数据库配置。

运行时扫描 `APP_API_N_URL` 全部数字编号配置，按数字排序，允许编号缺口；编号 N 固定对应 b(N−1)。镜像不内置后端默认值，至少显式配置一个有效地址。启动脚本只接受 HTTPS origin，可包含端口；拒绝空值、路径、查询、用户凭据及配置注入字符。它统一生成隔离代理和公开清单，清单禁止缓存且只包含 id/name/host。增减后端只需修改部署变量并更新容器、刷新页面，不用重新构建 Vue；更改 DNS 地址后重启容器以重新解析。管理接口采用独立 Session，每次操作固定到所选环境，失败请求不会自动改投另一个环境。

代理校验上游 TLS 证书和主机名，发送对应的 Host 与 SNI，使用系统 CA，不忽略证书错误。上游 Cookie 的 Path 统一改为对应的 `/backends/bN/`，已有 Domain 改为当前前端域名；无 Domain 的 Cookie 保持同源。Cookie 启用 Secure、HttpOnly、SameSite=Lax。这样同名 Session 和 CSRF Cookie 可在各环境独立保存；切换环境后应重新获取该环境的 Session 和 CSRF Token。

`/healthz` 仅返回容器静态健康状态。每个配置项生成 `/backends/bN/health`，只读访问对应后端的 `/health`，只接受 GET / HEAD，去掉查询参数、请求体、Cookie 和 Authorization，并隐藏上游 Set-Cookie。后端健康接口可用不表示管理接口已经部署。所有其他后端路径和 PHP、配置、数据库等源文件路径均不提供访问。

正式 Yii 后端仍需分别发布本仓库的 `AdminController`、`AdminAuthService`、`AdminDeviceService`、`AdminGameCatalog`、`AdminSceneFetcher`、`UrlManager` 及 `files/api/config/web.php` 中的管理路由，沿用各自正式环境已有的数据库配置和应用部署流程。首次对接前，B0、B1 的 `/admin-api/session` 均返回 404；前端会显示真实连接错误，不用演示数据替代。2026-10-11 已通过 develop → main → publish CI 发布管理接口，两端均自动更新到 `bb9a4e3`，并通过前端代理验证了 Session、登录、设备与场景查询。两端当前均返回 33 台设备与空场景目录。线上联调只读，不为测试而改动真实设备的游戏。

Nginx 配置和代理的集成测试使用本地 HTTPS 服务及测试 CA，覆盖 Host/SNI、TLS 校验、路径和查询、写入请求保持原后端、Cookie 隔离、只读健康检查和源文件屏蔽。指定可用 Nginx 二进制后运行：

```sh
NGINX_TEST_BINARY=/path/to/nginx python3 dockerfile/tests/test_frontend_proxy.py
```

后台接口测试继续使用上文的 PHPUnit 命令，无需任何前端专用 PHP 入口或配置。
