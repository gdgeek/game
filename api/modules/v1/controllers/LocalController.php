<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Checkin;
use app\modules\v1\models\Report;
use app\modules\v1\models\RecodeFile;
use app\modules\v1\models\File;

class LocalController extends Controller
{

    public function behaviors()
    {

        $behaviors = parent::behaviors();

        return $behaviors;
    }


    private function getReport(string $token, $device)
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
    private function getCheckin(string $token,string $openid): ?Checkin
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
    private function getFile(string $token, string $key, Checkin $checkin = null)
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


        $mysql = File::Create($checkin->id, $key);
        $mysql->save();

        $file->updated_at = strval(time());
        $file->dbid = $mysql->id;
        $file->save();
       

        return $file;
    }
    public function actionTest()
    {

        $helper = Yii::$app->helper;
        return $helper->play();

    }
    public function actionRefresh()
    {

        //做个cache日志 
        $helper = Yii::$app->helper;
        $helper->record();
        $token = Yii::$app->request->post("token");//这次 的token

        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }

        $time = Yii::$app->request->get("time");//得到更新时间
        $hash = Yii::$app->request->get("hash");//得到hash


        if (!$time || !$hash) {
            throw new \yii\web\HttpException(400, 'time and hash are required');
        }

        $pattern = '/^[A-Z][0-9a-f]{32}$/i';

        if (!preg_match($pattern, $token)) {//测试hash
            // 如果不匹配，抛出异常或返回错误信息
            throw new \yii\web\HttpException(400, 'token format error');
        }

        // 简单明了的方法：只获取一个参数
        $device = Yii::$app->request->post("device");// 设备标识 这个是给 ar设备用
        $openid = Yii::$app->request->post("openid");// 微信用户标识 这个是给微信小程序用
        $key = Yii::$app->request->post("key");// 密钥标识 这个是给文件用
        // 用数组过滤空值，检查是否只有一个参数
        $params = array_filter([$device, $openid, $key]);

        if (count($params) !== 1) {
            throw new \yii\web\HttpException(400, 'Exactly one of device, openid, or key must be provided');
        }


        $param = array_values($params)[0];// 获取唯一的参数值
        $salt = "buj1aban.c0m";

        if (md5($token . $time . $param . $salt) != $hash) {//验证是否通过
            throw new \yii\web\HttpException(400, 'hash error');
        }

        $report = $this->getReport($token, $device);//得到报告（ar端上传）
        $checkin = $this->getCheckin($token, $openid);//得到签到（小程序端上传）
        $file = $this->getFile($token, $key, $checkin);//得到文件记录

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