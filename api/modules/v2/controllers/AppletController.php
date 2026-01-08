<?php

namespace app\modules\v2\controllers;

use Yii;
use yii\rest\Controller;
use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
use app\modules\v2\helper\Server;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="小程序",
 *     description="小程序相关接口"
 * )
 */
class AppletController extends Controller
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


    /**
     * @OA\Post(
     *     path="/v2/applet/refresh",
     *     tags={"小程序"},
     *     summary="刷新小程序状态",
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionRefresh()
    {

        return Server::Refresh();
    }
}
