<?php

namespace app\modules\v2\controllers;

use Yii;
use yii\rest\Controller;
use app\modules\v2\models\Applet;
use app\modules\v2\models\Report;
use app\modules\v2\models\RecodeFile;
use app\modules\v2\models\File;
use app\modules\v2\helper\Server;
use app\modules\v2\helper\A1Server;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="服务器",
 *     description="服务器状态管理接口"
 * )
 */
class ServerController extends Controller
{
    public function behaviors()
    {

        $behaviors = parent::behaviors();

        return $behaviors;
    }


    private function doRefresh(): array
    {
        // $server = new Server();
        return Server::Refresh();
    }


    /**
     * @OA\Post(
     *     path="/v2/server/file",
     *     tags={"服务器"},
     *     summary="文件上传/刷新",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="key", type="string", description="文件 Key")
     *         )
     *     ),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionFile()
    {
        $key = Yii::$app->request->post("key");
        if (!isset($key)) {
            throw new \yii\web\HttpException(400, 'key is required');
        }
        return Server::Refresh();
    }
    /**
     * @OA\Post(
     *     path="/v2/server/applet",
     *     tags={"服务器"},
     *     summary="小程序刷新",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="string", description="小程序 ID")
     *         )
     *     ),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionApplet()
    {
        $id = Yii::$app->request->post("id");
        if (!isset($id)) {
            throw new \yii\web\HttpException(400, 'id is required');
        }
        return Server::Refresh();
    }

    /**
     * @OA\Post(
     *     path="/v2/server/device",
     *     tags={"服务器"},
     *     summary="设备刷新",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="uuid", type="string", description="设备 UUID"),
     *             @OA\Property(property="version", type="integer", format="int32", minimum=0, maximum=2147483647, default=0, description="设备版本号；省略时更新为 0")
     *         )
     *     ),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionDevice()
    {
        $device = Yii::$app->request->post("uuid");
        if (!isset($device)) {
            throw new \yii\web\HttpException(400, 'uuid is required');
        }
        return Server::Refresh();
    }
    /**
     * @OA\Get(
     *     path="/v2/server/refresh",
     *     tags={"服务器"},
     *     summary="获取刷新状态",
     *     @OA\Response(response=200, description="成功")
     * )
     * @OA\Post(
     *     path="/v2/server/refresh",
     *     tags={"服务器"},
     *     summary="触发刷新",
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="version", type="integer", format="int32", minimum=0, maximum=2147483647, default=0, description="随设备 UUID 上报的版本号；省略时更新为 0")
     *         )
     *     ),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionRefresh()
    {

        return Server::Refresh();
    }
    /**
     * @OA\Get(
     *     path="/v2/server/info",
     *     tags={"服务器"},
     *     summary="获取服务器信息",
     *     @OA\Parameter(
     *         name="uuid",
     *         in="query",
     *         required=true,
     *         description="设备 UUID",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="version",
     *         in="query",
     *         required=false,
     *         description="设备版本号；省略时按 0 处理，与数据库版本不同时更新",
     *         @OA\Schema(type="integer", format="int32", minimum=0, maximum=2147483647, default=0)
     *     ),
     *     @OA\Response(response=400, description="设备版本号无效"),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionInfo($uuid, $version = 0)
    {
        $version = (is_int($version) || is_string($version))
            ? filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]])
            : false;
        if ($version === false) {
            throw new \yii\web\BadRequestHttpException('version must be an integer between 0 and 2147483647');
        }

        $info = Server::getInfo($uuid, $version);
        return [
            'success' => true,
            'message' => 'success',
            'data' => $info,
        ];
    }

    /**
     * @OA\Get(
     *     path="/v2/server/log",
     *     tags={"服务器"},
     *     summary="获取日志",
     *     @OA\Parameter(
     *         name="token",
     *         in="query",
     *         required=true,
     *         description="Token",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionLog($token)
    {

        $report = Report::find()->where(['token' => $token])->one(); //得到报告（ar端上传）
        $checkin = Applet::find()->where(['token' => $token])->one(); //得到签到（小程序端上传）
        $file = RecodeFile::find()->where(['token' => $token])->one(); //得到文件记录
        return [
            'success' => true,
            'message' => 'success',
            'data' => [
                'checkin' => $checkin,
                'report' => $report,
                'file' => $file,
            ]
        ];
    }
    public function actionTest()
    {

        return "2";
    }

    /**
     * @OA\Get(
     *     path="/v2/server/scenes",
     *     tags={"服务器"},
     *     summary="获取场景列表（转发外部接口）",
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionScenes()
    {
        return A1Server::forwardCheckinRequest();
    }
}
