<?php

namespace app\modules\v2\controllers;

use yii\rest\ActiveController;
use app\modules\v2\helper\DeviceAccess;
use app\modules\v2\helper\DeviceBearerAuth;
use Yii;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="设置",
 *     description="系统设置接口"
 * )
 */
class SetupController extends ActiveController
{
    public $modelClass = 'app\modules\v2\models\Setup';
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => DeviceBearerAuth::class,
            'except' => ['options'],
        ];

        return $behaviors;
    }

    public function actions()
    {
        $actions = parent::actions();
        $actions['index']['prepareSearchQuery'] = static function ($query) { return DeviceAccess::scope($query, 'setup.device_id'); };
        foreach (['view', 'update', 'delete'] as $action) {
            $actions[$action]['findModel'] = static function ($id) { return DeviceAccess::setup($id); };
        }
        return $actions;
    }

    public function checkAccess($action, $model = null, $params = [])
    {
        if ($action === 'create') {
            DeviceAccess::device(DeviceAccess::bodyDeviceId(Yii::$app->request->getBodyParam('device_id')));
        }
        if ($action === 'update') {
            $body = Yii::$app->request->bodyParams;
            if (array_key_exists('device_id', $body) && DeviceAccess::bodyDeviceId($body['device_id']) !== (int) $model->device_id) {
                throw new \yii\web\ForbiddenHttpException('不可将设备配置重新关联到其他设备');
            }
        }
    }
}
