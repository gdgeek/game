<?php

namespace app\modules\v2\controllers;

use Yii;
use yii\rest\Controller;
use app\modules\v2\models\User;
use yii\web\Response;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="认证",
 *     description="用户认证相关接口"
 * )
 */
class SiteController extends Controller
{
  public function behaviors()
  {

    $behaviors = parent::behaviors();
    return $behaviors;
  }


  /**
   * @OA\Post(
   *     path="/v2/site/refresh-token",
   *     tags={"认证"},
   *     summary="刷新访问令牌",
   *     description="使用 refreshToken 获取新的访问令牌",
   *     @OA\RequestBody(
   *         required=true,
   *         @OA\JsonContent(
   *             @OA\Property(property="refreshToken", type="string", description="刷新令牌")
   *         )
   *     ),
   *     @OA\Response(
   *         response=200,
   *         description="刷新成功",
   *         @OA\JsonContent(
   *             @OA\Property(property="success", type="boolean"),
   *             @OA\Property(property="message", type="string"),
   *             @OA\Property(property="data", type="object",
   *                 @OA\Property(property="token", type="object",
   *                     @OA\Property(property="accessToken", type="string"),
   *                     @OA\Property(property="expires", type="string"),
   *                     @OA\Property(property="refreshToken", type="string")
   *                 )
   *             )
   *         )
   *     )
   * )
   */
  public function actionRefreshToken()
  {

    $refreshToken = Yii::$app->request->post("refreshToken");

    $user = User::findIdentityByAccessToken($refreshToken);
    if (!$user) {
      throw new \yii\web\UnauthorizedHttpException('Invalid or expired refreshToken');
    }
    if (!$user instanceof User) {
      throw new \RuntimeException('Identity must be User');
    }
    //$token->save();
    return [
      'success' => true,
      'message' => "refresh success",
      'data' => [
        'token' => $user->token()
      ]
    ];
  }
  public function actionPrint()
  {

    $helper = Yii::$app->helper;
    return $helper->play();
  }

  /**
   * @OA\Post(
   *     path="/v2/site/login",
   *     tags={"认证"},
   *     summary="微信小程序登录",
   *     description="使用微信 code 进行登录，获取访问令牌",
   *     @OA\RequestBody(
   *         required=true,
   *         @OA\JsonContent(
   *             required={"code"},
   *             @OA\Property(property="code", type="string", description="微信 wx.login 返回的 code")
   *         )
   *     ),
   *     @OA\Response(
   *         response=200,
   *         description="登录成功",
   *         @OA\JsonContent(
   *             @OA\Property(property="success", type="boolean"),
   *             @OA\Property(property="message", type="string"),
   *             @OA\Property(property="data", type="object",
   *                 @OA\Property(property="token", type="object"),
   *                 @OA\Property(property="user", ref="#/components/schemas/User"),
   *                 @OA\Property(property="openid", type="string"),
   *                 @OA\Property(property="unionid", type="string")
   *             )
   *         )
   *     ),
   *     @OA\Response(response=400, description="code is required")
   * )
   */
  public function actionLogin()
  {
    $code = Yii::$app->request->post("code");
    if (!is_string($code) || $code === '' || strlen($code) > 1024) {
      throw new \yii\web\HttpException(400, 'code is required');
    }
    $wechat = Yii::$app->wechat;
    $app = $wechat->miniApp();
    $utils = $app->getUtils();

    //如何拿到 unionid
    //https://developers.weixin.qq.com/miniprogram/dev/OpenApiDoc/open-api-3rd/unionid.html
    $response = $utils->codeToSession($code);
    $openid = $response['openid'] ?? null;
    if (!is_string($openid) || $openid === '') {
      throw new \yii\web\UnauthorizedHttpException('微信登录失败，请重新登录');
    }
    $user = null;
    $unionid = $response['unionid'] ?? null;

    if (is_string($unionid) && $unionid !== '') {
      $user = User::find()->where(['unionid' => $unionid])->one();
      if ($user == null) {
        $user = new User();
        $user->unionid = $unionid;
        $user->openid = $openid;
        if (!$user->save()) {
          throw new \yii\web\UnauthorizedHttpException('微信账号登录失败');
        }
      }
    } else {
      // Only WeChat's verified code exchange supplies this identity. A client
      // body openid/unionid never participates in lookup or account creation.
      $user = User::find()->where(['openid' => $openid])->one();
    }
    if (!$user instanceof User) {
      throw new \yii\web\UnauthorizedHttpException('微信账号尚未关联，请先完成账号授权');
    }

    return [
      'data' => [
        'token' => $user->token(),
        'user' => $user,
        'openid' => $user->openid,
        'unionid' => $user->unionid,
      ],
      //  'openid' => $response['openid'],
      //'unionid' => $unionid, // 返回 unionid（可能为 null）
      'success' => true,
      'message' => $unionid ? 'success' : 'unionid not available (check if user has authorized or follows related official account)'
    ];
  }
}
