<?php
namespace app\modules\v2\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v2\models\User;
use app\modules\v2\helper\RootAuth;
use yii\web\Response;
class SiteController extends Controller
{

  public function behaviors()
  {

    $behaviors = parent::behaviors();
    $behaviors['authenticator'] = [
      'class' => RootAuth::class,
    ];
    return $behaviors;
  }



  public function actionRefreshToken()
  {

    $refreshToken = Yii::$app->request->post("refreshToken");

    $user = User::findIdentityByAccessToken($refreshToken);
    if (!$user) {
      throw new \Exception("Invalid refreshToken");
    }
    if (!$user instanceof User) {
      throw new \RuntimeException('Identity must be User');
    }
    //$token->save();
    return [
      'success' => true,
      'message' => "refresh success",
      'data' => [
        'token' => $user->token(),
      ]
    ];
  }
  public function actionPrint()
  {

    $helper = Yii::$app->helper;
    return $helper->play();
  }

  public function actionLogin()
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


    // 检查是否包含 unionid
    $unionid = $response['unionid'] ?? null;

    if ($unionid) {
      $user = User::find()->where(['unionid' => $unionid])->one();
      if ($user == null) {
        $user = new User();
        $user->unionid = $unionid;
        $user->openid = $response['openid'];
        $user->save();
      }
    }

    return [
      'data' => [
        'token' => $user->token(),
        'openid' => $response['openid'],
        'unionid' => $unionid, // 返回 unionid（可能为 null）
      ],
      'openid' => $response['openid'],
      //'unionid' => $unionid, // 返回 unionid（可能为 null）
      'success' => true,
      'message' => $unionid ? 'success' : 'unionid not available (check if user has authorized or follows related official account)'
    ];

  }


}