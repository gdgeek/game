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
        
        $salt = "geek.v0xe1.pa2ty.c0m";
        $openId = $data['openId'];
        $timestamp =  $data['timestamp'];
        $fingerprint = $data['fingerprint'];

        if(isset($data['key'])){
          $key = $data['key'];
          $inputString =  $salt. $timestamp . $openId. $key;
        }else{
          $inputString =  $salt. $timestamp . $openId;
        }
     
        if($fingerprint == md5($inputString)){
         return true;
        }
      }
     
      return null;
    }


}