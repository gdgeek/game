<?php

namespace app\modules\v2\controllers;

use yii\rest\ActiveController;


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

}
