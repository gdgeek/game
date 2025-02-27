<?php

namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Device;
use app\modules\v1\helper\PlayerFingerprintAuth;
use app\modules\v1\models\Game;
use app\modules\v1\models\AwardType;
use app\modules\v1\models\Player;
use app\modules\v1\models\Shop;
use app\modules\v1\models\Record;
use app\modules\v1\models\Operation;

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




  public function actionPlayerInfo($targetId)
  {

    $user = Yii::$app->user->identity;

    if (!$user->manager) {
      throw new \yii\web\HttpException(400, 'Not Manager');
    }
    $target = User::findOne($targetId);
    if ($target == null) {
      throw new \yii\web\HttpException(400, 'No Player');
    }

    return [
      'success' => true,
      'message' => 'success',
      'target' => $target->player,
    ];
  }

  public function actionResetShop($shopId)
  {
    $operation = Operation::find()->where(['shop_id' => $shopId])->one();
    if ($operation == null) {
      throw new \yii\web\HttpException(400, 'No Operation');
    }
    $operation->pool = 0;
    $operation->turnover = 0;
    //$operation->income = 0;
    if (!$operation->save()) {
      throw new \yii\web\HttpException(400, 'Save Error');
    }
    return ['success' => true, 'message' => 'success', 'operation' => $operation];

  }
  public function actionGive(){

    $user = Yii::$app->user->identity;
    if (!$user->manager) {
      throw new \yii\web\HttpException(400, 'Not Manager');
    }

    $target_id =   $user_id = Yii::$app->request->post('targetId');
    $player = User::findOne($target_id);
    if ($player == null) {
      throw new \yii\web\HttpException(400,'No Player');
    }
    $money = Yii::$app->request->post('money');
    if(!$money){
      throw new \yii\web\HttpException(400,'No Money');
    }
    $user->give -= $money;
    $player->give += $money;
    if (!$user->validate()) {
      throw new \yii\web\HttpException(400,'Save Error' + json_decode($user->getErrors(), true) );
   
    } 
    if(!$player->validate()) {
      throw new \yii\web\HttpException(400,'Save Error' + json_decode($player->getErrors(), true) );
    }
    $user->save();
    $player->save();
    return ['success' => true, 'message' => 'success', 'user' => $user, 'player' => $player];


  }
  public function  actionCloseRecord(){
    $user = Yii::$app->user->identity;
    if (!$user->manager) {
      throw new \yii\web\HttpException(400, 'Not Manager');
    }

    $record_id = Yii::$app->request->post('recordId');
    $record = Record::findOne($record_id);
    if ($record == null) {
      throw new \yii\web\HttpException(400,'No Record');
    }
    $shop = $record->shop;
    $player = $record->player;
    $player->cost -= $shop->price;//扣掉玩家的花费，

    $operation = $shop->operation;
    $operation->pool += $record->game['points'] ;//池子加上本局的点数
    $operation->pool -= $shop->price;//池子减去本局的价格
    $operation->turnover -=  $shop->price;//营业额减去玩家的花费
    //$operation->income += $record->game['points'] ;//池子加上本局的点数
    //$operation->income -=  $shop->price;//收入减去玩家的花费
    if (!$player->validate()) {
      throw new \yii\web\HttpException(400,'Save Error' + json_decode($player->getErrors(), true) );
   
    }
    if (!$operation->validate()) {
      throw new \yii\web\HttpException(400,'Save Error' + json_decode($operation->getErrors(), true) );
   
    }
    $player->save();
    $operation->save();
    
   
    $record->delete();
    return ['success' => true, 'message' => 'success', 'player' => $player, 'operation' => $operation];
  }
  public function actionDeductPoints(){

    $user = Yii::$app->user->identity;
    if (!$user->manager) {
      throw new \yii\web\HttpException(400, 'Not Manager');
    }
    $points = Yii::$app->request->get('points');
    if(!$points){
      throw new \yii\web\HttpException(400,'No Points');
    }

    $player_id = Yii::$app->request->get('targetId');
    $player = Player::findOne($player_id);
    if (!$player) {
      throw new \yii\web\HttpException(400,'No Player');
    }
    if($points > $player->points){
      throw new \yii\web\HttpException(400,'Not Enough Points');
    }
    $player->points -= $points;
    if(!$player->validate()){
      throw new \yii\web\HttpException(400,'Save Error' + json_decode($player->getErrors(), true) );
    }
    $player->save();
    return ['success'=> true,'message'=> 'success','player'=> $player];
  }
  public function actionReadyGame($targetId, $deviceId)
  { //玩家和设备，开始游戏。


    $user = Yii::$app->user->identity;
    if (!$user->manager) {
      throw new \yii\web\HttpException(400, 'Not Manager');
    }
    //拿到玩家信息
    $player = Player::findOne($targetId);
    if ($player == null) {
      throw new \yii\web\HttpException(400, 'No Player');
    }

    //检查设备状态
    $device = Device::findOne($deviceId);
    if ($device == null) {
      throw new \yii\web\HttpException(400, 'No Device');
    }

    $record = Record::find()->where(['player_id' => $player->id, 'device_id' => $device->id])->with('user', 'device')->one();

    if ($record != null) {
      throw new \yii\web\HttpException(400, 'Already Running');
    }

    //扣掉玩家的钱，
    $shop = $device->shop;

  
    $player->cost = $player->cost + $shop->price;

    if ($player->cost > $player->recharge + $player->give) {
      throw new \yii\web\HttpException(400, 'Not Enough Money');
    }
    if ($player->validate() == false) {
      throw new \yii\web\HttpException(400, 'Invalid parameters' . json_encode($player->errors));
    }
   
    
    $operation = $shop->operation;

    $turnover = $operation->turnover + $shop->price;// 收入等于上次收入加上这次的价格
    $pool = $operation->pool + $shop->price;// 池子等于上次池子加上这次的价格

    $left = $turnover * (1 - ($shop->rate / 100));//剩下的钱等于收入减去收入的百分比
    $restore = $pool - $left; //恢复的钱等于池子减去剩下的钱
    $restore = random_int(floor($restore/2), $restore);
    
    $operation->pool = $pool - $restore;
    $operation->turnover = $turnover;
    //$operation->income += $operation->income + ($shop->price - $restore);
    
    $operation->save();
   
    if ($operation->validate() == false) {
      throw new \yii\web\HttpException(400, 'Invalid parameters' . json_encode($shop->errors));
    }
    $operation->save();

    //设备设置为等待运行。
    $record = new Record();
    $record->player_id = $player->id;
    $record->device_id = $device->id;
    //$game = new Game();

    $record->game = new Game($restore, $shop->play_time);

    if ($record->validate() == false) {
      throw new \yii\web\HttpException(400, 'Invalid parameters' . json_encode($record->errors));
    }

    // $device->status = 'runnable';
    if ($device->validate() == false) {
      throw new \yii\web\HttpException(400, 'Invalid parameters' . json_encode($device->errors));
    }


    $record->save();
    $device->save();
    $player->save();
    $shop->save();
    $record = Record::findOne($record->id);
    //返回记录包括player 和 device 信息，以及points
    /*$recordArray = $record->toArray(
      ['id', 'points', 'startTime', 'endTime'], // 要包含的字段
      ['player' => ['id', 'name'], 'device' => ['id', 'name']] // 要包含的关联数据及其字段
  );*/
    return ['success' => true, 'message' => 'success', 'record' => $record->toArray([], ['player', 'device'])];
  }


}
