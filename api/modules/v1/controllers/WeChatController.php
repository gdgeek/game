<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Player;
use bizley\jwt\JwtHttpBearerAuth;
use app\modules\v1\models\User;
use yii\filters\auth\CompositeAuth;
use app\modules\v1\helper\PlayerFingerprintAuth;

class WeChatController extends Controller
{

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
    
   

  public function actionCreditMoney()//存钱
  {
    $mondy = Yii::$app->request->post("mondy");
    $user = Yii::$app->user->identity;
    $user->recharge = $user->recharge + $mondy;
    if($user->validate() == false){
      throw new \yii\web\HttpException(400, 'Invalid parameters'.json_encode($player->errors));
    }
    $user->save();
    return ["result"=>true, "message"=>"success"];
  }

  /*
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