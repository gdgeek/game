<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use EasyWeChat\MiniApp;
use app\modules\v1\models\Player;
use app\modules\v1\models\User;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

class WeChatController extends Controller
{

  public function behaviors()
  {

    $behaviors = parent::behaviors();

    return $behaviors;
  }
  

  public function actionOpenid()
  {
    $code = Yii::$app->request->post("code");
    if (!$code) {
      throw new \yii\web\HttpException(400, 'code is required');
    }
    $wechat = Yii::$app->wechat;
    $app = $wechat->miniApp();
    $utils = $app->getUtils();

    //如何拿到 unionid
    //https://developers.weixin.qq.com/miniprogram/dev/OpenApiDoc/open-api-3rd/unionid.html
    $response = $utils->codeToSession($code);


    return ['openid' => $response['openid'], 'scuess' => true, 'message' => 'success'];

  }
  /*
   public function actionCreditMoney()//存钱
   {
     $money = Yii::$app->request->post("money");
     if(!$money){
       throw new \yii\web\HttpException(400, 'money is required');
     }
     if(is_numeric($money) == false){
       throw new \yii\web\HttpException(400, 'money must be a number');
     }
     if($money <= 0){
       throw new \yii\web\HttpException(400, 'money must be greater than 0');
     }
     //money 必须整数
     if($money != intval($money)){
       throw new \yii\web\HttpException(400, 'money must be an integer');
     }

     $user = Yii::$app->user->identity;
     $user->recharge = $user->recharge + $money;
     if($user->validate() == false){
       throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($player->errors));
     }
     $user = Yii::$app->user->identity;
     $user->save();
    
     return [ 'success'=>true, "player" =>  $user->player, "message"=>"success"];
   }

  
   public function actionSpendMoney()//花钱
   {
   }


   public function actionGainPoint()//赚积分
   {

   }
   public function actionUsePoint()//花积分
   {

   }
 */

}