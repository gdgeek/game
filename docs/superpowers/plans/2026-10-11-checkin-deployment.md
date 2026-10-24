# c.4mr.cn 双后端前端部署

用户指定 Portainer `https://port.f.01xr.com`，最终域名 `c.4mr.cn`。两个正式后端分别是 `https://x.b0.01xr.com`、`https://x.b1.01xr.com`，页面须支持切换。反向代理参考 `gdgeek/vue3.7dgame.com`，界面参考 `gdgeek/bujiaban-mp`。

部署采用独立纯 Nginx 前端 Stack `checkin-console`，接入现有 `proxy` 网络和 Traefik `websecure` / `letsencrypt`。`/backends/b0/admin-api/`、`/backends/b1/admin-api/` 固定转发到对应后端，Cookie Path 分别隔离；保存请求禁止跨环境自动故障切换。

前端容器没有 PHP、数据库连接、数据库密码或管理 Cookie key。此前独立 PHP 管理容器中复用的 MYSQL 环境变量已从实际运行 Stack 移除；最终镜像替换为 `checkin-frontend:20261011-1`。

- [x] 核对两个参考仓库的样式、同源代理与设备字段。
- [x] 实现 B0/B1 切换、独立 CSRF、保存期间禁止切换和旧异步结果保护。
- [x] 完成纯 Nginx 镜像、HTTPS 上游校验及 Compose 模板。
- [x] 完成真实 Nginx 的 TLS、Cookie Domain/Path 和路由隔离验证（14 项测试，含 40 组非法 URL 子用例）。
- [x] 上传构建包，在 Portainer 构建并更新前端 Stack，清空旧凭据环境。
- [x] 验证可信 TLS、静态页面、两个后端健康代理和切换界面。
- [ ] 将新增管理 API 发布到现有 B0/B1 后端，验证真实登录和设备/场景查询。
- [ ] 记录最终线上验证结果。

2026-10-11 只读检查：两个后端 `/health` 均 200，健康检查返回发布提交 `c4e088adc7ba17bd5bb640ac31178a122906e002`。两边 `/admin-api/session` 均 404，说明新增接口尚未发布。`/v2/server/scenes` 返回空数组，管理页须如实显示空场景状态。

验证原则：不为了测试切换真实设备游戏。保存流程通过隔离数据库测试核对，线上只读验证。

2026-10-11 16:12 前端已替换为纯 Nginx 镜像 `checkin-frontend:20261011-1`（本地 image ID 前缀 `d5ffa89d0333`），Stack 24 / `checkin-console`。严格 TLS 请求验证首页、`/healthz` 及两条后端健康代理均 200，浏览器验证了 B0/B1 切换。两条管理接口代理仍如实返回 404，待后端发布。
