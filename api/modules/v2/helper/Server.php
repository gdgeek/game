<?php

namespace app\modules\v2\helper;

use Yii;
use app\modules\v2\models\Applet;
use app\modules\v2\models\Report;
use app\modules\v2\models\RecodeFile;
use app\modules\v2\models\File;
use app\modules\v2\models\Device;
use app\modules\v2\models\Setup;
use Exception;

class Server
{
    public static function GetDevice(string $token, string|null $uuid)
    {
        $report = Report::find()->where(['token' => $token])->one(); //得到报告（ar端上传）
        if (!$uuid) {
            return $report;
        }
        if (!$report) {
            $report = new Report();
            $report->token = $token;
            $report->uuid = $uuid;
            $report->created_at = strval(time());
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
        $applet = Applet::find()->where(['token' => $token])->one(); //得到签到（小程序端上传）

        if (!$id) {
            return $applet;
        }
        if (!$applet) {
            $user = Yii::$app->user->identity;
            if (!$user) {
                throw new \yii\web\HttpException(401, 'User not authenticated');
            }
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
        $rf = RecodeFile::find()->where(['token' => $token])->one(); //得到文件记录
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

        if ($rf) {
            $rf->save();
        }

        return $rf;
    }
    private static function getSetup(string|null $uuid)
    {
        $device = Device::findOne(['uuid' => $uuid]);
        if ($device && $device->setup) {
            $setup = $device->setup;
            return $setup->getData();
        }
        return Setup::DefaultData();
    }


    /**
     * 根据设备UUID获取设备信息配置
     * 
     * 此方法用于获取指定设备的信息配置。如果设备不存在，会自动创建新设备并初始化默认配置。
     * 
     * @param string|null $uuid 设备的唯一标识符UUID，可以为null
     * @return array 返回设备的信息配置数组，如果获取失败则返回默认信息配置
     * 
     * 处理流程：
     * 1. 如果UUID不为空，尝试查找对应的设备
     * 2. 如果设备不存在，创建新设备并设置UUID
     * 3. 为新设备创建默认的Setup配置（包含数据和信息）
     * 4. 保存设备到数据库
     * 5. 获取设备关联的setup配置
     * 6. 如果setup存在，返回其信息配置
     * 7. 如果UUID为空或获取失败，返回默认信息配置
     */
    public static function getInfo(string|null $uuid)
    {
        // 检查UUID是否提供
        if ($uuid) {

            // 根据UUID查找现有设备
            $device = Device::findOne(['uuid' => $uuid]);

            // 如果设备不存在，创建新设备
            if (!$device) {

                $device = new Device();
                $device->uuid = $uuid;

                // 为新设备创建默认的Setup配置
                // Setup::Create() 会创建包含默认数据和信息的配置
                $device->save();
                $setup = Setup::Create($device, Setup::DefaultData(), Setup::DefaultInfo());

                // 保存设备到数据库

            }

            // 获取设备关联的setup配置
            $setup = $device->setup;
            if ($setup) {
                // 返回setup的信息配置
                return $setup->getInfo();
            }
        }
        //return $uuid;
        // 如果UUID为空或获取失败，返回默认信息配置
        return Setup::DefaultInfo();
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
                if ($report && $report->uuid) {
                    $result['data']['setup'] = self::getSetup($report->uuid);
                } else {
                    $result['data']['setup'] = Setup::DefaultData();
                }
            }
            if (in_array("info", $expands)) {
                if ($report && $report->uuid) {
                    $result['data']['info'] = self::getInfo($report->uuid);
                } else {
                    $result['data']['info'] = Setup::DefaultInfo();
                }
            }

            if (in_array("file", $expands)) {
                // 将 RecodeFile 对象转换为数组
                $fileData = $file ? $file->toArray() : null;
                // 移除不需要的字段
                unset($fileData['token']);
                //    unset($fileData['created_at']);
                unset($fileData['updated_at']);
                $result['data']['file'] = $fileData;
            }
            if (in_array("applet", $expands)) {
                // 将 Applet 对象转换为数组
                $appletData = $applet ? $applet->toArray() : [];
                $result['data']['applet'] = $appletData;
            }
            if (in_array("device", $expands)) {
                // 将 Report 对象转换为数组
                $deviceData = $report ? $report->toArray() : [];
                // 移除不需要的字段
                unset($deviceData['setup']);
                unset($deviceData['token']);
                unset($deviceData['created_at']);
                unset($deviceData['updated_at']);
                $result['data']['device'] = $deviceData;
            }

            //在 result 中增加 'success' => true
            $result['success'] = true;
            $result['message'] = 'success';

            return $result;
        };

        $result['success'] = false;
        $result['message'] = 'need expand';

        return $result;
    }
}
