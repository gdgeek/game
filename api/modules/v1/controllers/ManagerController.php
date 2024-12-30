<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\ActiveController;
use app\modules\v1\models\Device;
use app\modules\v1\helper\PlayerFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\Award;
use app\modules\v1\models\Player;
class ManagerController extends ActiveController
{

  public $modelClass = 'app\modules\v1\models\Manager';
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
        'class' => PlayerFingerprintAuth::className(),
      ];
      
      return $behaviors;
  }

  
  
  public function actionLogin(){
    
    $openId = Yii::$app->request->post('openId');
    $player = Player::find()->where(['openId'=>$openId])->one();
   
    if($player != null && $player->manager != null){
      $player_id = Yii::$app->request->post('player_id');
      if($player_id != null){
        
        $target = Player::findOne($player_id);
        return ["manager"=> $player->manager, "target"=>$target];
      }
      return ["manager"=>$player->manager];
    }else{
      throw new \yii\web\HttpException(400, 'Not Manager');
    }
  }
 
  
}