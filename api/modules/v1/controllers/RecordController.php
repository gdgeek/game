<?php

namespace app\modules\v1\controllers;
use Yii;
use app\modules\v1\helper\DeviceFingerprintAuth;
use yii\rest\ActiveController;

use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

class RecordController extends ActiveController
{

  public $modelClass = 'app\modules\v1\models\Record';
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


  
}