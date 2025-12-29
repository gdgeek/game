<?php

namespace app\modules\v2\controllers;
use app\modules\v2\models\Control;
use app\modules\v2\models\File;
use yii\rest\ActiveController;
use app\modules\v2\models\FileSearch;
use app\modules\v2\models\User;
use app\modules\v2\helper\RootAuth;
use Yii;
use app\modules\v2\models\Device;
use app\modules\v2\models\DeviceSearch;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
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

    $behaviors['authenticator'] = [
      'class' => JwtHttpBearerAuth::class,
      'except' => ['options'],
    ];

    //如果是 Assign 的话2 用 RootAuth
    if (Yii::$app->request->getMethod() == 'DELETE' || Yii::$app->request->get('action') == 'assign') {
      $behaviors['authenticator'] = ['class' => RootAuth::class];
    } else {
      $behaviors['authenticator'] = [
        'class' => JwtHttpBearerAuth::class,
        'except' => ['options'],
      ];
    }

    return $behaviors;
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

    $user = Yii::$app->user->identity;
    $dataProvider = $searchModel->search(Yii::$app->request->queryParams, $pageSize);
    $query = $dataProvider->query;
    $query->select('device.*')->leftJoin('control', '`control`.`device_id` = `device`.`id`')->andWhere(['control.user_id' => $user->id]);
    return $dataProvider;
  }

  public function actionTest($device_id)
  {
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
    $control = Control::findOne(['device_id' => $device_id, 'user_id' => $user_id]);
    if ($control) {
      $control->delete();
      return ['message' => 'Device unassigned successfully', 'success' => true];
    } else {
      throw new \yii\web\NotFoundHttpException('Control not found');
    }
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
  {//POST ${id}/assign' => 'assign', 得到$id

    $phone = Yii::$app->request->post('phone');
    // $device_id = Yii::$app->request->post('device_id');
    $user = User::findOne(['tel' => $phone]);
    if ($user) {
      $control = new Control();
      $control->device_id = $device_id;
      $control->user_id = $user->id;
      $control->save();
      $user->save(); // to trigger beforeSave and update role
      return ['message' => 'Device assigned successfully', 'success' => true, 'data' => $control];
    }

    throw new \yii\web\NotFoundHttpException('User not found');
  }
}
