# 不加班设备管理后台

Vue 3 + TypeScript + Vite 中文管理后台，视觉参考 `gdgeek/bujiaban-mp`。提供管理员登录、设备统计、筛选、分页、CSV 导出和逐设备场景配置。

## 本地演示

需要 Node.js 22.12+ 或 24、npm。

```sh
cd frontend
npm ci
npm run dev
```

访问 `http://127.0.0.1:5173/`，用户名 `dirui`，密码使用需求指定的密码，末尾为全角波浪号 `～`。密码在服务端校验，不预填且不进入浏览器构建产物。

此命令显式启用 demo 模式，页面标明“演示模式”，包含 12 台样例设备、6 款样例游戏。状态保存在被 Git 和 Docker 忽略的 `.local/demo-state.json`，会话在开发服务重启后失效。生产构建不包含演示 API。

## 双正式后端

生产站点为 `https://checkin.voxel.cn/`，前端通过同源 Nginx 反向代理访问：

| 页面选择 | 同源路径 | 正式后端 |
| --- | --- | --- |
| b0（默认） | `/backends/b0/admin-api/` | `https://x.b0.01xr.com/admin-api/` |
| b1 | `/backends/b1/admin-api/` | `https://x.b1.01xr.com/admin-api/` |

选择保存在浏览器本地。两个后端分别使用各自 Session Cookie 和 CSRF Token，Cookie Path 由代理限定到对应 `/backends/b0/`、`/backends/b1/`。切换后重新检查该后端登录状态；保存配置期间禁止切换。请求不会随机分流或自动写入另一个正式环境。

代理实现参考 `gdgeek/vue3.7dgame.com` 的同源 HTTPS 代理方式，保留 TLS 证书验证、SNI 和正确 Host。前端容器只运行 Nginx，环境变量只有两个后端 URL，不连接数据库，也不保存数据库密码。

两个正式后端均须发布本仓库新增的 `/admin-api`。它使用现有 v2 `device`、`setup` 数据，场景目录来自 A1。保存配置只修改 `setup.scene_id`，设备下次读取现有 info 接口时使用新场景。API 没有心跳字段，页面如实显示“状态未知”；场景目录为空时禁止分配。接口说明见 [ADMIN_API.md](../docs/ADMIN_API.md)。

真实 API 不可用时显示错误，绝不回退到演示数据。后端尚未部署时会提示“该后端尚未部署管理接口”。

## 本地连接正式接口

```sh
cd frontend
npm run dev:api
```

开发代理使用与生产相同的两条路径、Cookie Path 隔离与 TLS 验证。可用 `.env.local` 中的 `APP_API_1_URL`、`APP_API_2_URL` 覆盖上游地址（不使用 `VITE_` 前缀）。正式联调推荐通过 HTTPS 部署测试，以完整验证 Secure Cookie。

## 构建与部署

```sh
cd frontend
npm test
npm run build -- --base=/
```

将 `frontend/dist/` 和 `dockerfile/FrontendDockerfile`、`dockerfile/frontend-nginx.conf.template`、`dockerfile/40-configure-frontends.sh` 打包为构建上下文，再使用 Portainer 构建 `checkin-frontend:20261011-1`。根目录 `.dockerignore` 会排除 dist，因此上传包采用上述文件的白名单，不包含根 `.dockerignore`。

部署模板为 [admin.compose.yaml](../dockerfile/admin.compose.yaml)，接入现有 `proxy` 网络及 Traefik `websecure` / `letsencrypt`。`/healthz` 检查前端存活；`/backends/b0/health`、`/backends/b1/health` 只读检查上游，不转发 Cookie、Authorization 或请求体。

后端与前端独立发布。通用 PHP Dockerfile 仅构建原后端；管理账号散列可在后端用 `ADMIN_USERNAME`、`ADMIN_PASSWORD_HASH` 覆盖。不要把密码放入 `VITE_` 变量。

## 验证

前端测试覆盖统计、筛选、Session/CSRF、两后端请求与 CSRF 隔离、切换时的异步上下文和错误结构。后端使用隔离 Yii Application 与 SQLite 内存库验证鉴权、CSRF、路由、事务和其他设备配置字段保留。代理用真实 Nginx 与本地 TLS 上游验证 Cookie Domain/Path 及证书校验。

生产联调仅核对登录、设备和场景读取，不为了测试修改真实设备配置。
