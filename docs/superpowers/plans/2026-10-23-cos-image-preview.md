# COS 签名图片预览与 latest 发布计划

> **For agentic workers:** 使用 subagent-driven-development 并行实现后端与前端，完成审查后执行 ci-release 的三阶段门禁。

**Goal:** Web 已保存的设备图片与缩略图使用短期 COS 签名正常显示，并支持原比例悬停大图，测试后发布前后端 latest。

**Architecture:** 增加只读 `GET /admin-api/devices/{id}/image-previews`。沿用管理端 JWT 和设备 control 权限，服务端从该设备已保存 pictures/thumbs 取原地址，只为配置 bucket/region 的 HTTPS COS 对象签 GET，返回 `{previews:[{source,url,expiresAt}]}`，有效期 300 秒、响应 no-store。浏览器不接收密钥，不改变持久化图片地址，外部公开图片继续直接加载。前端在编辑器打开时加载签名映射，并在到期前更新；未保存的新私有地址保存后才可获取预览，界面给出简短说明。

**Tech Stack:** Yii2、既有 qcloud COS PHP SDK、Vue 3、Vitest/PHPUnit、GitHub Actions、香港 CCR latest、Watchtower。

- [x] 隔离最新 origin/develop，保留并行 Unity 版本提交和原工作区 dirty，仅携带本会话已有缩略图/悬停改动。
- [x] 后端先补权限、签名范围、有效期、无配置写入的失败测试；增加签名服务、设备读取方法、controller 与路由，测试通过。
- [x] 前端增加带 JWT 的图片预览 API、到期刷新和 editor 签名映射，图片源变化时清除失败状态；保存仍只发送原地址。demo 保持可用。
- [x] 覆盖真实 GET 签名格式、JWT/设备越权、外部 URL 不签名、到期刷新和保存原地址；运行前后端有意义的现有检查并审查 diff。
- [ ] 以真实 COS 图片完成缩略图和大图核验，保存无密钥/无令牌的结果与截图。
- [ ] develop 完整 CI 成功后提升 main，再完整成功后提升 publish，核对两个 latest 镜像与线上前端、B0、B1 提交。
- [ ] 安全同步本次源码回原工作区，不覆盖并行改动；保存发布记录并清理本次临时工作区。

## 接口契约

`GET /admin-api/devices/123/image-previews` 使用现有 `Authorization: Bearer …` 与 owner proof（device manager 会话时）的传输方式。

```json
{"previews":[{"source":"https://configured-bucket.cos.configured-region.myqcloud.com/picture/t1.png","url":"https://configured-bucket.cos.configured-region.myqcloud.com/picture/t1.png?SIGNED_QUERY","expiresAt":1792700000}]}
```

`source` 是已保存原地址，`expiresAt` 为 Unix 秒。跳过非配置 COS 主机及无效地址；无图片返回空列表。设备不存在或无权访问时返回 404；未登录返回 401；COS 配置暂不可用时仅在需要签名时返回不含敏感信息的 503。只读取 setup，禁止 GET 时创建默认 setup。不得把任何签名 URL、密钥或令牌写回设备配置或日志。

## 本地验证

- 前端：16 files / 189 tests 全部通过，Node 24 生产构建通过。
- 后端：管理 API、COS 图片签名、认证配置、JWT、场景目录及扫码回归共 152 tests / 992 assertions 通过。
- 前后端独立审查 PASS；真实 SDK 的 GET HMAC 与 300 秒截止时间已核验。
