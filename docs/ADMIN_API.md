# 设备管理 API

Vue 3 管理端部署在 `checkin.voxel.cn`，浏览器统一通过同源 `/api/admin-api/` 访问 Nginx，用户无需选择后端。代理参考 `gdgeek/vue3.7dgame.com` 的编号上游、`split_clients` 和 `map` 方法自动分配请求。正式上游保留 B0 `https://x.b0.01xr.com` 和 B1 `https://x.b1.01xr.com`，两端校验同一 JWT，并共享管理登录的 Redis 令牌记录。设备来自现有 v2 `device` 表，设备游戏映射到 `setup.scene_id`，游戏列表来自各后端配置的 Y1 `/v1/server/checkin?expand=verse_id,name`。同一 `proxy` 网络内设置 `SCENE_SERVER_BASE_URL=http://y1-p:8080/`，由 PHP 自动拼接 `/v1/server`。

## 登录与配置

默认管理员用户名为 `dirui`。初始密码采用用户提供的密码，末尾为半角波浪号 `~`，代码只保存其 `password_hash` 散列。可通过服务器环境变量覆盖：

- `ADMIN_USERNAME`：管理员用户名。
- `ADMIN_PASSWORD_HASH`：PHP `password_hash()` 生成的密码散列，不能设置成明文密码。

PHP 可从标准输入读取新密码并输出散列：

```sh
php -r 'echo password_hash(rtrim(fgets(STDIN), "\r\n"), PASSWORD_DEFAULT), PHP_EOL;'
```

把得到的完整散列作为 `ADMIN_PASSWORD_HASH` 设置在服务器环境中。Shell 中设置包含 `$` 的散列时应使用单引号，防止变量替换。

管理 JWT 的 `ADMIN_JWT_KEY` / `ADMIN_JWT_PUB_KEY` 默认读取同机主后端的 `/var/www/.ssh/jwt-key.pem` 和 `/var/www/.ssh/jwt-key-pub.pem`，算法为 ES256。现有 game 的业务 `JWT_KEY` 仍引用原 `ecc-private-key.pem`，算法配置也保持原状；不能将这份旧私钥与主后端公钥误配。两个节点须挂载同一组克隆的主后端私钥和对应公钥，不重新生成密钥。

管理 API 沿用主后端的 ES256 与克隆密钥文件，采用独立 `game-admin` 身份域的 Bearer JWT，不使用 PHP Session 或 CSRF Cookie。密码登录签发 `admin` 身份，微信扫码签发 `device_manager` 身份；不接受客户端提供的角色。访问令牌最长三小时，刷新令牌最多维持原有八小时总登录期限，刷新不会无限延长登录。所有请求使用 `Authorization: Bearer <accessToken>`。管理令牌不能作为原 v1/v2 微信或玩家令牌使用；这些业务令牌也不能作为后台管理员令牌。

参考主后端 `api.7dgame.com` 的 `IdentityService` / `SessionService` 和前端 `authClient`，登录返回 Access Token 与 Refresh Token，前端持久化并在到期前刷新。game 保留立即退出失效语义：Redis 只保存刷新令牌散列和随机登录 ID，刷新通过 Lua 原子轮换，退出删除登录记录，已签发的该次访问令牌也立即失效。每次授权核对签名、时间、issuer、audience、身份域及有效登录记录，并实时核对微信用户是否仍存在。

同一客户端 IP 在共享 Redis 的 1 分钟窗口内最多允许五次密码登录尝试，成功登录会清除计数；超过额度时返回 HTTP 429 和 `Retry-After`。限流桶包含窗口长度，旧 15 分钟策略的计数不会延续到新窗口。密码登录计数由 Redis 原子更新，场景短缓存与原扫码节流继续使用 `api/runtime` 文件缓存。令牌存储使用独立 `adminAuthRedis` 组件；`ADMIN_AUTH_REDIS_HOST`、`ADMIN_AUTH_REDIS_PORT`、`ADMIN_AUTH_REDIS_DB`、`ADMIN_AUTH_REDIS_PASSWORD` 指定双节点共同可达的 Redis，未覆盖的项回退对应 `REDIS_*`。不能让一个节点指向仅本机可见的 `redis` 别名。本部署与同机主后端一样，认证 Redis 指向 `10.206.16.15:6379` 的 DB 0，使用独立 `game:admin:auth:` 与 issuer 命名空间；业务 `REDIS_DB=3` 等配置保持不变。令牌记录为短期状态，Redis 重启或清理后需要重新登录。存储故障返回通用 503，不降级为其他身份。

## 微信小程序扫码登录

网页二维码由“不加班”小程序内部扫描：打开小程序“我的”页，点击“扫码登录设备后台”，扫描网页二维码并确认目标站点。此入口不依赖微信开放平台 PC OAuth；普通微信“扫一扫”不会直接打开本次确认页。密码登录继续可用。

每个正式 Yii 后端分别配置：

| 变量 | 含义 |
| --- | --- |
| `ADMIN_SCAN_LOGIN_ORIGIN` | 统一管理站点的规范 HTTPS origin，本部署两端均为 `https://checkin.voxel.cn`；不包含路径、查询或用户凭据 |

未配置有效的规范 origin 时扫码登录关闭，`GET /admin-api/session` 的 `scanLoginEnabled` 为 false。**只有网页账号密码登录是全量管理员**，默认账号为 `dirui`；微信扫码固定返回 `role: device_manager`，只能读取及修改 `control.user_id` 关联的设备。微信用户的 `manager`、`root` 角色不能提升此权限。无关联设备可登录但返回空列表，撤销关联后下一次读写立即失去该设备权限，删除微信用户后会话失效。不设置微信管理员名单或配置界面。

首次部署扫码功能前执行迁移 `m261011_120000_create_admin_scan_login_ticket_table`。先用 `php yii migrate/new` 核对待执行清单，并按既有数据库备份及发布流程执行 `php yii migrate --interactive=0`。共库节点只需迁移一次；自动代理的全部节点应使用相同的 `bujiaban-mp` AppID、JWT 签名验证材料、认证设置和规范扫码 origin。本部署规范 origin 为 `https://checkin.voxel.cn`，票据由共享数据库保存，创建浏览器凭据独立于登录令牌，由下面的随机 owner proof 绑定。

扫码版本的后端发布范围包括新增票据服务、迁移和 `v2/WebLoginController`，以及管理路由、`AdminAuthService`、v1 模块身份入口、v1/v2 User 与刷新控制器的认证修复；这些文件按同一版本发布，保持签发和认证的身份来源规则一致。Web 和独立小程序仓库分别构建发布。

登录票据有效期五分钟，数据库只保存批准 secret 和浏览器 owner proof 的散列，并绑定规范 origin。浏览器用 CSPRNG 生成独立 64 位十六进制 `X-Admin-Scan-Owner`，放在当前标签页 sessionStorage；创建、状态、消费、取消以及密码登录、退出时发送该请求头。owner proof 不进入二维码、URL 或日志；二维码中仍只有原批准 secret 和 origin，小程序批准协议保持兼容。共享 Redis owner 锁与数据库条件更新保护票据替换及一次性消费；锁租约为 120 秒，事务提交前原子核对并续租，丢锁时回滚。状态轮询和小程序批准不建立网页登录身份。

浏览器显式消费已批准票据后收到 JWT 登录包。刷新二维码、取消、密码登录成功和退出会取消该 owner 的未完成票据。若消费已执行但响应丢失，客户端不重放消费，也不能借旧 Cookie 找回新令牌；页面允许明确刷新二维码重新扫码。已过期、已取消、已消费状态保留确定的返回，票据过期后不能消费。

| 方法 | 路径 | 凭据与返回 |
| --- | --- | --- |
| POST | `/admin-api/scan-login/tickets` | 浏览器 owner proof；返回 `{ticketId, qrText, expiresAt}` |
| GET | `/admin-api/scan-login/tickets/<id>` | 创建浏览器 owner proof；返回 `{status, expiresAt}` |
| POST | `/admin-api/scan-login/tickets/<id>/consume` | 创建浏览器 owner proof；返回 `{user, token, authMode}` |
| POST | `/admin-api/scan-login/tickets/<id>/cancel` | 创建浏览器 owner proof；返回 `{status, expiresAt}`，保留已过期或已消费的终态 |
| POST | `/v2/web-login/tickets/<id>/approve` | 目标后端 Bearer + `{secret}`；有效微信用户批准自己的设备管理登录，沿用 v2 业务响应包装 |

`expiresAt`、`expires` 和 `refreshExpires` 均为 ISO 8601 时间。票据状态为 `pending`、`approved`、`consumed`、`cancelled` 或 `expired`。消费期间阻止切换登录方式，令牌代际检查防止迟到的登录、刷新或错误响应覆盖新的认证状态。

登录响应的 `user` 为 `{username, role}`：密码登录的 `role` 为 `admin`，扫码登录为 `device_manager`。权限由服务端有效登录记录和实时身份派生；设备范围在服务层按 `control.user_id` 执行，页面隐藏不能替代鉴权。

本次同时修复 v1/v2 刷新 Token 和身份查询对签名、有效期的验证。两套身份共用签名密钥，现通过签名内的 `identityDomain` 区分 `v1-player` 与 `v2-user`，防止不同用户表中的同一数字 ID 相互冒用。**发布后，旧的无身份来源标记的 v1/v2 Token 将失效，用户需要重新登录**；刷新接口不能将旧 Token 或其他身份域转换成管理员凭据。v1 刷新接口同时停止记录包含 Token 的请求内容。微信登录没有 unionid 时仅允许按微信服务端返回的 openid 匹配已有用户，无法匹配时明确失败；不接受客户端自报微信身份。

微信 Bearer 对原 `/v2/devices` 的列表、详情、管理列表和更新，以及 `/v2/setups` 的 CRUD，同样按实时 `control.user_id` 限制。Setup 更新禁止重绑 `device_id`。设备创建、删除、assign/unassign 和 Root/Manager 分配入口一律拒绝微信身份，包括 root；小程序已移除相关全局管理入口。v2 不接受网页管理员 JWT 代替微信 Bearer。

## 接口

所有成功响应均直接返回 JSON，省略 `success` / `data` 包装。响应带 `Cache-Control: no-store`。

| 方法 | 路径 | 请求 | 返回 |
| --- | --- | --- | --- |
| GET | `/admin-api/session` | 可选 Bearer | `{user: {username, role} \| null, scanLoginEnabled, authMode:"jwt"}` |
| POST | `/admin-api/login` | `{username: string, password: string}` | `{user, token, authMode:"jwt"}` |
| POST | `/admin-api/refresh` | `{refreshToken: string}` | `{user, token, authMode:"jwt"}` |
| POST | `/admin-api/logout` | `{refreshToken: string}` | `{user: null, authMode:"jwt"}` |
| GET | `/admin-api/devices` | 必须登录 | 设备数组 |
| GET | `/admin-api/games` | 必须登录 | 游戏数组 |
| PUT | `/admin-api/devices/<id>/game` | `{gameId: number}`，必须登录 | 更新后的单个设备 |
| PUT | `/admin-api/devices/<id>/configuration` | `{gameId?: number, title?: string, slogans?: string[], pictures?: string[], thumbs?: string[]}`，必须登录 | 更新后的单个设备 |

JSON 请求带 `Content-Type: application/json`，生产管理接口不发送 CSRF Token 或 Cookie。需要认证的请求带 Bearer；刷新与退出携带当前 `refreshToken`。正式前端会在发送业务请求之前执行单次共享刷新，业务请求一经发送，收到 401、超时或网络错误均不自动重放写入。

设备格式示例：

```json
{
  "id": 1,
  "uuid": "device-a",
  "name": "大厅一号",
  "title": "大厅一号",
  "ip": "192.168.1.10",
  "status": "unknown",
  "updatedAt": "2026-10-11 12:30:00",
  "gameId": 638,
  "slogans": ["欢迎来到这里"],
  "pictures": ["https://example.com/welcome.png"],
  "thumbs": ["https://example.com/welcome.webp"]
}
```

`title` 返回 `setup.title` 原值，无配置或空值时为 `""`，与小程序的设备名称对应。`name` 优先使用去掉首尾空白的 `title`；名称为空或仍为协议默认值 `未知` 时，沿用设备 `tag`，其次为 `uuid`。设备 `tag` 和 `uuid` 是物理标识，编辑名称不会修改它们。没有 `setup` 或未配置 `scene_id` 时 `gameId` 为 `null`。现有模型没有可信的在线心跳字段，因此 `status` 始终为 `unknown`。`updatedAt` 优先取配置更新时间，其次为设备更新时间，并沿用数据库的时间格式。设备总数是返回数组长度。读取设备列表不会创建缺失的 `setup`。

`slogans` 为欢迎词/口号，`pictures` 为图片地址，`thumbs` 为缩略图地址，直接对应现有设备 `setup` 的同名字段。返回值始终为字符串列表；缺少配置或字段为空时返回 `[]`。读取时过滤历史数据中的非字符串和空项，并去掉字符串首尾空白，不修改数据库。

`/configuration` 使用局部修改：只允许 `gameId`、`title`、`slogans`、`pictures`、`thumbs`，至少提供一个字段；省略的字段保持原值，显式 `[]` 清空对应列表。`title` 必须为字符串，去掉首尾空白后最多 255 个 Unicode 字符，空字符串可清除自定义名称并恢复标签/UUID 显示。三个内容字段必须是 JSON 字符串数组，字符串会去掉首尾空白并过滤空项。无效名称、非列表（包括 `{}`）、非字符串元素、未知字段、空请求对象、非正整数 `gameId` 或不在当前目录中的 `gameId` 返回 422。每次显式提交 `gameId` 都校验当前目录，包括与原值相同的编号；目录失败时返回 503。仅编辑已有配置的名称或内容不会请求目录，原场景已下架或为空也可保存。

当前部署的 MySQL 连接使用 `utf8`（三字节字符集）；该连接或 `utf8mb3` 下，名称中的四字节 Unicode 字符（如多数表情符号）会在写入前返回 422：`设备名称暂不支持表情符号，请使用文字或数字`。普通中文仍支持上述 255 字边界；`utf8mb4` 连接不受此兼容限制。本次不修改数据库连接字符集或表结构。

配置修改沿用现有管理 Bearer JWT 和设备归属校验，事务内锁定设备行并保存本次全部字段。金额、拍照次数及其他未提交的字段保留。首次保存缺少 `setup` 的设备时保留现有设备协议默认值；未提交 `gameId` 时使用当前动态默认场景，目录为空则保持未分配。响应为完整设备对象，包含从数据库回读的 `updatedAt`。现有 `/game` 接口继续可用。

游戏格式示例：

```json
{"id": 638, "name": "星际漫游", "description": "", "category": ""}
```

示例名称用于说明格式。真实目录为空时 API 返回 `[]`，管理端显示没有可分配的游戏，不生成或混入示例游戏。实时目录内容取决于 Y1 服务；演示模式的数据仅保存在演示环境中。

`id` 对应 Y1 的 `verse_id`，未提供的描述、分类为空字符串。Y1 直接数组及 `data`、`items`、`results` 包装均可归一化。游戏列表缓存 30 秒，Y1 失败返回 503。设置设备游戏前会检查编号属于当前游戏列表，再在数据库事务中创建或更新 `setup`；已有金额、文案、图片、拍照次数等字段保持原值。首次创建配置时会填充现有设备协议的默认标题、图片、金额、口号、缩略图和拍照次数，随后覆盖选中的游戏编号。保存响应中的 `updatedAt` 从数据库回读，与后续设备查询保持一致。

Y1 目录通过配置的服务地址及明确的 `page` / `pageSize=15` 参数读取全部分页，使用响应分页头确定页数，不跟随 `Link` 中的任意 URL。整个读取限制为 15 秒、最多 100 页；超限、任一后续页失败、JSON 无效或分页总数不一致都会返回 503，不返回或缓存部分目录。

## 错误

失败通过 HTTP 状态码和 JSON `message` 表达。前端不应依赖 Yii 的其他错误字段。

| 状态码 | 含义 |
| --- | --- |
| 400 | owner proof 或 JSON 格式错误 |
| 401 | 未登录、登录已过期、用户名或密码错误 |
| 404 | 设备不存在或路由不存在 |
| 405 | 请求方法不允许 |
| 422 | 请求字段无效、游戏编号不属于场景列表、模型验证失败 |
| 429 | 登录尝试过于频繁 |
| 503 | 数据库、令牌存储或 Y1 场景源暂时不可用 |

## 本地验证

无需部署专用的 `api/config`，使用独立 Yii Application 和 SQLite 内存库运行后台测试：

```sh
php api/vendor/bin/phpunit --stderr --bootstrap api/tests/_bootstrap.php --filter 'AdminApiTest|AdminSceneFetcherTest|AdminScanLoginTest' api/tests/unit/components
```

测试覆盖 JWT 签名和时间、身份域隔离、密码与微信角色、跨节点令牌读取、原子刷新、八小时总期限、立即注销、用户及设备权限变化、Cookie/CSRF 移除、扫码 owner 与一次性消费，以及现有设备和场景读取/写入事务。同时覆盖设备名称与欢迎词/图片/缩略图的局部保存、清空、长度校验、物理标识保留、目录不可用时保存和事务回滚。测试只使用独立账号、测试密钥和随机 Redis 键前缀。

真实联调需要现有数据库、已执行的 v2 表迁移、两节点匹配的 JWT 签名验证材料、共同可达的专用令牌 Redis，以及可访问本机 Y1 的服务器网络。扫码还需票据表迁移、相同规范 origin、微信凭据及已发布的小程序。

## 前端与正式后端部署

`checkin.voxel.cn` 只运行静态 Vue 页面和 Nginx，不运行 PHP、不连接数据库。`dockerfile/FrontendDockerfile` 使用官方 Nginx Alpine 镜像，复制预构建的 `frontend/dist`、代理模板和启动脚本。`dockerfile/admin.compose.yaml` 仅连接现有 `proxy` 网络，通过 Traefik 的 `websecure` / `letsencrypt` 提供 HTTPS，不发布宿主机端口，不配置数据库凭据、Cookie key 或持久化卷。

前端以根路径构建：`npm run build -- --base=/`。Portainer 的最小构建 tar 应包含以下路径，将 `dockerfile/FrontendDockerfile` 在 tar 根目录命名为 `Dockerfile`，其余路径保持不变：

- `frontend/dist/`
- `Dockerfile`（源文件为 `dockerfile/FrontendDockerfile`）
- `dockerfile/frontend-nginx.conf.template`
- `dockerfile/40-configure-frontends.sh`
- `dockerfile/50-configure-miniprogram.sh`

构建包不能携带仓库根 `.dockerignore`，因为通用构建忽略规则会排除这次预构建的 `frontend/dist`。镜像不包含后端源码、Composer vendor 或数据库配置。

运行时扫描 `APP_API_N_URL` 全部数字编号配置，按数字排序，允许编号缺口；编号 N 对应 b(N−1)。镜像没有内置后端默认值，必须显式配置至少一个 HTTPS origin。可选 `APP_API_N_WEIGHT` 为 1–1000000 的正整数，默认等权；每个节点的计算占比至少为 0.01%。脚本校验 URL、编号、权重和 Host，拒绝路径、查询、用户凭据及配置注入字符；生成 `split_clients "$request_id"`、实际路由 `map` 和带共享 zone 的动态 DNS upstream。每个分流桶以所选节点为主，其他节点为备用，失败节点短时隔离后被动恢复。增减节点只需更新部署变量和容器；DNS 由 Docker resolver 每 30 秒刷新。

公开 `/runtime-config.json` 禁止缓存，仅返回 `{"apiBase":"/api/admin-api","automatic":true,"auth":"jwt"}`。前端拒绝旧节点清单和错误认证模式。Token 使用独立持久化键，节点选择不进入浏览器存储；同一标签页的刷新请求合并为单个进行中的操作；支持 Web Locks 时，跨标签页刷新也串行执行，并采纳其他标签页已轮换的同次登录令牌。Nginx 校验上游 TLS、Host 和 SNI，默认使用第一个 origin 的主机名，也可用 `APP_API_UPSTREAM_HOST` 覆盖。本部署两端均接受 `x.b0.01xr.com`。统一管理路由转发 Bearer 和 owner proof，清除浏览器 Cookie，隐藏上游 Set-Cookie。

自动管理代理仅对已核查的 GET/HEAD `session`、`devices`、`games` 和扫码票据状态读取，在无方法覆盖头时允许因连接失败、超时或 502/503/504 切换一次；最多两次尝试，重试窗口 25 秒。登录、退出、扫码创建/消费/取消、配置保存及其他请求均关闭自动重试，避免重复执行。`/api/backends/bN/admin-api/` 和旧 `/backends/bN/admin-api/` 保留为固定诊断别名，使用各自 Cookie Path；正式 Web 不再使用这些别名。

`/healthz` 仅检查前端容器。每个 `/api/backends/bN/health` 只读对应后端 `/health`，不转发查询、请求体、Cookie 或 Authorization，不返回上游 Cookie。小程序及其他业务继续通过 `/api/<原后端路径>` 转发到 B0 主/B1 备，例如 `/api/v2/devices` → `/v2/devices`；管理 JWT 和小程序 JWT 身份域分开。`/api/health` 与 `/mp-health` 为主备健康入口。调试、dotfile、PHP 和源码配置路径不公开；仅已知 HTTPS 上游及根相对 Location 改写前缀，Swagger HTML 资源链接和现有支付回调地址不改写。

先发布两个后端的 JWT 管理控制器、令牌服务及共享令牌 Redis 配置，验证跨节点登录、刷新、注销和扫码状态，再发布自动代理前端。PHP Session 版本的旧网页登录需要重新登录。线上使用独立测试客户端与票据，不改动真实设备配置。

Nginx 集成测试使用本地 HTTPS 服务及测试 CA，覆盖分流、权重、DNS、Host/SNI、证书验证、读取故障切换、写入单次发送、Cookie 范围、原始 URI、健康入口和源码屏蔽。指定真实 Nginx 二进制运行全部代理回归；未提供时跳过集成测试，不能据此声称代理行为已验证。

```sh
PYTHONDONTWRITEBYTECODE=1 NGINX_TEST_BINARY=/path/to/nginx python3 -m unittest discover -s dockerfile/tests -p 'test_*.py' -v
```

后台接口测试继续使用上文的 PHPUnit 命令，无需任何前端专用 PHP 入口或配置。
