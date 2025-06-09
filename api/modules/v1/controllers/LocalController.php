<?php
namespace app\modules\v1\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Checkin;
use app\modules\v1\models\Report;
use app\modules\v1\models\RecodeFile;

class LocalController extends Controller
{

    public function behaviors()
    {

        $behaviors = parent::behaviors();

        return $behaviors;
    }

    

    public function actionRefresh()
    {
        $token = Yii::$app->request->post("token");
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }
        $time = Yii::$app->request->get("time");
        $hash = Yii::$app->request->get("hash");
        if (!$time || !$hash) {
            throw new \yii\web\HttpException(400, 'time and hash are required');
        }

        $pattern = '/^[A-Z][0-9a-f]{32}$/i';

        if (!preg_match($pattern, $token)) {
            // 如果不匹配，抛出异常或返回错误信息
            throw new \yii\web\HttpException(400, 'token format error');
        }

        // 简单明了的方法：只获取一个参数
        $device = Yii::$app->request->post("device");
        $openid = Yii::$app->request->post("openid");
        $key = Yii::$app->request->post("key");
        // 用数组过滤空值，检查是否只有一个参数
        $params = array_filter([$device, $openid, $key]);
        if (count($params) !== 1) {
            throw new \yii\web\HttpException(400, 'Exactly one of device, openid, or key must be provided');
        }

        $param = array_values($params)[0];
        $salt = "buj1aban.c0m";
        if (md5($token . $time . $param . $salt) != $hash) {
            throw new \yii\web\HttpException(400, 'hash error');
        }

        $report = Report::find()->where(['token' => $token])->one();
        $checkin = Checkin::find()->where(['token' => $token])->one();
        $file = RecodeFile::find()->where(['token' => $token])->one();


        

        if ($device) {
            if (!$report) {
                $report = new Report();
                $report->token = $token;
                $report->device = $device;
                $report->created_at = strval(time());
            }
            $status = Yii::$app->request->post("status");
            if ($status) {
                $report->status = $status;
                $report->data = Yii::$app->request->post("data");
            }
            $report->updated_at = strval(time());
            $report->save();
        } elseif ($openid) {
            if (!$checkin) {
                $checkin = new Checkin();
                $checkin->token = $token;
                $checkin->created_at = strval(time());
                $checkin->openid = $openid;
            }
            $status = Yii::$app->request->post("status");
            if ($status) {
                $checkin->status = $status;
                $checkin->data = Yii::$app->request->post("data");
            }
            $checkin->updated_at = strval(time());
            $checkin->save();
        } elseif ($key) {
            if (!$file) {
                $file = new RecodeFile();
                $file->token = $token;
                $file->created_at = strval(time());
                $file->key = $key;
            }
            $file->updated_at = strval(time());
            $file->save();
        }

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