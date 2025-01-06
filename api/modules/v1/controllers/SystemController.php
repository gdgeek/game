<?php

namespace app\modules\v1\controllers;
use Yii;

use yii\rest\Controller;
use app\modules\v1\models\Device;
use app\modules\v1\helper\PlayerFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\Award;
use app\modules\v1\models\Player;
use app\modules\v1\models\Shop;
use app\modules\v1\models\Record;

use app\modules\v1\models\User;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
//root，
//管理员， （可以查看所有信息） Administrator
//店长，（可以修改店家信息） Manager 
//工作人员， （可以修改设备信息） Manager
//玩家 Player
class SystemController extends Controller
{

 // public $modelClass = 'app\modules\v1\models\Manager';
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

  
  
  public function actionPlayerInfo($id){
    
    $user = User::findOne($id);
    if($user == null){
        
    }
    return ['success'=>true, 'player'=>$user->info, 'message'=>'success'];
  }

  
  public function actionReadyGame($targetId){

    $user = Yii::$app->user->identity;
    if(!$user->manager){
        throw new \yii\web\HttpException(400, 'No Permission');
    }

    $target = User::findOne($targetId);
    if($target == null){
        throw new \yii\web\HttpException(400, 'No Player');
    }
   
    $shops = Shop::find()->all();
    $devices = Device::find()->where(['status'=> 'ready'])->all();
  
    return [
      'success'=>true, 
      'message'=>'success',
     'target'=> $target->player, 
     'manager'=>$user->manager
    ];
  }
  public function actionStartGame($targetId, $deviceId){ //玩家和设备，开始游戏。

    //拿到玩家信息
    $target = Player::findOne($targetId);
    if($target == null){
      throw new \yii\web\HttpException(400, 'No Player');
    }
    //检查设备状态
    $device = Device::findOne($deviceId);
    if($device == null){
      throw new \yii\web\HttpException(400, 'No Device');
    }
    if($device->status != 'ready'){
      throw new \yii\web\HttpException(400, 'Device is not ready');
    }

    //扣掉玩家的钱，
    $shop = $device->shop;
    $target->cost = $target->cost + $shop->price;
    if($target->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($player->errors));
    }
    $shop->income = $shop->income + $shop->price;
    if($shop->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($shop->errors));
    }
    //设备设置为等待运行。
    $record = new Record();
    $record->player_id = $target->id;
    $record->device_id = $device->id;
    //$record->status = 'runnable';
    if($record->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($record->errors));
    }

    $device->status = 'runnable';
    if($device->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($device->errors));
    }


    $record->save();
    $device->save();
    $target->save();
    $shop->save();
    return ['success'=>true, 'message'=>'success', 'record'=>$record];
  }

  
}