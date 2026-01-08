<?php

namespace app\modules\v1\controllers;

use Yii;
use yii\rest\Controller;
use app\modules\v1\models\Checkin;
use app\modules\v1\models\Report;
use app\modules\v1\models\RecodeFile;

class CheckinController extends Controller
{
    public function behaviors()
    {

        $behaviors = parent::behaviors();

        return $behaviors;
    }

    public function actionUpload()
    {
        $token = Yii::$app->request->post("token");
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }
        $key = Yii::$app->request->post("key");
        if (!$key) {
            throw new \yii\web\HttpException(400, 'key is required');
        }
       // $device = Yii::$app->request->post("device");

        $checkin = Checkin::find()->where(['token' => $token])->one();



        if (!$checkin) {
            throw new \yii\web\HttpException(400, 'checkin not found');
        }
        $file = RecodeFile::find()->where(['token' => $token])->one();
        if (!$file) {
            $file = new RecodeFile();
            $file->token = $token;
        }

        $file->key = $key;
      //  $file->openid = $checkin->openid;
        $file->created_at = strval(time());
        $file->save();
        return [
            'success' => true,
            'message' => 'file upload success',
            'data' => $file
        ];
    }

    public function actionFiles()
    {
        $openid = Yii::$app->request->get("openid");
        if (!$openid) {
            throw new \yii\web\HttpException(400, 'openid is required');
        }
        $files = RecodeFile::find()->where(['openid' => $openid])->all();
        if (!$files) {
            return [
                'success' => false,
                'message' => 'no files found',
                'data' => []
            ];
        }
        return [
            'success' => true,
            'message' => 'files found',
            'data' => $files
        ];
    }
    public function actionReport()
    {
        $token = Yii::$app->request->post("token");
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }
        $action = Yii::$app->request->post("action");
        if (!$action) {
            throw new \yii\web\HttpException(400, 'action is required');
        }
        $device = Yii::$app->request->post("device");
        if (!$device) {
            throw new \yii\web\HttpException(400, 'device is required');
        }


        $report = Report::find()->where(['token' => $token])->one();
        if (!$report) {
            $report = new Report();
            $report->token = $token;
            $report->device = $device;
        }
        $report->action = $action;
        $report->save();
        return [
            'success' => true,
            'message' => 'success',
            'data' => $report
        ];
    }

    public function actionStatus()
    {
        $token = Yii::$app->request->get("token");

        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }


        $checkin = Checkin::find()->where(['token' => $token])->one();
        $file = RecodeFile::find()->where(['token' => $token])->one();
        $report = Report::find()->where(['token' => $token])->one();

        if ($checkin) {
            return [
                'success' => true,
                'message' => 'already checkin',
                'data' => [
                    'checkin' => $checkin,
                    'file' => $file,
                    'report' => $report
                ]
            ];
        } else {
            return [
                'success' => false,
                'message' => 'not checkin'
            ];
        }
    }

    public function actionStatusOver()
    {
        $token = Yii::$app->request->post("token");
        $openid = Yii::$app->request->post("openid");
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }
        if (!$openid) {
            throw new \yii\web\HttpException(400, 'openid is required');
        }
        $checkin = Checkin::find()->where(['token' => $token, 'openid' => $openid])->one();

        if ($checkin) {
            $checkin->delete();
            return [
                'success' => true,
                'message' => 'success'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'not checkin',
            ];
        }
    }

    private function changeState($status)
    {

        $token = Yii::$app->request->post("token");
        $openid = Yii::$app->request->post("openid");
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }
        if (!$openid) {
            throw new \yii\web\HttpException(400, 'openid is required');
        }
        $checkin = Checkin::find()->where(['token' => $token, 'openid' => $openid])->one();

        if (!$checkin) {
            $checkin = new Checkin();
            $checkin->created_at = strval(time());
            $checkin->token = $token;
            $checkin->openid = $openid;
        }
        if ($checkin->status != $status) {
            $checkin->status = $status;
            $checkin->updated_at = strval(time());
            $checkin->save();
        }


        return [
            'success' => true,
            'message' => 'success',
            'data' => [
                'checkin' => $checkin,
                'file' => RecodeFile::find()->where(['token' => $token, 'openid' => $openid])->one()
            ]
        ];
    }

    public function actionStatusReady()
    {
        return $this->changeState("ready");
    }
    public function actionStatusLinked()
    {
        return $this->changeState("linked");
    }




    public function actionClose()
    {
        $openid = Yii::$app->request->get("openid");
        if (!$openid) {
            throw new \yii\web\HttpException(400, 'openid is required');
        }
        $checkin = Checkin::find()->where(['openid' => $openid])->one();
        if ($checkin) {
            $checkin->delete();
            return [
                'success' => true,
                'message' => 'success'
            ];
        } else {
            return [
                'success' => true,
                'message' => 'not checkin',
            ];
        }
    }
    public function actionReady()
    {
        $token = Yii::$app->request->post("token");
        if (!$token) {
            throw new \yii\web\HttpException(400, 'token is required');
        }
        $openid = Yii::$app->request->post("openid");
        if (!$openid) {
            throw new \yii\web\HttpException(400, 'openid is required');
        }

        // Checkin::find()->where(['token' => $token])->andWhere(['openid' => $openid])->one();
        $checkin = Checkin::find()->where(['token' => $token])->one();


        if ($checkin && $checkin->openid == $openid) {
            return [
                'success' => true,
                'message' => 'already checkin',
                'data' => $checkin
            ];
        }
        if (!$checkin) {
            $checkin = new Checkin();
        }
        $checkin->token = $token;
        $checkin->openid = $openid;
        $checkin->status = "ready";
        $checkin->file = '';
        if ($checkin->save()) {
            return [
                'success' => true,
                'message' => 'success',
                'data' => $checkin
            ];
        } else {
            throw new \yii\web\HttpException(400, 'save failed' + json_encode($checkin->errors));
        }
    }
}
