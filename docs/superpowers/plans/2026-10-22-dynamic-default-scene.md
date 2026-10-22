# 动态默认场景实现计划

**目标：** 设备默认 `scene_id` 使用 Y1 的 checkin 目录中第一个有效 `verse_id`，移除固定编号。

**方案：** 复用现有 `AdminGameCatalog` 的 `/checkin` 请求、分页、数据归一化和 30 秒缓存，保留 Y1 返回顺序。仅生成默认配置时查询目录；已有配置继续沿用。目录为空时场景为 `null`，上游失败沿用明确的 503 错误。后台手动分配已校验的游戏时直接使用选中编号，避免再次查询默认场景。

**范围：** `Setup::DefaultInfo()`、设备首次建档的调用顺序、后台首次配置调用及其回归测试。保留工作区已有修改，不调整小程序字段或已有设备配置。

**技术栈：** PHP / Yii2 / PHPUnit，SQLite 内存数据库与可注入的场景目录。

- [x] 为默认场景选择、新设备持久化、空目录、已有配置、上游错误重试和后台显式选择补充回归测试，先运行确认固定值实现失败（39 项测试中 5 项按预期失败）。
- [x] `DefaultInfo(?int $sceneId = null)` 通过 `Yii::createObject(AdminGameCatalog::class)` 读取首个有效场景；显式编号直接使用，空列表得到 `null`。
- [x] 新设备在默认信息获取成功后再入库，避免上游失败留下无法完成初始化的设备；后台首次分配传入已校验的 `$gameId`。
- [x] 运行相关 PHPUnit 测试、PHP 语法检查与 diff 检查；同步设备协议说明。相关套件通过 100 项测试、528 个断言；4 个修改的 PHP 文件语法检查通过，`git diff --check` 通过。

发布准备：基于远端 `develop` 的 `2896907` 隔离移入本次改动，保留已发布的 Y1 接入和 JWT 后台鉴权，新增测试适配已有 Bearer 测试 helper。该版本相关套件运行 131 项、644 个断言，9 项 Redis 集成测试因未配置本地测试 Redis 跳过，由 CI 的 Redis 服务验证。Y1 checkin 请求仍为直接 GET，不添加 JWT。

验证命令：

```sh
./vendor/bin/phpunit --bootstrap tests/_bootstrap.php tests/unit/components/AdminApiTest.php
./vendor/bin/phpunit --bootstrap tests/_bootstrap.php --filter 'Admin|SceneServerConfig' tests/unit/components
```
