<?php

namespace app\modules\v1\controllers;
use Yii;
use app\modules\v1\helper\DeviceFingerprintAuth;
use yii\rest\ActiveController;

class DeviceController extends ActiveController
{

  public $modelClass = 'app\modules\v1\models\Device';
  public function behaviors()
  {
      
      $behaviors = parent::behaviors();
      
      $behaviors['corsFilter'] = [
          'class' => \yii\filters\Cors::className(),
          'cors' => [
              'Origin' => ['*'],
              'Access-Control-Request-Method' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'],
              'Access-Control-Request-Headers' => ['*'],
              'Access-Control-Allow-Credentials' => null,
              'Access-Control-Max-Age' => 86400,
              'Access-Control-Expose-Headers' => [
                  'X-Pagination-Total-Count',
                  'X-Pagination-Page-Count',
                  'X-Pagination-Current-Page',
                  'X-Pagination-Per-Page',
              ],
          ],
      ];
      $behaviors['authenticator'] = [
        'class' => DeviceFingerprintAuth::className(),
      ];
      
      return $behaviors;
  }

  public function actionRegister(){

  }
  public function actionReady(){

  }
  public function actionStart(){

  }
  public function actionOver(){

    
  }
  
}