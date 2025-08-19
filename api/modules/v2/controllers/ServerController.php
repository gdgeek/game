<?php
namespace app\modules\v2\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v2\models\Checkin;
use app\modules\v2\models\Report;
use app\modules\v2\models\RecodeFile;
use app\modules\v2\models\File;

class ServerController extends Controller
{

    public function behaviors()
    {

        $behaviors = parent::behaviors();

        return $behaviors;
    }


    private function getReport(string $token, string|null $device)
    {
        $report = Report::find()->where(['token' => $token])->one();//得到报告（ar端上传）
        if (!$device) {
            return $report;
        }
        if (!$report) {
            $report = new Report();
            $report->token = $token;
            $report->device = $device;
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
    private function getCheckin(string $token, string|null $openid): ?Checkin
    {
        $checkin = Checkin::find()->where(['token' => $token])->one();//得到签到（小程序端上传）

        if (!$openid) {
            return $checkin;
        }
        if (!$checkin) {
            $checkin = new Checkin();
            $checkin->token = $token;
            $checkin->created_at = strval(time());
            $checkin->openid = $openid;
        }

        $status = Yii::$app->request->post("status");
        if ($status) {
            $checkin->status = $status;
        }
        $data = Yii::$app->request->post("data");
        if ($data) {
            $checkin->data = $data;
        }

        $checkin->updated_at = strval(time());
        $checkin->save();
        return $checkin;
    }
    private function getFile(string $token, string|null $key, Checkin|null $checkin = null)
    {
        $file = RecodeFile::find()->where(['token' => $token])->one();//得到文件记录
        if (!$key) {
            return $file;
        }
        if (!$file) {
            $file = new RecodeFile();
            $file->token = $token;
            $file->created_at = strval(time());
            $file->key = $key;
        }


        // $mysql = File::Create($key);
        // $mysql->save();

        $file->updated_at = strval(time());
        // $file->dbid = $mysql->id;
        $file->save();
        if ($checkin) {

        }

        return $file;
    }
    public function actionTest()
    {

        $helper = Yii::$app->helper;
        return $helper->play();

    }

    public function actionRefresh()
    {

        $helper = Yii::$app->helper;
        $helper->record();
        $token = Yii::$app->request->post("token");

        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }

        // 开发模式直接跳过 time/hash 校验
        if (YII_ENV_DEV) {
            $device = Yii::$app->request->post("device");
            $openid = Yii::$app->request->post("openid");
            $key = Yii::$app->request->post("key");

            $params = array_filter([$device, $openid, $key]);
            if (count($params) !== 1) {
                throw new \yii\web\HttpException(400, 'Exactly one of device, openid, or key must be provided');
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

            $device = Yii::$app->request->post("device");
            $openid = Yii::$app->request->post("openid");
            $key = Yii::$app->request->post("key");
            $params = array_filter([$device, $openid, $key]);
            if (count($params) !== 1) {
                throw new \yii\web\HttpException(400, 'Exactly one of device, openid, or key must be provided');
            }

            $param = array_values($params)[0];
            $salt = "buj1aban.c0m";
            if (md5($token . $time . $param . $salt) !== $hash) {
                throw new \yii\web\HttpException(400, 'hash error');
            }
        }

      

        $report = $this->getReport($token, $device);
        $checkin = $this->getCheckin($token, $openid);
        $file = $this->getFile($token, $key, $checkin);

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

    public function actionLog($token)
    {

        $report = Report::find()->where(['token' => $token])->one();//得到报告（ar端上传）
        $checkin = Checkin::find()->where(['token' => $token])->one();//得到签到（小程序端上传）
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

}