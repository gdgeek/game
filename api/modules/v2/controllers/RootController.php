<?php

namespace app\modules\v2\controllers;

use yii\rest\Controller;
use app\modules\v2\helper\DeviceAccess;
use app\modules\v2\helper\DeviceBearerAuth;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Root管理",
 *     description="Root 权限接口"
 * )
 */
class RootController extends Controller
{
    public function behaviors()
    {

        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => DeviceBearerAuth::class,
        ];
        return $behaviors;
    }
    public function actionAssign()
    {
        DeviceAccess::denyAdministration();
    }
}
