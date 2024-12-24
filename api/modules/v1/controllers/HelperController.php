<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\ActiveController;

class HelperController extends ActiveController
{

  public $modelClass = 'app\modules\v1\models\Player';
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
      
    
      
      return $behaviors;
  }
  public function actionTest()
  {
      $helper = Yii::$app->helper;
      return $helper->record();
    
  }
  public function actionPrint()
  {

    $helper = Yii::$app->helper;
    return $helper->play();

   
  }
  
}