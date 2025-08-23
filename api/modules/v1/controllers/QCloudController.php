<?php

namespace app\modules\v1\controllers;
use app\modules\v1\models\File;
use yii\helpers\ArrayHelper;
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
       $file = File::Create("sdf",$key);
       $file->save();
       return $file;
    }
}