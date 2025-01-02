<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Player;
use bizley\jwt\JwtHttpBearerAuth;
use app\modules\v1\models\User;
use yii\filters\auth\CompositeAuth;
use app\modules\v1\helper\PlayerFingerprintAuth;

class CommonController extends Controller
{

    public function behaviors()
    {
      
        $behaviors = parent::behaviors();
        
    
       
         //如果 action 不是 test
        if(Yii::$app->controller->action->id != 'test'){
          $behaviors['authenticator'] = [
              'class' => PlayerFingerprintAuth::className(),
          ];
        }
        return $behaviors;
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
    if($user != null){
      return ['result'=>true, 'player'=> $user->player, 'message'=>"already signup"];
    }
    $user = new User();
    $user->tel = $tel;
    $user->openId = $openId;
    if($user->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($user->errors));
    }
    $user->save();
    return ['result'=>true, 'player'=> $user->player, 'message'=>"success"];
  }

  public function actionSignIn()
  {
    $helper = Yii::$app->helper;
    $helper->record();
    $openId = Yii::$app->request->post("openId");
    $user = User::find()->where(['openId'=>$openId])->one();
    if($user == null){
      return [ 'result'=>false,'player'=> null, 'message'=>"no signup"];
    }
    return [ 'result'=>true,'player'=> $user->player, 'message'=>"success"];
  }
}