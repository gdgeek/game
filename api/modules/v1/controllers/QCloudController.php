<?php

namespace app\modules\v1\controllers;

use Yii;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;

// 引入 COS SDK (需: composer require qcloud/cos-sdk-v5:^2.0)
use Qcloud\Cos\Client;

class QCloudController extends Controller
{
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        // ...existing code...
        $behaviors['corsFilter'] = [
            'class' => \yii\filters\Cors::class,
            'cors' => [
                'Origin' => ['*'],
                'Access-Control-Request-Method' => ['GET'],
                'Access-Control-Request-Headers' => ['*'],
                'Access-Control-Allow-Credentials' => null,
                'Access-Control-Max-Age' => 86400,
                'Access-Control-Expose-Headers' => [
                    'X-Pagination-Total-Count',
                    'X-Pagination-Page-Count',
                    'X-Pagination-Current-Page',
                    'X-Pagination-Per-Page',
                ],
            ],
        ];
        // ...existing code...
        return $behaviors;
    }

    public function actions()
    {
        return [];
    }

    /**
     * 生成 COS 预签名访问 URL
     * GET /v1/q-cloud/object-url?key=path/to/file.jpg&method=GET&expires=900
     * 支持:
     *  - method: GET | HEAD | PUT
     *  - expires: 1~86400 秒
     *  - raw=1 仅返回 URL
     *  - contentType 覆盖上传/下载 Content-Type
     *  - disposition 覆盖下载 Content-Disposition (attachment; filename="xxx")
     *  - response-content-type / response-content-disposition 等标准覆写
     *  - force_sts=1 使用临时密钥 (如果实现了获取临时凭证逻辑，可自行扩展)
     */
    public function actionObjectUrl(string $key, int $expires = 900, ?string $method = 'GET')
    {
        $method = strtoupper($method ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD', 'PUT'], true)) {
            throw new BadRequestHttpException('method 仅支持 GET/HEAD/PUT');
        }
        if ($expires < 1 || $expires > 86400) {
            throw new BadRequestHttpException('expires 必须在 1~86400 之间');
        }

        if (!class_exists(Client::class)) {
            throw new BadRequestHttpException('未安装 COS SDK，请先执行: composer require qcloud/cos-sdk-v5:^2.0');
        }

        $cloud = Yii::$app->secret->cloud ?? [];
        $bucket = $cloud['bucket'] ?? null;
        $region = $cloud['region'] ?? 'ap-nanjing';
        if (!$bucket) {
            throw new BadRequestHttpException('缺少 bucket 配置');
        }

        $key = ltrim($key, '/');
        if ($key === '') {
            throw new BadRequestHttpException('key 不能为空');
        }

        // 默认使用永久密钥
        $secretId = Yii::$app->secret->id ?? null;
        $secretKey = Yii::$app->secret->key ?? null;
        $sessionToken = null;

        if (!$secretId || !$secretKey) {
            throw new BadRequestHttpException('缺少主账号密钥配置');
        }

        // 可选: 使用临时密钥 (这里预留扩展点, 需你自己实现获取临时凭证逻辑)
        $useSts = (bool)Yii::$app->request->get('force_sts', false);
        if ($useSts && method_exists($this, 'actionToken')) {
            try {
                // 假设你在其他控制器实现了 actionToken，可改为 service 调用
                $stsResp = (new \app\modules\v1\controllers\TencentCloudController('tc', Yii::$app))->actionToken($bucket, $region);
                $credObj = $stsResp->getCredentials();
                $secretId = $credObj->getTmpSecretId();
                $secretKey = $credObj->getTmpSecretKey();
                $sessionToken = $credObj->getToken();
                $remain = $stsResp->getExpiredTime() - time();
                if ($remain < $expires) {
                    $expires = max(1, $remain - 5);
                }
            } catch (\Throwable $e) {
                throw new BadRequestHttpException('获取临时密钥失败: ' . $e->getMessage());
            }
        }

        $config = [
            'region' => $region,
            'schema' => 'https',
            'credentials' => [
                'secretId' => $secretId,
                'secretKey' => $secretKey,
            ],
        ];
        if ($sessionToken) {
            $config['credentials']['token'] = $sessionToken;
        }

        $client = new Client($config);

        $options = [
            'Method' => $method,
        ];

        // 覆写响应头 (下载场景)
        $overrideQueryKeys = [
            'response-content-type',
            'response-content-disposition',
            'response-content-language',
            'response-cache-control',
            'response-expires',
            'response-content-encoding',
        ];
        foreach ($overrideQueryKeys as $qk) {
            $v = Yii::$app->request->get($qk);
            if ($v !== null) {
                $options['Params'][$qk] = $v;
            }
        }

        if ($ct = Yii::$app->request->get('contentType')) {
            $options['Headers']['Content-Type'] = $ct;
        }
        if ($disp = Yii::$app->request->get('disposition')) {
            $options['Params']['response-content-disposition'] = $disp;
        }

        try {
            // $expires 可直接为整型秒数
            $url = $client->getObjectUrl($bucket, $key, $expires, $options);
        } catch (\Throwable $e) {
            throw new BadRequestHttpException('生成 URL 失败: ' . $e->getMessage());
        }

        if (Yii::$app->request->get('raw') == 1) {
            return $url;
        }

        return [
            'success' => true,
            'data' => [
                'bucket' => $bucket,
                'region' => $region,
                'key' => $key,
                'method' => $method,
                'usesSts' => $useSts,
                'expiresIn' => $expires,
                'expireAt' => time() + $expires,
                'url' => $url,
            ],
        ];
    }
}