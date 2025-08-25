<?php
namespace app\modules\v2\controllers;
use Yii;
use yii\rest\Controller;
use app\modules\v2\models\Applet;
use app\modules\v2\models\Report;
use app\modules\v2\models\RecodeFile;
use app\modules\v2\models\File;
use app\modules\v2\helper\Server;
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

    
    public function actionFile()
    {
        $key = Yii::$app->request->post("key");
        if (!isset($key)) {
            throw new \yii\web\HttpException(400, 'key is required');
        }
        return Server::Refresh();
    }
    public function actionApplet()
    {
        $id = Yii::$app->request->post("id");
        if (!isset($id)) {
            throw new \yii\web\HttpException(400, 'id is required');
        }
        return Server::Refresh();
    }

    public function actionDevice()
    {
        $device = Yii::$app->request->post("uuid");
        if (!isset($device)) {
            throw new \yii\web\HttpException(400, 'uuid is required');
        }
        return Server::Refresh();
    }
    public function actionRefresh()
    {

        return Server::Refresh();

    }

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

}