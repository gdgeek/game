<?php

namespace app\modules\v2\controllers;

use Yii;
use yii\rest\Controller;
use yii\web\BadRequestHttpException;
use yii\helpers\ArrayHelper;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="微信",
 *     description="微信相关接口"
 * )
 */
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
     * @OA\Post(
     *     path="/v2/wechat/profile",
     *     tags={"微信"},
     *     summary="更新用户资料",
     *     description="更新当前登录用户的头像和昵称",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="avatar", type="string", description="头像 URL"),
     *             @OA\Property(property="nickname", type="string", description="昵称")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="更新成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", ref="#/components/schemas/User")
     *             )
     *         )
     *     )
     * )
     */
    public function actionProfile()
    {
        $request = Yii::$app->request;
        $avatar = $request->post('avatar');
        $nickname = $request->post('nickname');

        // 获取当前用户并检查是否存在
        $user = Yii::$app->user->identity;
        if (!$user) {
            throw new \yii\web\HttpException(401, 'User not authenticated');
        }

        // 确保用户对象是 ActiveRecord 实例
        if (!($user instanceof \yii\db\ActiveRecord)) {
            throw new \yii\web\HttpException(500, 'Invalid user object');
        }

        $dirty = false;
        if ($avatar && $user->avatar != $avatar) {
            $user->avatar = $avatar;
            $dirty = true;
        }
        if ($nickname && $user->nickname != $nickname) {
            $user->nickname = $nickname;
            $dirty = true;
        }

        if ($dirty) {
            if (!$user->save()) {
                throw new \yii\web\HttpException(500, 'Failed to save user profile');
            }
        }

        return [
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'user' => $user,
            ],
        ];
    }

    /**
     * @OA\Post(
     *     path="/v2/wechat/bind-phone",
     *     tags={"微信"},
     *     summary="绑定手机号",
     *     description="使用微信 getPhoneNumber 返回的 code 绑定手机号",
     *     security={{"Bearer":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"code"},
     *             @OA\Property(property="code", type="string", description="小程序 getPhoneNumber 返回的 code")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="绑定成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean"),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", ref="#/components/schemas/User"),
     *                 @OA\Property(property="phone", type="string"),
     *                 @OA\Property(property="purePhone", type="string"),
     *                 @OA\Property(property="countryCode", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=400, description="绑定失败")
     * )
     *
     * 绑定手机号（推荐：新版 getPhoneNumber 返回的 code）
     * POST /v2/wechat/bind-phone
     * body:
     *  - code: 小程序前端 getPhoneNumber 返回的 code（推荐）
     *  - 或 encryptedData + iv [+ sessionKey]（旧方案，需提供 sessionKey 或服务端自行维护）
     */
    public function actionBindPhone()
    {
        $request = Yii::$app->request;
        $code = $request->post('code');

        //$encryptedData = $request->post('encryptedData');
        //$iv = $request->post('iv');

        // 获取当前用户并检查是否存在
        $user = Yii::$app->user->identity;
        if (!$user) {
            throw new \yii\web\HttpException(401, 'User not authenticated');
        }

        // 确保用户对象是 ActiveRecord 实例
        if (!($user instanceof \yii\db\ActiveRecord)) {
            throw new \yii\web\HttpException(500, 'Invalid user object');
        }

        $wechat = Yii::$app->wechat;
        $app = $wechat->miniApp();

        // 优先走"新版 code"接口：wxa/business/getuserphonenumber
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
                $phoneNumber = ArrayHelper::getValue($phoneInfo, 'phoneNumber');
                if ($user->tel != $phoneNumber) {
                    $user->tel = $phoneNumber;
                    if (!$user->save()) {
                        throw new \yii\web\HttpException(500, 'Failed to save phone number');
                    }
                }
                return [
                    'success' => true,
                    'message' => 'success',
                    'data' => [
                        'user' => $user,
                        'phone' => $phoneNumber,
                        'purePhone' => ArrayHelper::getValue($phoneInfo, 'purePhoneNumber'),
                        'countryCode' => ArrayHelper::getValue($phoneInfo, 'countryCode'),
                    ],
                ];
            } catch (\Throwable $e) {
                throw new BadRequestHttpException('bind phone failed: ' . $e->getMessage());
            }
        }

        throw new BadRequestHttpException('code parameter is required');
    }
}
