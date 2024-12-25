<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\ActiveController;
use app\modules\v1\models\Player;

class PlayerController extends ActiveController
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

  public function actionSignUp()
  {
    $helper = Yii::$app->helper;
    $helper->record();
    return ['post'=>Yii::$app->request->post(),'get'=>Yii::$app->request->get(),'headers'=>Yii::$app->request->headers];
    
  }
  public function actionSignIn()
  {
    $helper = Yii::$app->helper;
    $helper->record();
    $json = Yii::$app->request->post("parameters");
    if(json_validate($json) == false){
      throw new \yii\web\HttpException(400, 'Invalid JSON');
    }
    $params = json_decode($json, false);

    echo $params->openId; 
    echo $params->fingerprint;
    echo $params->timestamp;


    $inputString = "geek.v0xe1.pa2ty.c0m". $params->timestamp . $params->openId;
    
    if($params->fingerprint != md5($inputString)){
      throw new \yii\web\HttpException(400, 'Invalid fingerprint');
    }
    $player = Player::find()->where(['openid'=>$params->openId])->one();
    if($player == null){
     return ['time'=>time(),'result'=>"no signup"];
    }
   
    return ['time'=>time(), 'result'=>"success", 'player'=>$player];
    
  }

}