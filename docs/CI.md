# CI 镜像发布

工作流定义在 `.github/workflows/ci.yml`，结构参考 `gdgeek/api.7dgame.com`，适配本项目的 PHP 8.4、Yii2 Basic 和 Codeception。

## 触发与标签

所有分支的 push 都运行测试；目标为 `develop`、`main`、`publish` 的 PR 也运行测试。仅这三个分支的 push 在测试通过后构建并发布镜像。

镜像地址：`hkccr.ccs.tencentyun.com/gdgeek/wechat`。

| 分支 | 发布标签 |
| --- | --- |
| `develop` | `develop` |
| `main` | `main` |
| `publish` | `publish`、`latest` |

`latest` 始终由 `publish` 分支更新。PR 和其他分支的 push 不发布镜像。同一分支的工作流串行执行。

## 执行流程

1. **Test**：启动 MariaDB 10.4 和 Redis 7，安装开发依赖，复制测试所需的配置模板，生成 Codeception actors，使用测试数据库执行迁移、单元测试和功能测试。任一步骤失败都会阻止镜像发布。
2. **Build and publish**：在新的 runner 中按锁文件安装应用依赖，生成 `api/GIT_COMMIT` 和 `api/BUILD_TIME`，使用 `dockerfile/Dockerfile` 构建一次，并推送该分支对应的标签。保留 Buildx 和 GitHub Actions 构建缓存。
3. **Publish notice**：仅 `publish` 分支执行，在 Actions 摘要中记录 `publish` 和 `latest` 镜像已发布。

测试配置只使用 CI 的本地数据库；迁移通过 `tests/bin/yii` 加载测试环境。

镜像提供匿名 `GET /health` 和 `HEAD /health`，检查数据库、Redis，并返回当前提交号和构建时间。Docker 健康检查每 30 秒访问此接口，使用约定的 200/503 状态判断可用性；详见 [健康检查](HEALTH.md)。

当前容器入口使用 Yii 的开发模式，需要 `yii2-debug` 和 `yii2-gii`，因此镜像构建仍安装开发依赖。若切换为 `--no-dev`，需先同步调整入口与应用配置。

## 凭据与部署

沿用本仓库已配置的 GitHub Actions secrets：

- `TCR_USERNAME`
- `TCR_PASSWORD`

与参考仓库一致，工作流的终点是镜像发布；运行环境由外部系统更新。旧流程的 Portainer Webhook 调用已移除，`PORTAINER_WEBHOOK_URL` 不再被此工作流使用。

首次将此工作流合入某个分支后，该分支后续的 push 才会使用新规则。三个分支都需要包含此工作流版本。
