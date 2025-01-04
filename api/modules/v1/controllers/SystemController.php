<?php

namespace app\modules\v1\controllers;
use Yii;

use yii\rest\Controller;
use app\modules\v1\models\Device;
use app\modules\v1\helper\PlayerFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\Award;
use app\modules\v1\models\Player;

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
      throw  
    }
    return ['success'=>true, 'player'=>$user->info, 'message'=>'success']
  }

  public function actionDevices(){//得到所有设备
    
  }
  public function actionStartGame($player, $device){ //玩家和设备，开始游戏。

    //拿到玩家信息
    $player = Player::findOne($player);
    if($player == null){
      throw new \yii\web\HttpException(400, 'No Player');
    }
    //检查设备状态
    $device = Device::findOne($device);
    if($device == null){
      throw new \yii\web\HttpException(400, 'No Device');
    }
    if($device->status != 0){
      throw new \yii\web\HttpException(400, 'Device is not ready');
    }

    //扣掉玩家的钱，
    $shop = $device->shop;
    $player->cost = $player->cost + $shop->price;
    if($player->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($player->errors));
    }
    $shop->earn = $shop->earn + $shop->price;
    if($shop->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($shop->errors));
    }
    //设备设置为等待运行。
    $recode = new Recode();
    $recode->player_id = $player->id;
    $recode->device_id = $device->id;
    $recode->status = 0;
    if($recode->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($recode->errors));
    }

    $device->status = 1;
    if($device->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($device->errors));
    }
    $recode->save();
    $device->save();
    $player->save();
    $shop->save();
  }

  
}