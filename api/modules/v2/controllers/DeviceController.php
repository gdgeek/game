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
