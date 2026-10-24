# game 与微信小程序协作

## 仓库位置

| 部分 | 本地路径 | 仓库 |
| --- | --- | --- |
| Yii2 API 与 Vue3 Web 管理后台 | 工作区根目录，分别位于 `api/` 和 `frontend/` | `gdgeek/game` |
| uni-app / Vue3 / TypeScript 微信小程序 | `bujiaban-mp/` | `gdgeek/bujiaban-mp` |

打开根目录的 `game.code-workspace` 可同时查看两个仓库。小程序保留独立 Git 历史，根仓库的 Git 和 Docker 构建上下文都排除了它。

首次在其他机器建立相同工作区时，在 `game` 根目录执行：

```sh
git clone git@github.com:gdgeek/bujiaban-mp.git bujiaban-mp
```

## 小程序启动

```sh
cd bujiaban-mp
pnpm install --frozen-lockfile
pnpm run dev:mp-weixin
```

微信开发者工具导入 `bujiaban-mp/dist/dev/mp-weixin`。生产打包使用 `pnpm run build:mp-weixin`。

源码的 `.env.development`、`.env.production` 与默认配置都使用 `https://c.4mr.cn/api/v2`，由统一 `/api/` 入口去掉前缀、保留后端 `/v2` 路径并使用 B0 主 / B1 备。旧 `/v2/` 保留兼容；健康检查使用 `https://c.4mr.cn/api/health`。联调时可在小程序的 `.env.development.local` 中覆盖 `VITE_API_URL` 为开发服务地址；修改后重启编译。

## 已核对的接口对应关系

| 小程序入口 | game 后端接口 | 协作要点 |
| --- | --- | --- |
| `src/api/checkin.ts`、`src/api/login.ts` | `POST /v2/site/login`；微信资料及手机号接口 | 微信登录获得的 Access Token 由 `src/utils/common.ts` 加入 Bearer 请求头 |
| `src/api/device.ts` | `GET /v2/devices/manage`、`GET /v2/devices/{id}?expand=setup` | 管理设备及读取配置 |
| `src/pages/device/manage.vue`、`src/api/device.ts` | `PUT /v2/setups/{id}` | 编辑 `title`、`money`、`scene_id`、口号、镜头和图片等配置 |
| `src/api/a1.ts` | `GET /v2/server/scenes` | 获取场景列表，页面将场景 ID 写入 `setup.scene_id` |
| 根仓库 `frontend/` | `/admin-api` | 使用 Session 与 CSRF；接口说明见 `docs/ADMIN_API.md` |

场景列表由 `game` 的 `/v2/server/scenes` 适配层转发到 Y1 的
`/v1/server/checkin?expand=verse_id,name`。每个后端实例通过
`SCENE_SERVER_BASE_URL` 单独配置。Portainer 中 `game` 与 `y1-p` 共用
`proxy` 网络时，直接设置为 `http://y1-p:8080/`；这是容器内直连，PHP 会自动拼接
`/v1/server`，不经过公网域名。若不在同一 Docker 网络，生产 B0/B1 再分别使用
`https://y1.b0.01xr.com` 和 `https://y1.b1.01xr.com`；开发 B0 为
`https://y1.d.b0.01xr.com`，开发 B1 为
`https://y1.d.b1.01xr.com`。B1 是 B0 的备用实例，二者共用数据库和缓存；切换实例时只需修改对应后端的 `SCENE_SERVER_BASE_URL`。响应需保持直接数组并包含 `verse_id`、`name`。

小程序设备管理和 Web 管理后台都涉及 v2 的 `device` / `setup` 数据。调整字段时同时核对 `bujiaban-mp/src/api/device.ts`、`api/modules/v2/models/` 和 `api/components/AdminDeviceService.php`。

设备通过 `/v2/server/info?uuid=...` 首次建档时，默认 `setup.scene_id` 按 Y1
`/v1/server/checkin` 目录的返回顺序，选择第一个有效场景的 `verse_id`，不使用固定编号。
默认场景沿用目录的 30 秒缓存；目录为空时为 `null`，Y1 请求失败时返回 503，重试前不留下部分初始化的新设备。
已有设备配置保持原值；后台明确选择游戏时使用已校验的选中编号。

Unity 通过 `POST /v2/server/device` 或 `POST /v2/server/refresh` 上报设备时，
可在请求 body 中随 `uuid`、`token` 一起传入 `version`（例如 `2`）。每次包含
UUID 的设备请求都会将对应 `device.version` 同步为该值；省略 `version` 时写入
`0`，已绑定设备的同一 token 再次请求也会更新。支持 JSON 整数及表单整数字符串，
范围为 `0` 至 `2147483647`；非法值返回 400。沿用现有 `time` / `hash` 签名，
`version` 不加入签名公式。无 UUID 的小程序、文件和状态读取请求不修改设备版本；
`GET /v2/server/info?uuid=...&version=2` 也支持版本上报，`version` 从 query
读取，省略时按 `0` 处理，取值范围及非法值处理与上述 POST 接口一致。
新设备完成建档后同步到请求版本；已有设备仅在数据库版本与请求版本不同时更新
`device.version`，相同时不执行更新，设备配置保持原值。刷新流程内部仅调用
`getInfo()` 读取配置时不会重置设备版本；其他入口新增设备仍默认写入 `1`。

后端路由源文件为 `files/api/config/web.php`。各仓库独立提交与发布；建立协作结构和本次扫码登录实现均只修改本地代码，尚未发布扫码登录。

## 接入记录

2026-10-11 从小程序远程 `main` 分支克隆，接入时提交为 `cd5d0ba1e7a4bcdf02bb6763dfc2a797b6c55711`。首次建立协作结构时未安装依赖；随后扫码登录实现按冻结锁文件安装依赖，并通过测试、类型检查及微信小程序构建，尚未进行微信真机联调。

## 扫码登录 Web 管理后台

小程序“我的”页提供“扫码登录设备后台”。在网页选择目标后端、显示登录二维码后，使用该入口扫描，在确认页核对后端域名并确认。该流程使用小程序内部 `uni.scanCode`，微信“扫一扫”直接打开小程序需要另外的平台配置，不包含在此入口中。

小程序仅接受固定的 `bujiaban-admin-login://v1/` 票据协议和可信后端 origin。构建环境变量 `VITE_ADMIN_LOGIN_ORIGINS` 为逗号分隔的 HTTPS origin 名单，默认允许 `https://c.4mr.cn`、`https://x.b0.01xr.com`、`https://x.b1.01xr.com` 和 `https://x.4mr.cn`；增减正式后端时同步更新此名单、微信平台合法请求域名并重新构建发布小程序。二维码不能指定名单以外的请求地址。

扫码二维码中的 origin 仍为可信根域名，不带路径。确认针对目标后端执行新的 `wx.login`；checkin 目标通过 `/api/v2/site/login` 和 `/api/v2/web-login/tickets/<id>/approve`，可信直连 B0/B1/x.4mr 目标保持 `/v2` 路径。只使用该目标返回的临时 Bearer 批准票据。临时 Token 不覆盖 `AR_CHECKIN_OPENID`，现有打卡、设备和用户功能继续使用原 API 配置。二维码 secret 仅在此次页面流程的内存中传递，不写导航 URL 或持久化存储。

微信扫码只建立关联设备管理身份，Web 服务端按照当前微信用户的 `control` 关系限制列表和修改；微信 `root` 或 `manager` 均无全量设备权限。只有网页账号密码登录（默认 `dirui`）为全量管理员，不设置微信管理员名单。后端变量、迁移和接口见 [ADMIN_API.md](ADMIN_API.md)。

本次后端同步修复 v1/v2 Token 的签名、期限和身份来源校验。发布新后端后，旧的无 `identityDomain` 标记的 Token 失效，已有小程序用户需要重新登录；扫码所用目标环境 Token 每次通过新的微信 code 获取。

发布验收步骤：

1. 后端完成票据表迁移、规范 origin 配置，小程序配置对应合法请求域名及相同 AppID；若 B0/B1 共库，该库只迁移一次。
2. Web 选择 B0，微信用户在小程序中扫码并确认，Web 只显示该用户关联设备；无关联用户显示空列表。
3. 对 B1 重复操作，核对目标后端和设备关联；扫码结束后小程序原打卡登录仍可使用。
4. 验证取消不批准、过期二维码需要刷新、刷新后的旧二维码不可登录；篡改设备 ID 也不能修改他人配置。
5. 撤销设备关联后核验设备读写立即失效；默认 `dirui` 密码登录仍可管理全部设备。

本地验证分别在小程序仓库执行 `pnpm test`、`pnpm run tsc`、`pnpm run build:mp-weixin`。构建通过不能替代开发者工具运行、微信真机、线上域名和关联设备权限验证。
