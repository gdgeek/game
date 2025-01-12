<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\ActiveController;
use app\modules\v1\models\Device;
use app\modules\v1\helper\DeviceFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\Award;
class GameController extends ActiveController
{

  public $modelClass = 'app\modules\v1\models\Device';
  public function behaviors()
  {
      
      $behaviors = parent::behaviors();
      
   

      $behaviors['authenticator'] = [
        'class' => DeviceFingerprintAuth::className(),
      ];
      
      return $behaviors;
  }

  public function actionDevice(){
    
    $uuid = urldecode(Yii::$app->request->get('uuid'));
    $device = Device::find()->where(['uuid'=>$uuid])->one();
    if($device == null){
      $device = new Device();
      $device->uuid = $uuid;
      $device->status = 'unused';
      if($device->validate() == false){
        throw new \yii\web\HttpException(400, 'Invalid device' . json_encode($device->errors));
      }
      $device->save();
      return Device::findOne($device->id);
    }
    return $device;

  }
  public function actionReady(){

    return ["result" => true, "message"=>"Ready to play"];   
  }
  public function actionStart(){

    $game = new Game();
    return $game;

  }
  public function actionFinish(){
    return ["result"=>true, "message"=>"Game over"];   
    
  }
  
}