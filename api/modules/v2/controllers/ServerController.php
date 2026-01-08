<?php

namespace app\modules\v2\controllers;

use Yii;
use yii\rest\Controller;
use app\modules\v2\models\Applet;
use app\modules\v2\models\Report;
use app\modules\v2\models\RecodeFile;
use app\modules\v2\models\File;
use app\modules\v2\helper\Server;
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
     *             @OA\Property(property="uuid", type="string", description="设备 UUID")
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
     *     @OA\Response(response=200, description="成功")
     * )
     */
    public function actionInfo($uuid)
    {
        $info = Server::getInfo($uuid);
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

        $report = Report::find()->where(['token' => $token])->one();//得到报告（ar端上传）
        $checkin = Applet::find()->where(['token' => $token])->one();//得到签到（小程序端上传）
        $file = RecodeFile::find()->where(['token' => $token])->one();//得到文件记录
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
        // 外部接口地址
        $targetUrl = 'https://a1.voxel.cn/v1/server/checkin?expand=verse_id,name';

        // 获取请求参数

        $params = Yii::$app->request->get();


        // 构建带参数的 URL（目标 URL 已包含查询参数，使用 & 追加）
        if (!empty($params)) {
            $targetUrl .= '&' . http_build_query($params);
        }

        // 使用 cURL 转发请求
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $targetUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // 转发请求头（可选）
        $headers = [];
        // $headers[] = 'Authorization: Bearer YOUR_TOKEN';
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \yii\web\HttpException(500, '转发请求失败: ' . $error);
        }

        // 设置响应状态码
        Yii::$app->response->statusCode = $httpCode;

        // 尝试解析 JSON 响应
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // 如果不是 JSON，直接返回原始响应
        Yii::$app->response->format = \yii\web\Response::FORMAT_RAW;
        return $response;
    }
}
