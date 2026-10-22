# 前端 latest 自动发布实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Track each step with checkboxes.

**Goal:** 前端与后端都经 develop → main → publish CI 发布到香港镜像仓库，生产统一跟随 latest 自动更新。

**Architecture:** 保留现有后端 CI；新增前端测试、构建和镜像发布步骤，使用同一腾讯仓库凭据。前端镜像为 hkccr.ccs.tencentyun.com/gdgeek/checkin-frontend，分支分别发布同名标签，仅 publish 更新 latest。构建上下文只复制 dist 与既有 Docker/Nginx 启动文件，沿用当前自动代理与 JWT。

**Tech Stack:** GitHub Actions、Node.js 24、Vue/Vite、Docker Buildx、Tencent CCR、Portainer、Watchtower。

- [x] CI：修改 .github/workflows/ci.yml，运行 npm ci、npm test、npm run build；将 frontend/dist、FrontendDockerfile、Nginx 模板和 40/50 启动脚本复制进 RUNNER_TEMP 的白名单上下文，再用既有登录方式发布前端镜像。publish_notice 等待两个镜像成功。
- [x] 部署约定：dockerfile/admin.compose.yaml 改为完整仓库的 checkin-frontend:latest；更新 frontend/README.md 与 docs/ADMIN_API.md，保留现有 APP_API 与 APP_MP 配置，不增加服务或凭据。
- [x] 核验：Node.js 24.12.0 干净安装、168 项前端测试及生产构建通过；YAML、Compose 和白名单上下文校验通过；规格审查通过，后端实现未改动。
- [ ] 发布：每一阶段 CI 完整成功后再提升下一阶段，保留并行设备版本提交，三个分支最终文件树一致。
- [ ] 上线：将现有 checkin-console 镜像地址换成香港仓库 latest，沿用 Watchtower；验证前端静态资源、健康检查、B0/B1 实际提交及登录配置。
- [ ] 本地同步与清理：保留工作区独立改动及嵌套仓库，快进本地最新代码，保留备份并清理本次临时工作区。
