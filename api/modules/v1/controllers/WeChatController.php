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


  public function actionInfo()
  {

    $wechat = Yii::$app->wechat;
    $app = $wechat->payApp();

    return $app;
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


    // 检查是否包含 unionid
    $unionid = $response['unionid'] ?? null;


    return [
      'openid' => $response['openid'],
      'unionid' => $unionid, // 返回 unionid（可能为 null）
      'success' => true,
      'message' => $unionid ? 'success' : 'unionid not available (check if user has authorized or follows related official account)'
    ];

  }

}