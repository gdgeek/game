# 小程序 B0/B1 自动切换 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox syntax for tracking.

**Goal:** 启用小程序统一入口的 B0 主 / B1 备故障切换并验证实际登录。

**Architecture:** 在现有静态 NGINX 镜像添加独立小程序代理配置，不变更 Web 固定后端 Session 路由。通过共同上游 Host/SNI 和共享 upstream zone 切换，读请求有限重试，写请求不重放；小程序只连接 checkin.voxel.cn/v2。

**Tech Stack:** NGINX 1.28、POSIX shell、Python unittest/TLS fixtures、Vue/uni-app/TypeScript/Vitest、Yii2。

---

### Task 1: 主备代理与真实 NGINX 测试

Files: dockerfile/50-configure-miniprogram.sh (new), dockerfile/frontend-nginx.conf.template, dockerfile/FrontendDockerfile, dockerfile/tests/test_miniprogram_failover.py (new), dockerfile/tests/README.md. 已上线动态后端部署文件作为隔离工作树基线带入，原工作区保持不动。

- [x] 运行现有渲染器和代理测试作为基线：NGINX_TEST_BINARY=/private/tmp/game-frontend-nginx-test-build/nginx-1.28.3/objs/nginx python3 -m unittest discover -s dockerfile/tests -p test_frontend_proxy.py -v。
- [x] 新测试先验证没有统一 /v2 代理、B0 故障无法返回 B1，确认失败后实现。
- [x] 加可开关 APP_MP_API_ENABLED=1；主备 HTTPS origin 和共同上游 Host 采用白名单式格式校验，不使用 eval。生成 zone、resolver 和 backup；保持 proxy_ssl_verify on。
- [x] 添加 /v2/ 路由及 /mp-health 只读无凭据健康入口。Cookie/Set-Cookie 不跨到 v2；Authorization、路径/query 保留。固定 /backends/ 管理路由不变。
- [x] 对核实可安全重复的 GET/HEAD 有限重试，所有带query的devices及method override、其他方法或副作用 GET 不重放；不配置 non_idempotent。
- [x] 实际 TLS fixtures 测试主用、主故障、备不可用、恢复、正确 SNI/Host、Bearer 保留、无浏览器 Cookie、URI/query、不重复 POST/PUT/DELETE；两组全部通过。

### Task 2: 小程序统一入口

Files: src/config/index.ts, .env.development, .env.production, src/api/scan-login.ts, config/scan tests and README in independent bujiaban-mp worktree.

- [x] 添加先失败的测试断言默认和生产入口为 https://checkin.voxel.cn/v2，可信扫码 origin 包含 checkin.voxel.cn。
- [x] 改默认与两环境 VITE_API_URL=https://checkin.voxel.cn/v2；可信名单保留现有三项并添加新入口。
- [x] 不添加客户端写重试、不改变凭据存储或 COS；urlCheck 保持 true。
- [x] 执行全部 Vitest、类型检查、lint 和 MP production build，等待独立审核。

### Task 3: 兼容性、部署和发布

- [x] 通过 Portainer 两容器只输出 JWT_KEY 文件 SHA256 指纹及微信 AppID，核验两端相同，避免输出密钥。
- [x] 只读核对两端健康版本和共同 Host/SNI；不停止生产服务作为测试。
- [x] 独立审查两仓库满足设计，再审代码与测试；逐项解决发现的问题。
- [ ] 依 ci-release 发布根 develop、main、publish，每次准确 HEAD CI 成功后推进；小程序独立 main CI 成功。
- [ ] 打包前端 dist、Dockerfile、两个 entrypoint 与 template，Portainer F 构建新镜像并在 stack24 启用四个 APP_MP_API 变量，检查健康并验证 /mp-health、/v2 入口。
- [ ] B0/B1 统一 ADMIN_SCAN_LOGIN_ORIGIN=https://checkin.voxel.cn 后核验规范 origin。
- [ ] 微信开发者工具 urlCheck=true 下重新登录、关联设备读取、扫码 B0/B1 新真实票据；无错误后官方 CLI 上传独立新版本。
- [ ] 记录实际部署/包状态、SHA、CI ID、验证证据及入口/共库/上游空游戏目录局限；清理仅本次生成临时工作树，归档管理工作树按实际需要。

审核记录：两阶段独立审核通过；方法覆盖与 expand=setup 副作用回归先失败再修复，最终真实 NGINX 42/42，无 skip。Web 136/136 与 api 构建成功；MP 207/207、类型、lint、微信构建成功，main 8300e512aebdef178cbe4809b9ad279d2cc7ffc7 的 CI 38149641700 success。
