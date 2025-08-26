<?php
namespace app\modules\v2\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v2\models\User;
use yii\web\Response;

use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
class WechatController extends Controller
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
  public function actionPhone(){

  }
  public function actionProfile(){
    
  }


}