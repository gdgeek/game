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


    private function getReport(string $token, string|null $uuid)
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
    private function getCheckin(string $token, string|null $id): ?Checkin
    {
        $checkin = Checkin::find()->where(['token' => $token])->one();//得到签到（小程序端上传）

        if (!$id) {
            return $checkin;
        }
        if (!$checkin) {
            $checkin = new Checkin();
            $checkin->token = $token;
            $checkin->created_at = strval(time());
            $checkin->id = $id;
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
        $rf = RecodeFile::find()->where(['token' => $token])->one();//得到文件记录
        if (!$key) {
            return $rf;
        }
        if (!$rf) {
            $rf = new RecodeFile();
            $rf->token = $token;
            $rf->created_at = strval(time());
            $rf->key = $key;
        }


        // $file = File::Create($key);
        // $file->save();

        $rf->updated_at = strval(time());
        // $rf->file_id = $file->id;
        $rf->save();
     
        return $rf;
    }
    public function actionTest()
    {

        $helper = Yii::$app->helper;
        return $helper->play();

    }

    private function defaultSetup(): array
    {
        return [
            'money' => 0,
            [
                'pictures' => [
                    'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t1.png',
                    'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t2.png',
                    'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t3.png',
                    'https://game-1251022382.cos.ap-nanjing.myqcloud.com/picture/t4.png',
                ],
                'shot' => [1, 5, 10, 20],
            ],
        ];
    }
    private function doRefresh(): array
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



        $report = $this->getReport($token, $uuid);
        $checkin = $this->getCheckin($token, $id);
        $file = $this->getFile($token, $key, $checkin);

        $result['data'] = [];
        //检查 url 里面是否有 expand,如果有的话拆分成数组
        $expand = Yii::$app->request->get("expand");
        if ($expand) {
            $expands = explode(",", $expand);
            if (in_array("token", $expands)) {
                $result['data']['token'] = $token;
            }
            //  $result['data'] = [];
            //检查 expands 里面是否有 setup 如果有，则增加 setup 字段
            if (in_array("setup", $expands)) {

                if(isset($report["uuid"])){
                     $result['data']['setup'] = $this->getSetup($report["uuid"]);
                }else{
                    $result['data']['setup'] = $this->defaultSetup();                }
               
            }

            if (in_array("file", $expands)) {
                unset($file['token']);
                //    unset($file['created_at']);
                unset($file['updated_at']);
                $result['data']['file'] = $file;
            }
            if (in_array("applet", $expands)) {
                $result['data']['applet'] = $checkin;
            }
            if (in_array("device", $expands)) {

                unset($report['setup']);
                unset($report['token']);
                unset($report['created_at']);
                unset($report['updated_at']);
                $result['data']['device'] = $report;
            }
         
            //在 result 中增加 'success' => true
            $result['success'] = true;
            $result['message'] = 'success';

            return $result;
        } else {


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

    private function getSetup(string|null $device)
    {
        return $this->defaultSetup();
    }
    public function actionFile()
    {
        $key = Yii::$app->request->post("key");
        if (!isset($key)) {
            throw new \yii\web\HttpException(400, 'key is required');
        }
        return $this->doRefresh();
    }
    public function actionApplet()
    {
        $id = Yii::$app->request->post("id");
        if (!isset($id)) {
            throw new \yii\web\HttpException(400, 'id is required');
        }
        return $this->doRefresh();
    }

    public function actionDevice()
    {
        $device = Yii::$app->request->post("uuid");
        if (!isset($device)) {
            throw new \yii\web\HttpException(400, 'uuid is required');
        }
        return $this->doRefresh();
    }
    public function actionRefresh()
    {

        return $this->doRefresh();

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