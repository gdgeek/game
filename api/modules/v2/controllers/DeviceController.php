<?php

namespace app\modules\v2\controllers;

use yii\rest\ActiveController;
use app\modules\v2\helper\DeviceAccess;
use app\modules\v2\helper\DeviceBearerAuth;
use Yii;
use app\modules\v2\models\DeviceSearch;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="设备",
 *     description="设备管理接口"
 * )
 */
class DeviceController extends ActiveController
{
    public $modelClass = 'app\modules\v2\models\Device';
    public function behaviors()
    {
        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = ['class' => DeviceBearerAuth::class, 'except' => ['options']];
        return $behaviors;
    }

    public function actions()
    {
        $actions = parent::actions();
        $actions['index']['prepareSearchQuery'] = static function ($query) { return DeviceAccess::scope($query, 'device.id'); };
        foreach (['view', 'update', 'delete'] as $action) {
            $actions[$action]['findModel'] = static function ($id) { return DeviceAccess::device($id); };
        }
        return $actions;
    }

    public function beforeAction($action)
    {
        if (!parent::beforeAction($action)) { return false; }
        if (in_array($action->id, ['create', 'delete', 'assign', 'unassign'], true)) { DeviceAccess::denyAdministration(); }
        return true;
    }

    protected function verbs()
    {
        return array_merge(parent::verbs(), ['manage' => ['GET'], 'assign' => ['POST'], 'unassign' => ['DELETE']]);
    }

    /**
     * @OA\Get(
     *     path="/v2/devices/manage",
     *     tags={"设备"},
     *     summary="设备管理列表",
     *     @OA\Parameter(name="pageSize", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="列表数据")
     * )
     */
    public function actionManage()
    {
        //改成 DeviceSearch
        $searchModel = new DeviceSearch();
        $pageSize = Yii::$app->request->get('pageSize', 15);

        $dataProvider = $searchModel->search(Yii::$app->request->queryParams, $pageSize);
        DeviceAccess::scope($dataProvider->query, 'device.id');
        return $dataProvider;
    }

    public function actionTest($device_id)
    {
        DeviceAccess::device($device_id);
        return "test" . $device_id;
    }
    /**
     * @OA\Delete(
     *     path="/v2/devices/{device_id}/assign/{user_id}",
     *     tags={"设备"},
     *     summary="取消分配",
     *     @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="user_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionUnassign($device_id, $user_id)
    {
        DeviceAccess::denyAdministration();
    }
    /**
     * @OA\Post(
     *     path="/v2/devices/{device_id}/assign",
     *     tags={"设备"},
     *     summary="分配设备给用户",
     *     @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="phone", type="string", description="用户手机号")
     *         )
     *     ),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionAssign($device_id)
    {
        DeviceAccess::denyAdministration();
    }
}
