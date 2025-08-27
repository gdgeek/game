<?php
namespace app\modules\v2\controllers;

use Yii;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;
use yii\helpers\ArrayHelper;
class WechatController extends Controller
{
    public function behaviors()
    {
        $b = parent::behaviors();
        // 按需加上认证，已在请求头里带 Authorization: Bearer xxx
        return $b;
    }

    public function actionPrint()
    {
        $helper = Yii::$app->helper;
        return $helper->play();

    }
    public function actionCode()
    {
        $code = Yii::$app->request->get("code");
        if (!$code) {
            throw new \yii\web\HttpException(400, 'code is required');
        }
        $wechat = Yii::$app->wechat;
        $app = $wechat->miniApp();
        $utils = $app->getUtils();

        //如何拿到 unionid
        //https://developers.weixin.qq.com/miniprogram/dev/OpenApiDoc/open-api-3rd/unionid.html
        $response = $utils->codeToSession($code);

        return $response;

    }

    /**
     * 绑定手机号（推荐：新版 getPhoneNumber 返回的 code）
     * POST /v2/wechat/bind-phone
     * body:
     *  - code: 小程序前端 getPhoneNumber 返回的 code（推荐）
     *  - 或 encryptedData + iv [+ sessionKey]（旧方案，需提供 sessionKey 或服务端自行维护）
     */
    public function actionRegister()
    {
        $request = Yii::$app->request;
        $code = $request->post('code');
        //$encryptedData = $request->post('encryptedData');
        //$iv = $request->post('iv');

        $user = Yii::$app->user->identity;
        $wechat = Yii::$app->wechat;
        $app = $wechat->miniApp();

        // 优先走“新版 code”接口：wxa/business/getuserphonenumber
        if ($code) {
            try {
                $resp = $app->getClient()
                    ->postJson('wxa/business/getuserphonenumber', ['code' => $code])
                    ->toArray();

                // 返回示例：{ "errcode":0, "errmsg":"ok", "phone_info": { "phoneNumber":"...", "purePhoneNumber":"...", "countryCode":"86", "watermark":{...} } }
                if (isset($resp['errcode']) && (int) $resp['errcode'] !== 0) {
                    throw new BadRequestHttpException('getuserphonenumber failed: ' . ($resp['errmsg'] ?? 'unknown'));
                }

                $phoneInfo = $resp['phone_info'] ?? [];
                return [
                    'success' => true,
                    'message' => 'success',
                    'data' => [
                        'user' => $user,
                        'phone' => ArrayHelper::getValue($phoneInfo, 'phoneNumber'),
                        'purePhone' => ArrayHelper::getValue($phoneInfo, 'purePhoneNumber'),
                        'countryCode' => ArrayHelper::getValue($phoneInfo, 'countryCode'),
                        'raw' => $resp,
                    ],
                ];
            } catch (\Throwable $e) {
                throw new BadRequestHttpException('bind phone failed: ' . $e->getMessage());
            }
        }

    }
}