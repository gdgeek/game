<?php
namespace app\modules\v2\helper;


use Yii;
use app\modules\v2\models\Applet;
use app\modules\v2\models\Report;
use app\modules\v2\models\RecodeFile;
use app\modules\v2\models\File;
use app\modules\v2\models\Device;
class Server
{
    public static function GetDevice(string $token, string|null $uuid)
    {
        $report = Report::find()->where(['token' => $token])->one();//得到报告（ar端上传）
        if (!$uuid) {
            return $report;
        }
        if (!$report) {
            $report = new Report();
            $report->token = $token;
            $report->uuid = $uuid;
            $report->created_at = strval(time());
            $report->setup = json_encode([
                'money' => 0,
                [
                    'pictures' =>
                        [
                            'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t1.png',
                            'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t2.png',
                            'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t3.png',
                            'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t4.png',
                        ],
                    'shot' =>
                        [
                            1,
                            5,
                            10,
                            20,
                        ]
                ]
            ]); // 示例数据

           /* $device = Device::findOne(['uuid' => $uuid]);
            if ($device) {
                $report->device_id = $device->id;
            } else {
                //创建一个新的 device
                $device = new Device();
                $device->uuid = $uuid;
                $device->save();
                $report->device_id = $device->id;
            }*/
        }
        if (!$report->device_id) {
            $device = Device::findOne(['uuid' => $report->uuid]);
            if ($device) {
                $report->device_id = $device->id;
            } else {
                //创建一个新的 device
                $device = new Device();
                //得到客户端ip
                $device->ip = Yii::$app->request->userIP;
                $device->uuid = $uuid;
                $device->save();
                $report->device_id = $device->id;
            }
        }
        $status = Yii::$app->request->post("status");
        if ($status) {
            $report->status = $status;
        }

        $data = Yii::$app->request->post("data");
        if ($data) {
            $report->data = $data;
        }
        $report->updated_at = strval(time());
        $report->save();

        return $report;
    }

    private static function GetApplet(string $token, string|null $id): ?Applet
    {
        $applet = Applet::find()->where(['token' => $token])->one();//得到签到（小程序端上传）

        if (!$id) {
            return $applet;
        }
        if (!$applet) {

            $user = Yii::$app->user->identity;
            $applet = new Applet();
            $applet->token = $token;
            $applet->created_at = strval(time());
            $applet->id = $id;
            $applet->user_id = $user->id;
        }

        $status = Yii::$app->request->post("status");
        if ($status) {
            $applet->status = $status;
        }
        $data = Yii::$app->request->post("data");
        if ($data) {
            $applet->data = $data;
        }

        $applet->updated_at = strval(time());
        $applet->save();
        return $applet;
    }
    private static function GetFile(string $token, string|null $key, Applet|null $applet = null)
    {
        $rf = RecodeFile::find()->where(['token' => $token])->one();//得到文件记录
        if (!$key) {
            return $rf;
        }
        if (!$rf && $applet) {
            $rf = new RecodeFile();
            $rf->token = $token;
            // $rf->created_at = strval(time());
            $rf->key = $key;

            $file = File::Create($key, $applet->id);
            $file->user_id = $applet->user_id;
            $file->save();
            //'Y-m-d H:i:s'转时间戳 类似 strval(time()) 这种结果

            $rf->created_at = $file->created_at;
            $rf->updated_at = strval(time());
            $rf->file_id = $file->id;

        }




        $rf->save();

        return $rf;
    }
    private static function GetSetup(string|null $device)
    {
        return self::DefaultSetup();
    }
    private static function DefaultSetup(): array
    {
        $setup = [

            'money' => 0,
            'slogans' => [
                '我在这里很想你',
                '今天也要加油鸭',
                '阳光正好，微风不燥',
                '记录每一刻，热爱每一天'
            ],
            'pictures' => [
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t1.webp',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t2.webp',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t3.webp',
                'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t4.webp',
            ],

            'shot' => [1, 5, 10, 20],
        ];
        return $setup;
    }
    public static function Refresh(): array
    {

        $helper = Yii::$app->helper;
        $helper->record();
        $token = Yii::$app->request->post("token");

        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }

        // 开发模式直接跳过 time/hash 校验
        if (YII_ENV_DEV) {
            $uuid = Yii::$app->request->post("uuid");
            $id = Yii::$app->request->post("id");
            $key = Yii::$app->request->post("key");
            $params = array_filter([$uuid, $id, $key]);

            if (count($params) !== 1) {
                throw new \yii\web\HttpException(400, 'Exactly one of uuid, id, or key must be provided');
            }
        } else {
            $time = Yii::$app->request->get("time");
            $hash = Yii::$app->request->get("hash");

            if (!$time || !$hash) {
                throw new \yii\web\HttpException(400, 'time and hash are required');
            }

            $pattern = '/^[A-Z][0-9a-f]{32}$/i';
            if (!preg_match($pattern, $token)) {
                throw new \yii\web\HttpException(400, 'token format error');
            }

            $uuid = Yii::$app->request->post("uuid");
            $id = Yii::$app->request->post("id");
            $key = Yii::$app->request->post("key");
            $params = array_filter([$uuid, $id, $key]);
            if (count($params) !== 1) {
                throw new \yii\web\HttpException(400, 'Exactly one of uuid, id, or key must be provided');
            }

            $param = array_values($params)[0];
            $salt = "buj1aban.c0m";
            if (md5($token . $time . $param . $salt) !== $hash) {
                throw new \yii\web\HttpException(400, 'hash error');
            }
        }



        $report = Server::GetDevice($token, $uuid);
        $applet = Server::GetApplet($token, $id);

        $file = Server::GetFile($token, $key, $applet);

        $result['data'] = [];
        //检查 url 里面是否有 expand,如果有的话拆分成数组
        $expand = Yii::$app->request->get("expand");
        if ($expand) {
            $expands = explode(",", $expand);
            if (in_array("token", $expands)) {
                $result['data']['token'] = $token;
            }
            if (in_array("setup", $expands)) {

                if (isset($report["uuid"])) {
                    $result['data']['setup'] = self::GetSetup($report["uuid"]);
                } else {
                    $result['data']['setup'] = self::DefaultSetup();
                }

            }

            if (in_array("file", $expands)) {
                unset($file['token']);
                //    unset($file['created_at']);
                unset($file['updated_at']);
                $result['data']['file'] = $file;
            }
            if (in_array("applet", $expands)) {
                $result['data']['applet'] = $applet;
            }
            if (in_array("device", $expands)) {

                unset($report['setup']);
                unset($report['token']);
                unset($report['created_at']);
                unset($report['updated_at']);
                $result['data']['device'] = $applet;
            }

            //在 result 中增加 'success' => true
            $result['success'] = true;
            $result['message'] = 'success';

            return $result;
        }
        ;

        $result['success'] = false;
        $result['message'] = 'need expand';

        return $result;

    }

}