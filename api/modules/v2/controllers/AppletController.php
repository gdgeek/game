<?php
namespace app\modules\v2\controllers;
use Yii;
use yii\rest\Controller;

use bizley\jwt\JwtHttpBearerAuth;
use yii\filters\auth\CompositeAuth;
use app\modules\v2\helper\Server;
class AppletController extends Controller
{

    public function behaviors()
    {

        $behaviors = parent::behaviors();
        $behaviors['authenticator'] = [
            'class' => CompositeAuth::class,
            'authMethods' => [
                JwtHttpBearerAuth::class,
            ],
            'except' => ['options'],
        ];

        return $behaviors;
    }


    public function actionRefresh()
    {
        return Server::Refresh();
    }



}