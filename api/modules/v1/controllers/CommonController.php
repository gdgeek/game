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
        if (
            Yii::$app->controller->action->id != 'test'
            && Yii::$app->controller->action->id != 'refresh-token'
        ) {
            $behaviors['authenticator'] = [
              'class' => PlayerFingerprintAuth::className(),
            ];
        }
        return $behaviors;
    }
    public function actionRefreshToken()
    {
        $helper = Yii::$app->helper;
        $helper->record();
        $refreshToken = Yii::$app->request->post("refreshToken");

        $user = User::findIdentityByAccessToken($refreshToken);
        if (!$user) {
            throw new \Exception("Invalid refreshToken");
        }
      //$token->save();
        return [
        'success' => true,
        'message' => "refresh success",
        'token' => $user->token(),
        ];
    }
    public function actionTest()
    {
      //打印日志

        return "1123";
    }




    public function actionSignUp()
    {
        $helper = Yii::$app->helper;
        $helper->record();
        $openId = Yii::$app->request->post("openId");
        $tel = Yii::$app->request->post("tel");

        if (!$tel) {
            throw new \yii\web\HttpException(400, 'No Tel');
        }
        $user = User::find()->where(['openId' => $openId])->one();
        $token = null;
        $message = null;
        if ($user == null) {
            $user = new User();
            $user->tel = $tel;
            $user->openId = $openId;
            if ($user->validate() == false) {
                throw new \yii\web\HttpException(400, 'Invalid parameters' . json_encode($user->errors));
            }
            $user->save();
            $user = User::findOne($user->id);
            $message = "success";
        } else {
            $message = "already signup";
        }


        return  [
        'success' => true,
        'message' => $message,
        'player' => $user->player,
        'token' =>  $user->token()
        ];
    }

    public function actionSignIn()
    {
        $helper = Yii::$app->helper;
        $helper->record();

        $openId = Yii::$app->request->post("openId");
        $user = User::find()->where(['openId' => $openId])->one();
        if ($user == null) {
            return [ 'success' => false,'player' => null, 'message' => "no signup"];
        }

        return [
        'success' => true,
        'message' => "success" ,
        'player' => $user->player,
        'token' => $user->token()
        ];
    }
}
