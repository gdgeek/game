<?php
namespace app\modules\v1\helper;

use Yii;
use yii\filters\auth\AuthMethod;
use yii\web\UnauthorizedHttpException;
use app\modules\v1\models\Player;

class PlayerFingerprintAuth extends AuthMethod
{
    
    /**
     * @inheritdoc
     */
    public function authenticate($user, $request, $response)
    {
     
      Yii::$app->helper->record("auth");
      
      if(\Yii::$app->request->isGet){
        $data = \Yii::$app->request->get();
      }else{
        $data = \Yii::$app->request->post();
      }

      
     
      if(isset($data['openId']) && isset($data['timestamp']) && isset($data['fingerprint'])){
        
        $openId =  urldecode($data['openId']);
        $timestamp =  urldecode($data['timestamp']);
        $fingerprint = urldecode($data['fingerprint']);
        $inputString = "geek.v0xe1.pa2ty.c0m". $timestamp . $openId;
     
        if($fingerprint == md5($inputString)){
         return true;
        }
      }
     
      return null;
    }


}