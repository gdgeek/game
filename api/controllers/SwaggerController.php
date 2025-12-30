<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use OpenApi\Generator;
use OpenApi\Annotations as OA;
use OpenApi\Processors;

/**
 * @OA\Info(
 *     version="2.0.0",
 *     title="微信小程序后端 API (v2)",
 *     description="游戏娱乐管理系统 RESTful API 文档"
 * )
 * @OA\Server(url="/", description="API Server")
 * @OA\SecurityScheme(
 *     securityScheme="Bearer",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT"
 * )
 */
class SwaggerController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * 在执行任何操作前进行身份验证
     * 使用 HTTP Basic Authentication 保护 Swagger 文档
     * 凭据配置在 config/params.php 的 'swagger' 键中
     */
    public function beforeAction($action)
    {
        // 从配置文件读取凭据
        $swaggerConfig = Yii::$app->params['swagger'] ?? null;

        if (!$swaggerConfig) {
            throw new \yii\web\ServerErrorHttpException('Swagger 配置未找到');
        }

        // 检查 HTTP Basic Auth 凭据
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($username !== $swaggerConfig['username'] || $password !== $swaggerConfig['password']) {
            header('WWW-Authenticate: Basic realm="Swagger API Documentation"');
            header('HTTP/1.0 401 Unauthorized');
            echo '需要认证才能访问 API 文档';
            exit;
        }

        return parent::beforeAction($action);
    }

    /**
     * 渲染 Swagger UI 界面
     */
    public function actionIndex()
    {
        $swaggerUiUrl = Yii::$app->request->baseUrl . '/swagger-ui';
        $jsonSchemaUrl = Yii::$app->urlManager->createUrl(['swagger/json-schema']);
        
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API 文档 - Swagger UI</title>
    <link rel="stylesheet" href="{$swaggerUiUrl}/swagger-ui.css">
    <style>
        body { margin: 0; padding: 0; }
        .swagger-ui .topbar { display: none; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="{$swaggerUiUrl}/swagger-ui-bundle.js"></script>
    <script>
        window.onload = function() {
            SwaggerUIBundle({
                url: "{$jsonSchemaUrl}",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIBundle.SwaggerUIStandalonePreset
                ],
                layout: "BaseLayout"
            });
        };
    </script>
</body>
</html>
HTML;
    }

    /**
     * 生成 OpenAPI JSON Schema
     */
    public function actionJsonSchema()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        
        $baseDir = dirname(__DIR__); // api/
        
        // 显式指定要扫描的文件，避免目录遍历问题
        $scanFiles = [
            $baseDir . '/controllers/SwaggerController.php',
            // v2 Controllers
            $baseDir . '/modules/v2/controllers/AppletController.php',
            $baseDir . '/modules/v2/controllers/DeviceController.php',
            $baseDir . '/modules/v2/controllers/FileController.php',
            $baseDir . '/modules/v2/controllers/ManagerController.php',
            $baseDir . '/modules/v2/controllers/PlayerController.php',
            $baseDir . '/modules/v2/controllers/RootController.php',
            $baseDir . '/modules/v2/controllers/ServerController.php',
            $baseDir . '/modules/v2/controllers/SetupController.php',
            $baseDir . '/modules/v2/controllers/SiteController.php',
            $baseDir . '/modules/v2/controllers/TencentCloudController.php',
            $baseDir . '/modules/v2/controllers/WechatController.php',
            $baseDir . '/modules/v2/controllers/WechatPayController.php',
            // v2 Models
            $baseDir . '/modules/v2/models/Applet.php',
            $baseDir . '/modules/v2/models/Control.php',
            $baseDir . '/modules/v2/models/Device.php',
            $baseDir . '/modules/v2/models/DeviceSearch.php',
            $baseDir . '/modules/v2/models/File.php',
            $baseDir . '/modules/v2/models/FileSearch.php',
            $baseDir . '/modules/v2/models/RecodeFile.php',
            $baseDir . '/modules/v2/models/Report.php',
            $baseDir . '/modules/v2/models/Setup.php',
            $baseDir . '/modules/v2/models/User.php',
        ];

        // 简单的文件存在性检查
        $existingFiles = array_filter($scanFiles, 'file_exists');
        
        // 如果没有找到文件，返回调试信息
        if (empty($existingFiles)) {
             return [
                 'error' => 'No files found to scan',
                 'baseDir' => $baseDir,
                 'attempted' => $scanFiles
             ];
        }

        $openapi = Generator::scan($existingFiles, ['validate' => false]);
        
        return json_decode($openapi->toJson());
    }
}
