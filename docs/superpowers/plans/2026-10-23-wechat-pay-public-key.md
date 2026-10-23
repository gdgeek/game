# 微信支付公钥切换实施计划

**Goal:** 完成商户指引第二步的请求头、回调和应答验签兼容，并准备第三步的线上配置与验收。

**Architecture:** 在共享 Wechat 组件创建的 EasyWeChat Application 中统一接入带验签的 Client/Server；公钥 ID 使用现有配置，额外平台证书从可选 JSON 路径列表加载，并从 X509 读取序列号。全部 APIv3 成功响应和回调按 Wechatpay-Serial 精确选取验签材料。

**Tech Stack:** PHP 8.4、Yii2、EasyWeChat 6.19、PHPUnit、Symfony MockHttpClient、OpenSSL。

- [x] 核对官方指引、SDK 行为及两个下载文件。两个公钥文件 SHA256 相同：cbbce05c8f4e9ce607c7c5ab67ed372fb4ef625600c1d1c5a0c638ce6c4b3b08。
- [x] 创建隔离工作区；现有 AdminAuthConfigTest 基线 3 项 / 25 断言通过。
- [x] 在 `api/tests/unit/components/WechatPayVerificationTest.php` 先添加离线失败测试，覆盖连续请求头、公钥和旧证书签名、未知序列号、篡改回调/应答、空应答及环境配置。初次 21 项中 20 项按预期失败；实现后 21 项 / 48 断言通过。
- [x] 修改 `api/modules/v1/components/Wechat.php` 和 `files/api/config/web.php`，保留现有 ID/path 配置并增加 `WECHAT_PLATFORM_CERTS` JSON 路径列表。
- [x] 新建 `api/components/WechatPayApplication.php`、`WechatPayClient.php`、`WechatPayServer.php`，统一验证 APIv3 应答和回调；验签失败不进入业务逻辑。
- [x] 运行定向 PHPUnit、Codeception 与 PHP 语法检查；复核 v1/v2 均通过共享组件接入。补充真实路由用例后，PHPUnit 62 项 / 181 断言通过；新支付兼容用例 Codeception 29 项 / 76 断言通过；现有小程序支付用例 Codeception 通过。
- [x] 写入 `docs/WECHAT_PAY_PUBLIC_KEY.md`，记录部署变量、公钥校验值、旧证书保留与灰度验收步骤。
- [x] 独立复核最终 diff，完成本地第二步开发。以 origin/publish b777adf 为部署基线保留现有支付业务；复核发现并修正旧版支付和两处退款回调未实际调用 serve() 的问题。
- [ ] 核对并更新用户指定的 B0、B1 生产验签材料与代码；现已接入内置浏览器中两端登录页，开始只读核对。
- [ ] 用户在商户平台查看第三步的回调/应答比例；浏览器站点安全策略禁止代理进入商户平台，不通过其他接口绕过。双方确认全业务覆盖后由用户完成平台确认切换。

验证命令：`php api/vendor/bin/phpunit --do-not-cache-result --bootstrap api/tests/_bootstrap.php api/tests/unit/components/WechatPayVerificationTest.php`。测试仅使用临时生成的测试密钥与模拟 HTTP，不调用微信、不创建真实交易。
