# 设备管理 API

Vue 3 管理端部署在 `checkin.voxel.cn`，用户手动选择 B0 或 B1 正式后端。浏览器分别通过同源 `/backends/b0/admin-api/` 和 `/backends/b1/admin-api/` 访问 Nginx，代理到 `https://x.b0.01xr.com/admin-api/` 和 `https://x.b1.01xr.com/admin-api/`。设备来自所选后端现有 v2 `device` 表，设备游戏映射到 `setup.scene_id`，游戏列表来自 A1 `/v1/server/checkin?expand=verse_id,name` 的 `verse_id` 和 `name`。

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

## 接口

所有成功响应均直接返回 JSON，省略 `success` / `data` 包装。响应带 `Cache-Control: no-store`。

| 方法 | 路径 | 请求 | 返回 |
| --- | --- | --- | --- |
| GET | `/admin-api/session` | 无 | `{user: {username} \| null, csrfToken}` |
| POST | `/admin-api/login` | `{username: string, password: string}` | `{user: {username}, csrfToken}` |
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
php api/vendor/bin/phpunit --stderr --bootstrap api/tests/_bootstrap.php --filter 'AdminApiTest|AdminSceneFetcherTest' api/tests/unit/components
```

测试覆盖登录、Session 轮换和过期、提前启动 Session 的实际 PHP Cookie 参数、环境变量覆盖、无 Redis 登录、真实 debug/gii 路由初始化、CSRF、真实错误处理器 JSON 响应、后台与微信身份隔离、只读设备查询、场景归一化与分页、有效游戏校验、字段保留、首次配置的真实设备协议默认值、时间回读一致性和事务失败回滚。测试中的独立账号不使用生产密码。

真实数据联调需要现有数据库、已执行的 v2 设备和配置表迁移、可写 PHP Session 存储，以及能访问 A1 的服务器网络。此 API 未修改现有微信、小程序和设备协议。

## 前端与正式后端部署

`checkin.voxel.cn` 只运行静态 Vue 页面和 Nginx，不运行 PHP、不连接数据库。`dockerfile/FrontendDockerfile` 使用官方 Nginx Alpine 镜像，复制预构建的 `frontend/dist`、代理模板和启动脚本。`dockerfile/admin.compose.yaml` 仅连接现有 `proxy` 网络，通过 Traefik 的 `websecure` / `letsencrypt` 提供 HTTPS，不发布宿主机端口，不配置数据库凭据、Cookie key 或持久化卷。

前端以根路径构建：`npm run build -- --base=/`。Portainer 的最小构建 tar 应包含以下路径，将 `dockerfile/FrontendDockerfile` 在 tar 根目录命名为 `Dockerfile`，其余路径保持不变：

- `frontend/dist/`
- `Dockerfile`（源文件为 `dockerfile/FrontendDockerfile`）
- `dockerfile/frontend-nginx.conf.template`
- `dockerfile/40-configure-frontends.sh`

构建包不能携带仓库根 `.dockerignore`，因为通用构建忽略规则会排除这次预构建的 `frontend/dist`。镜像不包含后端源码、Composer vendor 或数据库配置。

运行时 `APP_API_1_URL` 和 `APP_API_2_URL` 默认分别为 `https://x.b0.01xr.com`、`https://x.b1.01xr.com`。启动脚本只接受 HTTPS origin，可包含端口；拒绝路径、查询、用户凭据及配置注入字符。两个固定代理路径始终各自对应一个后端，任何请求失败都不会自动改投另一个环境。更改后端域名或 DNS 地址后应重启容器以重新解析。

代理校验上游 TLS 证书和主机名，发送对应的 Host 与 SNI，使用系统 CA，不忽略证书错误。上游 Cookie 的 Path 统一改为各自的 `/backends/b0/` 或 `/backends/b1/`，已有 Domain 改为当前前端域名；无 Domain 的 Cookie 保持同源。Cookie 启用 Secure、HttpOnly、SameSite=Lax。这样同名 Session 和 CSRF Cookie 可在两个环境独立保存；切换环境后应重新获取该环境的 Session 和 CSRF Token。

`/healthz` 仅返回容器静态健康状态。`/backends/b0/health` 和 `/backends/b1/health` 分别只读访问对应后端的 `/health`，只接受 GET / HEAD，去掉查询参数、请求体、Cookie 和 Authorization，并隐藏上游 Set-Cookie。后端健康接口可用不表示管理接口已经部署。所有其他后端路径和 PHP、配置、数据库等源文件路径均不提供访问。

正式 Yii 后端仍需分别发布本仓库的 `AdminController`、`AdminAuthService`、`AdminDeviceService`、`AdminGameCatalog`、`AdminSceneFetcher`、`UrlManager` 及 `files/api/config/web.php` 中的管理路由，沿用各自正式环境已有的数据库配置和应用部署流程。当前核对 B0、B1 的 `/admin-api/session` 都返回 404，说明这部分代码尚未上线；前端应显示真实连接错误，不用演示数据替代。发布后再通过前端代理验证 Session、登录、设备与场景查询。线上联调只读，不为测试而改动真实设备的游戏。

Nginx 配置和代理的集成测试使用本地 HTTPS 服务及测试 CA，覆盖 Host/SNI、TLS 校验、路径和查询、写入请求保持原后端、Cookie 隔离、只读健康检查和源文件屏蔽。指定可用 Nginx 二进制后运行：

```sh
NGINX_TEST_BINARY=/path/to/nginx python3 dockerfile/tests/test_frontend_proxy.py
```

后台接口测试继续使用上文的 PHPUnit 命令，无需任何前端专用 PHP 入口或配置。
