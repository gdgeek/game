<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\ActiveController;
use app\modules\v1\models\Device;
use app\modules\v1\helper\DeviceFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\Award;
use app\modules\v1\models\Gain;


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
      $device = $this->getDevice();
      return  [ 
        'message' => "new device", 
        'success' => true,
        'device'=> Device::findOne($device->id)];   
    }
    return [ 
      'message' => "old device", 
      'success' => true,
      'device'=> $device
    ];

  }
  private function getDevice(){
    $uuid = urldecode(Yii::$app->request->get('uuid'));
    $device = Device::find()->where(['uuid'=>$uuid])->one();
    if($device == null){
      $device = new Device();
      $device->uuid = $uuid;
      $device->ip = Yii::$app->request->userIP;
      if($device->validate() == false){
        throw new \yii\web\HttpException(400, 'Invalid device' . json_encode($device->errors));
      }
      $device->save();
    }
    return $device;
  }
  public function actionReady(){

    $device = $this->getDevice();
    $record = $device->record;
    if($record == null){
      return ["result" => false, "message"=>"No Ready", 'success' => false];  
    }

    return ["result" => true, 'game'=>$record->game, "message"=>"Ready to play", 'success' =>true];   
  }
  public function actionStart(){

    $device = $this->getDevice();
   
    if($device->record == null){
      throw new \yii\web\HttpException(400, 'No record');
    }
    $record = $device->record;
    if($record->status == "running"){
      throw new \yii\web\HttpException(400, 'Game is running');
    }
    $record->status = "running";
  
    if($record->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid record' . json_encode($record->errors));
    }
    $record->save();
    
    return [
      'result'=>true, 
      'message'=>'Game start', 
      'success' =>true, 
      'gmae' => $record->game
    ];
  }
  public function addGain($shop, $player, $type){
    $gain = new Gain();
    $gain->shop_id = $shop->id;
    $gain->player_id = $player->id;
    $gain->type = $type;

    if($gain->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid gain' . json_encode($gain->errors));
    }
    $gain->save();
  }
  public function actionFinish(){

    $device = $this->getDevice();

    if($device->record == null){
      throw new \yii\web\HttpException(400, 'No record');
    }
    if($device->record->status != "running"){
      throw new \yii\web\HttpException(400, 'Game is not running');
    }

    $shop = $device->shop;
    $award = Yii::$app->request->post("award");
   
    if(!$award){
      throw new \yii\web\HttpException(400, 'award is required');
    }
    $user = $device->record->user;
    $points = $award['points'];
    $user->points = $user->points + $points;
    $shop->expend = $shop->expend + $points;
    
   
    if(isset($award['s'])){
      for($i = 0; $i < $award['s']; $i++){
        $this->addGain($shop, $user, 's');
      }
    }
    if(isset($award['m'])){
      for($i = 0; $i < $award['m']; $i++){
        $this->addGain($shop, $user, 'm');
      }
    }
    if(isset($award['l'])){
      for($i = 0; $i < $award['l']; $i++){
        $this->addGain($shop, $user, 'l');
      }
    }
    if(isset($award['xl'])){
      for($i = 0; $i < $award['xl']; $i++){
        $this->addGain($shop, $user, 'xl');
      }
    }
   
    $player = $user->player;
    $device->record->delete();
    return ['result'=>true, 
            'message'=>'Game over', 
            'player' => $player, 
            'success' =>true];   
    
  }
  
}