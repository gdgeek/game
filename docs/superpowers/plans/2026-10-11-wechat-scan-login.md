# 微信小程序扫码登录 Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development to implement each owned component and review the integrated result.

**Goal:** 微信用户通过 bujiaban-mp 扫码确认进入 Web，仅管理自己关联的设备；网页密码登录（默认 dirui）才是全量管理员。

**Architecture:** Yii 数据库保存五分钟票据并绑定浏览器 Session 与规范 origin；小程序使用目标环境临时 Bearer 批准，Web 以 CSRF 显式消费并建立后台 Session。Web、小程序、后端分配独立文件边界并行实施，根代理集成并审核。

**Tech Stack:** Yii2/PHP/PHPUnit、Vue3/TypeScript/Vitest、uni-app/微信小程序。

## 固定协议

- 配置：`ADMIN_SCAN_LOGIN_ORIGIN` 是规范 HTTPS origin；未配置有效 origin 时关闭扫码入口；不配置微信管理员名单。小程序 `VITE_ADMIN_LOGIN_ORIGINS` 为可信 origin 逗号名单，默认现有 B0/B1。
- QR：`bujiaban-admin-login://v1/<32位小写hex id>?secret=<64位小写hex>&origin=<encodeURIComponent(origin)>`。
- 创建返回 `{ticketId, qrText, expiresAt}`；状态返回 `{status, expiresAt}`，时间为 ISO 8601；状态 pending/approved/consumed/cancelled/expired。
- 取消返回 `{status, expiresAt}`，终态为 cancelled/expired/consumed；已过期或已消费的票据不改写为 cancelled。
- `GET /admin-api/session` 增加可选 `scanLoginEnabled:boolean`，所有登录响应保持 `{user,csrfToken}`；`user.role` 为密码来源 `admin` 或微信来源 `device_manager`，由服务端派生。
- Web：POST `/scan-login/tickets`；GET `/scan-login/tickets/<id>`；POST 同路径 `/consume` 与 `/cancel`，除 GET 外均携带 CSRF，路径相对于选中后端 `/admin-api`。
- 小程序：目标 origin 的 POST `/v2/site/login` 用新 wx.login code；临时 token POST `/v2/web-login/tickets/<id>/approve` body `{secret}`，成功包装 `{success:true,message,data:{status:'approved'}}`。
- session CSRF 保持现有约定；二维码 secret 不用于浏览器兑换，不放导航 URL 和日志。

## 1. 后端（独立任务）

- [x] 在 `api/tests/unit/components/AdminScanLoginTest.php` 先写票据绑定、过期、一次性、CSRF、撤权、错误 secret、origin 与登录失效回归，执行并确认新增行为失败。
- [x] 新建 `api/components/AdminScanLoginService.php`、`api/modules/v2/controllers/WebLoginController.php` 和 `api/migrations/m261011_120000_create_admin_scan_login_ticket_table.php`；扩展 `AdminController.php`、`AdminAuthService.php`、`files/api/config/web.php`、`UrlManager.php`。
- [x] 为 v1/v2 刷新 Token 验签/期限/身份来源和缺 unionid 登录行为先写失败测试，再做必要修复；不将一个身份域的 Token 换签为另一个身份域。
- [x] 执行 `php api/vendor/bin/phpunit --stderr --bootstrap api/tests/_bootstrap.php --filter 'Admin' api/tests/unit/components`，确认管理与扫码回归、迁移均通过；补齐实际模块认证过滤器的 v1/v2 兼容性回归。

## 2. Web（独立任务）

- [x] 在 `frontend/src/lib/scan-login.test.ts` / API 测试先覆盖非重叠轮询、旧响应、卸载、后端绑定与消费期间锁，观察失败。
- [x] 新建扫码组件与轮询模块，扩展 `frontend/src/lib/api.ts`、`types.ts`、`components/LoginView.vue`、`App.vue`；保留全部已有动态后端未提交改动。
- [x] 使用 qrcode 生成真实二维码；错误和终态允许刷新，consume 期间禁止认证和环境变更。
- [x] 执行 `npm test` 与 `npm run build`，向根代理交付结果。

## 3. 小程序（独立任务）

- [x] 在 `src/api/__tests__/scan-login.test.ts` 先验证严格协议、可信 origin、取消、目标环境临时 token 与全局缓存隔离。
- [x] 新建 `src/api/scan-login.ts`、`src/pages/auth/confirm.vue`，扩展 `pages/user/index.vue` 与 `pages.json`；使用临时内存状态导航。
- [x] 单独执行 wx.login 和目标后端 code 交换；不将原 Bearer 发给二维码提供的 URL；只允许可信 origin。
- [x] 在小程序仓库安装锁定依赖，执行 `pnpm test`、`pnpm run tsc` 和 `pnpm run build:mp-weixin`；最小修复原有 `Recode.vue` 模板与 `a1.test.ts` 类型导入两个构建阻碍。

## 4. 集成与审核（根代理）

- [x] 逐项核对协议、禁用状态、数据库迁移、跨域登录和临时凭据，不合并其他会话工作。
- [x] 更新 `docs/ADMIN_API.md` 与 `docs/BUJIABAN_MP.md` 的部署变量、迁移、关联设备权限、旧 Token 兼容影响及真机验收步骤。
- [x] 独立安全审阅，修复发现后重跑受影响检查。
- [x] 分别检查两仓库 diff 与 `git diff --check`，最终交付代码和可复现验证结果，准确说明仍需微信真机及实际环境配置。

用户随后授权发布，并要求先通过微信开发者工具测试。发布按 develop → main → publish 的 CI 门禁执行，Web 与小程序另行部署；保留其他会话无关改动。

## 最新权限调整与发布

- [x] 删除微信管理员名单；扫码固定设备管理角色，服务层按 control 关联过滤读取和写入，撤销关联即时生效。
- [x] Web 根据来源区分“关联设备管理”和密码管理员界面，小程序去掉 root 页面注册与全局管理入口。
- [x] 对 v2 原有关联设备 API 越权先写真实路由失败测试，再统一鉴权和设备范围；关闭微信分配与全局增删入口，Setup 禁止重绑。
- [ ] 独立复核 v2 范围修复并重新验证最终版本。
- [ ] 微信开发者工具实际编译和扫码/确认测试，核对合法请求域名。
- [ ] 两仓库分别提交推送，根仓库三分支 CI 门禁通过。
- [ ] 核对 B0/B1 是否共库，迁移和规范 origin 配置，实际扫码全链路验证；完成微信平台上传/审核/发布并按真实状态记录。

## 早期验证记录（最新结果以发布记录为准）

- 后端：根代理修复后新鲜执行上述 `--filter 'Admin'` 命令，74 项 / 376 断言通过；13 个变更 PHP 文件语法检查通过。新增扫码与认证测试为 28 项 / 188 断言，独立审查者另行运行通过。
- Web：根代理新鲜执行 `npm test`，8 个文件 / 123 项通过；`npm run build`（含类型检查）通过。
- 小程序：根代理新鲜执行 `pnpm test`，16 个文件 / 188 项通过；`pnpm run tsc` 和 `pnpm run build:mp-weixin` 通过。冻结安装未修改锁文件。
- 浏览器：实际 Vue 页面配合隔离的模拟 API 验证二维码 PNG、B0 取消与 B1 绑定、390px 移动布局、消费期间认证与环境锁、单次 CSRF 消费、B1 数据加载及无页面异常；这不等于微信真机或线上后端联调。
- 早期独立客户端和后端审查通过；最新权限调整发现的 v2 越权已修复，需再次独立复核。后端审查发现实际配置固定 v2 身份类会拒绝合法 v1 Token，已在 v1 Module 入口选择 v1 身份、禁用 Session/自动登录并清除旧内存身份；真实 PlayerController 回归验证合法 v1 可用、跨域和伪造 Token 被拒绝、浏览器 Session 与管理员身份不变。
- 直接使用 PHPUnit 执行整个旧 components 目录有 13 项 `Codeception\\Test\\Unit` 初始化/注入错误，涉及既有 HealthServiceTest / UrlManagerTest；本次管理与扫码回归使用上面的 `--filter 'Admin'` 命令，不将其宣称为整个后端套件通过。
- 实际部署与微信真机待环境配置后验证；未运行生产迁移或写入线上设备。
