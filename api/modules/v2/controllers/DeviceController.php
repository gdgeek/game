<?php

namespace app\modules\v2\controllers;
use app\modules\v2\models\Control;
use app\modules\v2\models\File;
use yii\rest\ActiveController;
use app\modules\v2\models\FileSearch;
use app\modules\v2\helper\RootAuth;
use Yii;
use app\modules\v2\models\Device;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

class DeviceController extends ActiveController
{
  public $modelClass = 'app\modules\v2\models\Device';
  public function behaviors()
  {
    $behaviors = parent::behaviors();
    $behaviors['authenticator'] = [
      'class' => CompositeAuth::class,
      'authMethods' => [
        JwtHttpBearerAuth::class,
      ],
      'except' => ['options'],
    ];

    return $behaviors;
  }

  /*
  public function actionAssign($id)
  {
    $phone = Yii::$app->request->post('phone');
    $user = User::findOne(['tel' => $phone]);
    if ($user) {
      $control = new Control();
      $control->device_id = $id;
      $control->user_id = $user->id;
      $control->save();
      $user->save(); // to trigger beforeSave and update role
      return ['message' => 'Device assigned successfully', 'success' => true, 'data' => $control];
    }

    throw new \yii\web\NotFoundHttpException('User not found');
  }*/

  public function actionManage()
  {
    $user = Yii::$app->user->identity;
    //通过 Control 拿到 devices joinLeft
    $query = Device::find()->innerJoinWith('control')->where(['control.user_id' => $user->id]);
    return $query->all();
  }
}
