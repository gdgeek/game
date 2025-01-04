<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Player;
use bizley\jwt\JwtHttpBearerAuth;
use app\modules\v1\models\User;
use yii\filters\auth\CompositeAuth;
use app\modules\v1\helper\PlayerFingerprintAuth;

//PlayerToken
use  app\modules\v1\models\PlayerToken;

class CommonController extends Controller
{

    public function behaviors()
    {
      
        $behaviors = parent::behaviors();
        
         //如果 action 不是 test
        if(Yii::$app->controller->action->id != 'test'
        && Yii::$app->controller->action->id != 'refresh-token'
        ){
          $behaviors['authenticator'] = [
              'class' => PlayerFingerprintAuth::className(),
          ];
        }
        return $behaviors;
    }
    public function actionRefreshToken($refreshToken){
      $helper = Yii::$app->helper;
      $helper->record();
    
      $token = PlayerToken::findByRefreshToken($refreshToken);
      if($token == null){
        throw new \yii\web\HttpException(400, 'Invalid refreshToken');
      }
      if($token->isExpired){
        throw new \yii\web\HttpException(400, 'Expired refreshToken');
      }
     $token->refresh();
      return [
        'success'=>true,
        'player' => $token->user->player,
        'refreshToken' => $token->refresh_token,
        'message'=>"refresh success"];
    }
    public function actionLogin(){
       // $user = User::findOne(3);
       // return $user->generateAccessToken();
    }
    public function actionTest(){
      //打印日志

      $helper = Yii::$app->helper;
      return $helper->play("auth");
    
    }



  public function actionSignUp()
  {
    $helper = Yii::$app->helper;
    $helper->record();
    $openId = Yii::$app->request->post("openId");
    $tel = Yii::$app->request->post("tel");
    
    if(!$tel){
      throw new \yii\web\HttpException(400, 'No Tel');
    }
    $user = User::find()->where(['openId'=>$openId])->one();
    $now = new \DateTimeImmutable('now', new \DateTimeZone(\Yii::$app->timeZone));
     
    if($user != null){

    
      $token = PlayerToken::GenerateRefreshToken($user->id);
      return [
        'success'=>true, 
        'message'=>"already signup",
        'player'=> $user->player,
        'token' => [
          'key' => $user->generateAccessToken($now),
          'expires' => $now->modify('+3 hour')->format('Y-m-d H:i:s')
          'refreshToken' => $token->refresh_token
        ]
      ];
    }
    $user = new User();
    $user->tel = $tel;
    $user->openId = $openId;
    if($user->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($user->errors));
    }
    $user->save();
    $user = User::findOne($user->id);

    $token = PlayerToken::GenerateRefreshToken($user->id);
    return [
      'success'=>true,
      'message'=>"success",
      'player'=> $user->player, 
      'token' => [
        'key' => $user->generateAccessToken($now),
        'expires' => $now->modify('+3 hour')->format('Y-m-d H:i:s')
        'refreshToken' => $token->refresh_token
      ] 
    ];
  }

  public function actionSignIn()
  {
    $helper = Yii::$app->helper;
    $helper->record();
    
    $openId = Yii::$app->request->post("openId");
    $user = User::find()->where(['openId'=>$openId])->one();
    if($user == null){
      return [ 'success'=>false,'player'=> null, 'message'=>"no signup"];
    }
    $now = new \DateTimeImmutable('now', new \DateTimeZone(\Yii::$app->timeZone));
    
    $token = PlayerToken::GenerateRefreshToken($user->id);
    return [ 
      'success'=>true,
      'message'=>"success",
      'player'=> $user->player, 
      'token' => [
        'key' => $user->generateAccessToken($now),
        'expires' => $now->modify('+3 hour')->format('Y-m-d H:i:s')
        'refreshToken' => $token->refresh_token
      ]
    ];
  }
}