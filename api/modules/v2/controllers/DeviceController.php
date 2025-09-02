<?php

namespace app\modules\v2\controllers;
use app\modules\v2\models\Control;
use app\modules\v2\models\File;
use yii\rest\ActiveController;
use app\modules\v2\models\FileSearch;
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
      'class' => CompositeAuth::class,
      'authMethods' => [
        JwtHttpBearerAuth::class,
      ],
      'except' => ['options'],
    ];

    return $behaviors;
  }

  public function actionManage()
  {
    //改成 DeviceSearch
    $searchModel = new DeviceSearch();
    $pageSize = Yii::$app->request->get('pageSize', 15);

    $user = Yii::$app->user->identity;
    $dataProvider = $searchModel->search(Yii::$app->request->queryParams,  $pageSize);
    $query = $dataProvider->query;
    $query->select('device.*')->leftJoin('control', '`control`.`device_id` = `device`.`id`')->andWhere(['control.user_id' => $user->id]);
    return $dataProvider;
  }
}
