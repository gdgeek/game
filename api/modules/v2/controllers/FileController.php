<?php

namespace app\modules\v2\controllers;
use app\modules\v2\models\File;
use yii\rest\ActiveController;
use app\modules\v2\models\FileSearch;


class FileController extends ActiveController
{
  public $modelClass = 'app\modules\v2\models\File';
  public function behaviors()
  {
    $behaviors = parent::behaviors();
   /* $behaviors['authenticator'] = [
      'class' => DeviceFingerprintAuth::class,
    ];*/

    return $behaviors;
  }
  

  public function actionList($unionid){
    $searchModel = new FileSearch();
    $dataProvider = $searchModel->search(['FileSearch' => ['unionid' => $unionid]]);
    return $dataProvider->getModels();
  }

}
