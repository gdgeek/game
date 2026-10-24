# 小程序 B0/B1 自动切换设计

用户在确认统一反向代理方案后，明确要求“启动自动切换”。小程序请求统一走 https://c.4mr.cn/v2/，代理优先 B0，B0 出现连接/超时/502/503/504 故障时使用 B1。两端保持共享用户、票据、订单、设备数据，核验 JWT 文件指纹相同。

Web /backends/b0/admin-api/ 与 /backends/b1/admin-api/ 保持固定代理与独立 Cookie Path，避免本机 PHP Session 在切实例时丢失。两后端 ADMIN_SCAN_LOGIN_ORIGIN 统一 https://c.4mr.cn，让小程序的临时微信登录与批准经统一入口，票据 audience 一致。

NGINX 用共享 zone、max_fails/fail_timeout 与备机实现被动故障识别，DNS 动态解析。上游地址分别为 B0/B1，而共同 Host/SNI 为 x.4mr.cn；已分别通过 curl --connect-to 验证两端共同域名 TLS 与健康响应。继续验证证书，保留 Bearer，剥离浏览器 Cookie，不暴露 /admin-api 或任意代理。

仅核实可安全重复的 GET/HEAD 查询允许失败后有限重试：无 query 的 devices 列表/manage/数字 ID 以及 server/scenes。设备鉴权可能幂等更新派生 role，scenes 可能写派生缓存，因此不宣称零写入。所有带 query 的 devices 请求（expand=setup 可能创建 Setup）、非空 X-Http-Method-Override、其他请求（含有副作用的 GET server/info、POST 微信换码/支付/打卡/批准、PUT/DELETE）不自动重放。健康探针清空 method override。非重试路由的连接/超时失败会隔离故障 peer，HTTP 5xx 原样返回且不触发隔离；后续可安全重复的查询或健康探针识别故障后切备。默认不启用 non_idempotent。用本地真实 NGINX + 两个 TLS 测试服务验证主用、故障切换、恢复、Header/URI 与不重放，线上不停止真实后端、不修改真实设备、不支付。

小程序默认/API环境改统一入口；扫码信任增加 c.4mr.cn，保留已发布旧域名用于滚动升级，urlCheck 保持 true，COS 媒体直连保持现状。微信公众平台先前被浏览器站点策略阻止，不绕过；新包可用官方开发者工具测试上传，提审/正式发布须平台可用。代理入口和共库依旧是可用性依赖，不声称覆盖其故障。
