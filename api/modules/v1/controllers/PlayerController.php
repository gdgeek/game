<?php

namespace app\modules\v1\controllers;

use Yii;
use yii\rest\ActiveController;
use app\modules\v1\models\Player;
use bizley\jwt\JwtHttpBearerAuth;
use app\modules\v1\models\User;
use yii\filters\auth\CompositeAuth;

class PlayerController extends ActiveController
{
    public $modelClass = 'app\modules\v1\models\Player';
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
/*
  public function actionTest(){


    $user = User::findOne(3);
    return  ["t" =>  getenv('MYSQL_HOST')];
  }

  public function actionSignUp()
  {
    $helper = Yii::$app->helper;
    $helper->record();
    $json = Yii::$app->request->post("parameters");

    if(json_validate($json) == false){
      throw new \yii\web\HttpException(400, 'Invalid JSON');
    }
    $params = json_decode($json, false);
    if(!isset($params->tel) || !isset($params->openId) || !isset($params->fingerprint) || !isset($params->timestamp)){
      throw new \yii\web\HttpException(400, 'Missing parameters');
    }

    $inputString = "geek.v0xe1.pa2ty.c0m". $params->timestamp . $params->openId;

    if($params->fingerprint != md5($inputString)){
      throw new \yii\web\HttpException(400, 'Invalid fingerprint');
    }

    $player = Player::find()->where(['openId'=>$params->openId])->one();
    if($player != null){

      return ['time'=>time(), 'player'=> $player, 'result'=>"already signup"];
    }
    $player = new Player();
    $player->tel = $params->tel;
    $player->openId = $params->openId;
    if($player->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($player->errors));
    }
    $player->save();
    return ['time'=>time(), 'player'=> Player::findOne($player->id), 'result'=>"success"];
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

    if(!isset($params->openId) || !isset($params->fingerprint) || !isset($params->timestamp)){
      throw new \yii\web\HttpException(400, 'Missing parameters');
    }

    $inputString = "geek.v0xe1.pa2ty.c0m". $params->timestamp . $params->openId;

    if($params->fingerprint != md5($inputString)){
      throw new \yii\web\HttpException(400, 'Invalid fingerprint');
    }
    $player = Player::find()->where(['openId'=>$params->openId])->one();

    if($player == null){
      return ['time'=>time(), 'player'=> null, 'result'=>"no signup"];
    }

    return ['time'=>time(), 'player'=> $player, 'success'=>true, 'result'=>"success", 'player'=> $player];
  }
  */
}
