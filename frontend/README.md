# 不加班设备管理后台

Vue 3 + TypeScript + Vite 中文管理后台，视觉参考 `gdgeek/bujiaban-mp`。提供管理员登录、设备统计、筛选、分页、CSV 导出，以及逐设备的名称、游戏、欢迎词（口号）、图片和缩略图配置。

## 本地演示

需要 Node.js 22.12+ 或 24、npm。

```sh
cd frontend
npm ci
npm run dev
```

访问 `http://127.0.0.1:5173/`，用户名 `dirui`，密码使用需求指定的密码，末尾为全角波浪号 `～`。密码在服务端校验，不预填且不进入浏览器构建产物。

此命令显式启用 demo 模式，页面标明“演示模式”，包含 12 台样例设备、6 款样例游戏。状态保存在被 Git 和 Docker 忽略的 `.local/demo-state.json`，会话在开发服务重启后失效。生产构建不包含演示 API。

## 自动分配正式后端

生产站点为 `https://checkin.voxel.cn/`。参考 `gdgeek/vue3.7dgame.com`，容器启动时扫描 `APP_API_N_URL`，生成 Nginx `split_clients`、`map` 和故障切换配置。用户始终访问同源 `/api/admin-api/`，节点由代理自动选择；登录页和工作空间均没有后端选择器，也不在 localStorage 中记录节点。

现有上游仍为：

| 部署变量 | 正式上游 |
| --- | --- |
| `APP_API_1_URL` | `https://x.b0.01xr.com` |
| `APP_API_2_URL` | `https://x.b1.01xr.com` |

浏览器只读取禁止缓存的 `/runtime-config.json`：

```json
{"apiBase":"/api/admin-api","automatic":true,"auth":"jwt"}
```

前端固定校验上述路径、自动模式和 JWT 身份模式，拒绝其他 origin、路径和旧节点清单。配置失败时禁用登录并显示“重试加载配置”，用户明确重试后重新获取；真实接口不可用时显示错误。

全部节点使用相同的 `game-admin` JWT 签名、Redis 刷新令牌存储与身份设置。浏览器通过 `/admin-api` 使用 Bearer Access Token；访问令牌三小时有效，登录总时限八小时，刷新在到期前执行。单标签页并发由 singleflight 合并，同源浏览器支持 Web Locks 时也会串行化跨标签页刷新，并在锁内复读已轮换的 token；不支持 Web Locks 的浏览器仍合并本标签页并发，但同时在多个标签页刷新时可能需要重新登录。令牌保存在独立 localStorage 键中，扫码 owner proof 使用 CSPRNG 生成并只存于当前标签页 sessionStorage。登录、退出、扫码创建/状态/消费/取消均带 `X-Admin-Scan-Owner`，不将 proof 放进 URL 或二维码。JWT 请求使用 `credentials: omit`，不发送 Cookie 或 CSRF。

登录、退出、扫码消费、配置保存等写请求均不会由浏览器自动重试。生产代理只对安全读取进行故障切换，不重放可能已经执行的写入。保存失败时保留错误，用户核对后明确重试。浏览器业务请求超时为 45 秒，管理代理读取超时为 40 秒。若扫码消费响应丢失，客户端不会重放消费或从匿名 Session 推断登录成功，而是提示刷新二维码后重新扫码。

增减后端只需调整 Portainer Stack 的 `APP_API_N_URL` 并更新容器，沿用同一个前端镜像。允许编号缺口，按数字排序；部署必须显式配置至少一个有效 HTTPS origin。节点地址只在代理服务器侧使用，不进入公开配置。Nginx 保留 TLS 证书校验、SNI 与正确 Host。

正式上游均须发布本仓库的 `/admin-api`。它使用现有 v2 `device`、`setup` 数据，场景目录来自各实例配置的 Y1。点击「配置设备」可修改设备名称、选择游戏，增删欢迎词、图片地址和缩略图地址；HTTP(S) 图片地址提供预览。只提交本次修改的 `title`、`gameId`、`slogans`、`pictures`、`thumbs`，设备下次读取配置时生效。设备名称与小程序共用 `setup.title`，最多 255 个字符；名称为空或默认“未知”时，列表显示原设备标签或 UUID。列表保留顺序和重复项，忽略首尾空白与空项，删除全部条目可清空列表。接口没有心跳字段，页面显示“状态未知”；场景目录为空或加载失败时仍可编辑设备名称、欢迎词和图片。接口说明见 [ADMIN_API.md](../docs/ADMIN_API.md)。

## 本地连接正式接口

```sh
cd frontend
cp .env.example .env.local
npm run dev:api
```

开发服务器读取同一套 `APP_API_N_URL`（不使用 `VITE_` 前缀），浏览器同样只看到 `/api/admin-api/`。Vite 在服务端按顺序轮询已配置上游，转发 Bearer 与 owner proof，移除请求 Cookie 并隐藏上游 Set-Cookie。每个开发请求只发送到一个节点，连接失败返回 502，不重试写入。所有节点须验证共同的 JWT 配置并连接共享 Redis 刷新令牌存储。

## 构建与部署

```sh
cd frontend
npm test
npm run build -- --base=/
```

将 `frontend/dist/` 和 `dockerfile/FrontendDockerfile`、`dockerfile/frontend-nginx.conf.template`、`dockerfile/40-configure-frontends.sh`、`dockerfile/50-configure-miniprogram.sh` 打包为构建上下文，再使用 Portainer 构建 `checkin-frontend:20261016-auto-1`。根目录 `.dockerignore` 会排除 dist，因此上传包采用上述文件的白名单，不包含根 `.dockerignore`。

部署模板为 [admin.compose.yaml](../dockerfile/admin.compose.yaml)，接入现有 `proxy` 网络及 Traefik `websecure` / `letsencrypt`。`/healthz` 检查前端存活；后端健康探针由代理配置提供。

后端与前端独立发布。通用 PHP Dockerfile 仅构建原后端；管理账号散列可在后端用 `ADMIN_USERNAME`、`ADMIN_PASSWORD_HASH` 覆盖。不要把密码放入 `VITE_` 变量。

## 验证

前端测试覆盖固定自动入口、JWT 配置校验、token 持久化与刷新、请求代际保护、Bearer/owner proof 头、cookie 隔离、写失败不重放，以及扫码创建、轮询、消费和取消。Vite 代理通过本地双上游验证自动分流、Bearer/owner proof 转发、cookie 隔离、路径和失败写只发送一次；生产 Nginx 的自动生成、故障切换及 TLS 行为由部署测试验证。

生产联调仅核对登录、设备和场景读取，不为了测试修改真实设备配置。
