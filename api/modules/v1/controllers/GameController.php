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
      'class' => DeviceFingerprintAuth::class,
    ];

    return $behaviors;
  }
  public function actionDevice()
  {

    $uuid = urldecode(Yii::$app->request->get('uuid'));
    $device = Device::find()->where(['uuid' => $uuid])->one();
    if ($device == null) {
      $device = $this->getDevice();
      return [
        'message' => "new device",
        'success' => true,
        'device' => Device::findOne($device->id)
      ];
    }
    return [
      'message' => "old device",
      'success' => true,
      'device' => $device
    ];

  }
  private function getDevice()
  {
    $uuid = urldecode(Yii::$app->request->get('uuid'));
    $device = Device::find()->where(['uuid' => $uuid])->one();
    if ($device == null) {
      $device = new Device();
      $device->uuid = $uuid;
      $device->ip = Yii::$app->request->userIP;

    } else {
      $device->ip = Yii::$app->request->userIP;
    }

    if ($device->validate() == false) {
      throw new \yii\web\HttpException(400, 'Invalid device:' . json_encode($device->errors));
    }
    $device->save();
    return $device;
  }
  public function actionRestart()
  {
    $device = $this->getDevice();
    if ($device->record == null) {
      throw new \yii\web\HttpException(400, 'No record');
    }
    $record = $device->record;
    $record->status = 'ready';
    if (!$record->validate()) {
      throw new \yii\web\HttpException(400, 'Invalid record' . json_encode($record->errors));
    }
    $record->save();
    return ['message' => 'Game restart', 'success' => true];
  }
  public function actionReady()
  {

    $device = $this->getDevice();
    $record = $device->record;
    if ($record == null) {
      return ["message" => "No Ready", 'success' => false];
    }

    return ['game' => $record->game, 'record' => $record, "message" => "Ready to play", 'success' => true];
  }
  public function actionStart()
  {

    $device = $this->getDevice();

    if ($device->record == null) {
      throw new \yii\web\HttpException(400, 'No record');
    }
    $record = $device->record;
    if ($record->status == "running") {
      throw new \yii\web\HttpException(400, 'Game is running');
    }
    $record->status = "running";

    if ($record->validate() == false) {
      throw new \yii\web\HttpException(400, 'Invalid record' . json_encode($record->errors));
    }
    $record->save();

    return [

      'message' => 'Game start',
      'success' => true,
      'game' => $record->game
    ];
  }
  public function addGain($shop, $player, $type)
  {
    $gain = new Gain();
    $gain->shop_id = $shop->id;
    $gain->player_id = $player->id;
    $gain->type = $type;

    if ($gain->validate() == false) {
      throw new \yii\web\HttpException(400, 'Invalid gain' . json_encode($gain->errors));
    }
    $gain->save();
  }
  public function actionFinish()
  {

    $device = $this->getDevice();
    $record = $device->record;
    if ($record == null) {
      throw new \yii\web\HttpException(400, 'No record');
    }
    if ($record->status != "running") {
      throw new \yii\web\HttpException(400, 'Game is not running');
    }

    $shop = $device->shop;
    $points = Yii::$app->request->post("points");

    if (!$points) {
      throw new \yii\web\HttpException(400, 'points is required');
    }
    $user = $record->user;
    //$points = $award['points'];
    $user->points = $user->points + $points;
    $user->times += 1;
    $operation = $shop->operation;

    $back = $record->game['points'] - $points;
    $operation->pool = $operation->pool + $back;
    //$operation->income = $operation->income + $back;

    $user->save();
    $operation->save();
    $record->delete();
    return [

      'message' => 'Game over',
      'player' =>  $user->player,
      'success' => true
    ];

  }

}
