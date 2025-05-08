<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Checkin;
use EasyWeChat\MiniApp;
use app\modules\v1\models\Player;
use app\modules\v1\models\User;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;

class CheckinController extends Controller
{

    public function behaviors()
    {

        $behaviors = parent::behaviors();

        return $behaviors;
    }



    public function actionStatus()
    {
        $token = Yii::$app->request->get("token");
      
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token and openid is required');
        }

        // if($token){
        $checkin = Checkin::find()->where(['token' => $token])->one();
     

        if ($checkin) {
            return [
                'scuess' => true,
                'message' => 'already checkin',
                'data' => [
                    'status' => $checkin->status,
                    'token' => $checkin->token,
                    'openid' => $checkin->openid,
                ]
            ];
        } else {
            return [
                'scuess' => true,
                'message' => 'not checkin',
                'data' => [
                    'status' => 'waiting',
                    'token' => $token,
                ]
            ];
        }

    }
    public function actionClose()
    {
        $openid = Yii::$app->request->get("openid");
        if (!$openid) {
            throw new \yii\web\HttpException(400, 'openid is required');
        }
        $checkin = Checkin::find()->where(['openid' => $openid])->one();
        if ($checkin) {
            $checkin->delete();
            return [
                'scuess' => true,
                'message' => 'success'
            ];
        } else {
            return [
                'scuess' => true,
                'message' => 'not checkin',
            ];
        }
    }
    public function actionReady()
    {
        $token = Yii::$app->request->post("token");
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }
        $openid = Yii::$app->request->post("openid");
        if (!$openid) {
            throw new \yii\web\HttpException(400, 'openid is required');
        }

        // Checkin::find()->where(['token' => $token])->andWhere(['openid' => $openid])->one();
        $checkin = Checkin::find()->where(['token' => $token])->one();


        if ($checkin && $checkin->openid == $openid) {
            return [
                'scuess' => true,
                'message' => 'already checkin',
                'data' => $checkin
            ];
        }
        if (!$checkin) {
            $checkin = new Checkin();
        }
        $checkin->token = $token;
        $checkin->openid = $openid;
        $checkin->status = "ready";
        $checkin->file = '';
        if ($checkin->save()) {
            return [
                'scuess' => true,
                'message' => 'success',
                'data' => $checkin
            ];
        } else {
            throw new \yii\web\HttpException(400, 'save failed' + json_encode($checkin->errors));
        }
    }

}