# 健康检查

匿名访问 `GET /health`。数据库执行 `SELECT 1`，配置了 Redis 时执行 `PING`；所有依赖正常返回 HTTP 200，任一依赖异常返回 HTTP 503。

```sh
curl -i https://<服务器域名>/health
curl -I https://<服务器域名>/health
```

正常响应示例：

```json
{
  "status": "healthy",
  "timestamp": "2026-10-11T12:34:56+08:00",
  "checks": {
    "database": {"status": "up", "responseTime": 2},
    "redis": {"status": "up", "responseTime": 1}
  },
  "gitCommit": "c8b7de92d051a603707e4181725b281636a14509",
  "version": "2026-10-11 12:00:00"
}
```

`responseTime` 单位为毫秒。异常依赖返回 `status: down` 和通用 `error`，不会输出连接地址、密码或异常堆栈。`HEAD /health` 执行相同检查并返回相同状态码，响应体为空。响应设置 `Cache-Control: no-store`。

正常请求继续使用 Redis 路由缓存。只有 `/health` 请求在初始化路由时绕过缓存，确保 Redis 故障时仍能进入健康检查并返回 JSON 503；业务缓存也继续使用 Redis。

CI 构建镜像前生成 `api/GIT_COMMIT` 和 `api/BUILD_TIME`，分别记录提交号和北京时间的构建时间；接口通过 `gitCommit` 和 `version` 返回。手动构建时这些文件可省略，对应字段不会出现。读取位置依次为应用目录及其父目录。

生产 Docker 镜像每 30 秒通过 PHP 请求容器内 `http://127.0.0.1/health`，只有 HTTP 请求成功且 JSON 中 `status` 为 `healthy` 才成功；启动宽限 30 秒，单次检查超时 15 秒，连续失败 3 次标记为 `unhealthy`。部署时需提供有效的数据库和 Redis 配置。该检查反映应用及依赖的可用性；Docker 健康状态本身不会自动重启容器。
