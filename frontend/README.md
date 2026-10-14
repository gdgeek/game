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

## 自动适配正式后端

生产站点为 `https://checkin.voxel.cn/`。参考 `gdgeek/vue3.7dgame.com`，容器启动时扫描部署变量 `APP_API_N_URL`，自动生成 Nginx 代理和 `/runtime-config.json`；Vue 启动时读取清单。配置一个后端时自动连接并隐藏切换器，配置多个时自动生成选择项。浏览器保留仍有效的上次选择，否则使用第一项。

现有部署配置为：

| 页面选择 | 同源路径 | 正式后端 |
| --- | --- | --- |
| 正式后端 1（b0） | `/backends/b0/admin-api/` | `https://x.b0.01xr.com/admin-api/` |
| 正式后端 2（b1） | `/backends/b1/admin-api/` | `https://x.b1.01xr.com/admin-api/` |

每个后端分别使用自己的 Session Cookie 和 CSRF Token，Cookie Path 限定到 `/backends/bN/`。切换后重新检查登录状态；保存配置期间禁止切换。管理接口使用服务器本地 Session，操作始终固定到所选环境，避免登录丢失或失败写入被重投到其他环境。

增减后端只需修改 Portainer Stack 的 `environment` 并更新容器，沿用同一个镜像，无需重新构建前端。例如新增 `APP_API_3_URL: https://your-backend.example`，删除相应变量即可移除后端。允许编号缺口，按数字排序；编号 N 始终对应 b(N−1)，不会因移除其他项而改变 Cookie 路径。镜像没有内置后端默认值，必须显式配置至少一个有效的 HTTPS origin；空值或非法地址会阻止启动。配置变更后刷新页面读取新清单。

配置清单只公开后端编号、名称和主机，不包含凭据，并禁止缓存。地址仅来自部署配置，浏览器不能指定任意代理上游。前端容器只运行 Nginx，保留 TLS 证书验证、SNI 和正确 Host，不连接数据库。

两个正式后端均须发布本仓库新增的 `/admin-api`。它使用现有 v2 `device`、`setup` 数据，场景目录来自各实例配置的 Y1。保存配置只修改 `setup.scene_id`，设备下次读取现有 info 接口时使用新场景。API 没有心跳字段，页面如实显示“状态未知”；场景目录为空时禁止分配。接口说明见 [ADMIN_API.md](../docs/ADMIN_API.md)。

真实 API 不可用时显示错误，绝不回退到演示数据。后端尚未部署时会提示“该后端尚未部署管理接口”。

## 本地连接正式接口

```sh
cd frontend
cp .env.example .env.local
npm run dev:api
```

开发服务器使用同一套 `APP_API_N_URL` 枚举、公开清单、代理路径和 Cookie Path 隔离规则（不使用 `VITE_` 前缀）。正式联调推荐通过 HTTPS 部署测试，以完整验证 Secure Cookie。

## 构建与部署

```sh
cd frontend
npm test
npm run build -- --base=/
```

将 `frontend/dist/` 和 `dockerfile/FrontendDockerfile`、`dockerfile/frontend-nginx.conf.template`、`dockerfile/40-configure-frontends.sh` 打包为构建上下文，再使用 Portainer 构建 `checkin-frontend:20261011-2`。根目录 `.dockerignore` 会排除 dist，因此上传包采用上述文件的白名单，不包含根 `.dockerignore`。

部署模板为 [admin.compose.yaml](../dockerfile/admin.compose.yaml)，接入现有 `proxy` 网络及 Traefik `websecure` / `letsencrypt`。`/healthz` 检查前端存活；每个配置项自动生成 `/backends/bN/health` 只读探针，不转发 Cookie、Authorization 或请求体。

后端与前端独立发布。通用 PHP Dockerfile 仅构建原后端；管理账号散列可在后端用 `ADMIN_USERNAME`、`ADMIN_PASSWORD_HASH` 覆盖。不要把密码放入 `VITE_` 变量。

## 验证

前端测试覆盖单个及多个后端清单、失效选择、配置校验与重试、Session/CSRF 隔离、切换时的异步上下文和错误结构。代理用真实 Nginx 与本地 TLS 上游验证动态数量、编号缺口、Cookie Domain/Path 及证书校验。

生产联调仅核对登录、设备和场景读取，不为了测试修改真实设备配置。
